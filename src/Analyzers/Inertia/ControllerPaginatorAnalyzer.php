<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Analyzers\Inertia;

use AbeTwoThree\LaravelTsPublish\Ast\CallChainWalker;
use AbeTwoThree\LaravelTsPublish\Ast\InertiaRenderLocator;
use AbeTwoThree\LaravelTsPublish\Ast\MethodLocator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * Analyzes a controller method body to infer paginator-model relationships.
 */
class ControllerPaginatorAnalyzer
{
    /**
     * @var list<string>
     */
    private const PAGINATOR_METHODS = ['paginate', 'simplePaginate', 'cursorPaginate'];

    /**
     * @param  class-string  $controllerClass
     */
    public function __construct(
        protected string $controllerClass,
        protected string $methodName,
    ) {}

    /**
     * @var array{method: ClassMethod, finder: NodeFinder, varModelMap: array<string, class-string>}|null
     */
    private ?array $resolvedMethodContext = null;

    private bool $methodContextBuilt = false;

    /**
     * Analyze the controller method body to find paginator-model relationships.
     *
     * `Resource::collection()` props map to the resource FQCN, not the model: the emitted type is
     * `AnonymousResourceCollection<ResourceName>`.
     *
     * @return array<string, class-string> prop key => model or resource FQCN
     */
    public function analyze(): array
    {
        $ctx = $this->getMethodContext();

        if ($ctx === null) {
            return [];
        }

        ['method' => $method, 'finder' => $finder, 'varModelMap' => $varModelMap] = $ctx;

        $propVarMap = $this->resolvePropVariables($method, $finder);

        /** @var array<string, class-string> $result */
        $result = [];

        foreach ($propVarMap as $propKey => $varName) {
            if (isset($varModelMap[$varName])) {
                $result[$propKey] = $varModelMap[$varName];
            }
        }

        $collectionProps = $this->resolveStaticCollectionProps($method, $finder, $varModelMap)['nonPaginated'];

        foreach ($collectionProps as $propKey => $resourceFqcn) {
            $result[$propKey] = $resourceFqcn;
        }

        return $result;
    }

    /**
     * Find props of the form `'key' => new SomeResource($paginatedVar)` or `new SomeResource(Model::paginate())`.
     *
     * @return array<string, class-string<object>> prop key => resource FQCN
     */
    public function analyzePaginatedResourceProps(): array
    {
        $ctx = $this->getMethodContext();

        if ($ctx === null) {
            return [];
        }

        ['method' => $method, 'finder' => $finder, 'varModelMap' => $varModelMap] = $ctx;

        return $this->resolvePaginatedResourceConstructorProps($method, $finder, $varModelMap);
    }

    /**
     * Find props of the form `'key' => SomeResource::collection($paginatedVar)` or `::collection(Model::paginate())`.
     *
     * @return array<string, class-string> prop key => resource FQCN
     */
    public function analyzePaginatedStaticCollectionProps(): array
    {
        $ctx = $this->getMethodContext();

        if ($ctx === null) {
            return [];
        }

        ['method' => $method, 'finder' => $finder, 'varModelMap' => $varModelMap] = $ctx;

        return $this->resolveStaticCollectionProps($method, $finder, $varModelMap)['paginated'];
    }

    /**
     * Get (and cache) the method context, returning null when the method cannot be resolved.
     *
     * @return array{method: ClassMethod, finder: NodeFinder, varModelMap: array<string, class-string>}|null
     */
    private function getMethodContext(): ?array
    {
        if (! $this->methodContextBuilt) {
            $this->resolvedMethodContext = $this->buildMethodContext();
            $this->methodContextBuilt = true;
        }

        return $this->resolvedMethodContext;
    }

    /**
     * Build the method context from the controller class and method name.
     *
     * @return array{method: ClassMethod, finder: NodeFinder, varModelMap: array<string, class-string>}|null
     */
    private function buildMethodContext(): ?array
    {
        $context = resolve(MethodLocator::class)->locateOwn($this->controllerClass, $this->methodName);

        if ($context === null) {
            return null;
        }

        $finder = new NodeFinder;
        $varModelMap = $this->resolveVariableModels($context->method, $finder);

        return ['method' => $context->method, 'finder' => $finder, 'varModelMap' => $varModelMap];
    }

    /**
     * Scan the Inertia::render() props array for `'key' => new SomeResource($paginatedVar)` items.
     *
     * @param  array<string, class-string>  $varModelMap  Variable name => model FQCN from paginator analysis.
     * @return array<string, class-string> prop key => resource FQCN
     */
    private function resolvePaginatedResourceConstructorProps(ClassMethod $method, NodeFinder $finder, array $varModelMap): array
    {
        $locator = resolve(InertiaRenderLocator::class);
        $renderCall = $locator->findRenderCall($method);

        if ($renderCall === null) {
            return [];
        }

        $propsArray = $locator->propsArray($renderCall);

        if ($propsArray === null) {
            return [];
        }

        /** @var array<string, class-string> $map */
        $map = [];

        /** @var array<Expr\ArrayItem> $items */
        $items = $propsArray->items;

        foreach ($items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            if (! $item->value instanceof New_) {
                continue;
            }

            $newNode = $item->value;

            if (! $newNode->class instanceof Name) {
                continue;
            }

            if (count($newNode->args) !== 1) {
                continue;
            }

            $arg = $newNode->args[0];

            if (! $arg instanceof Node\Arg) {
                continue;
            }

            $isPaginated = ($arg->value instanceof Variable
                    && is_string($arg->value->name)
                    && isset($varModelMap[$arg->value->name]))
                || $this->resolveInlinePaginatorModel($arg->value) !== null;

            if (! $isPaginated) {
                continue;
            }

            $resourceFqcn = $newNode->class->toString();

            if (! class_exists($resourceFqcn) || ! is_a($resourceFqcn, JsonResource::class, true)) {
                continue;
            }

            /** @var class-string<JsonResource> $resourceFqcn */
            $map[$item->key->value] = $resourceFqcn;
        }

        return $map;
    }

    /**
     * Find variables assigned from a paginator call chain rooted at an Eloquent Model static call.
     *
     * Only direct chains resolve; indirection (`$q = Post::query(); $q->paginate()`) falls back to `<unknown>`.
     * A paginator called inline as an argument has no assignment at all — resolveInlinePaginatorModel() handles it.
     *
     * @return array<string, class-string> variable name => model FQCN
     */
    private function resolveVariableModels(ClassMethod $method, NodeFinder $finder): array
    {
        /** @var array<string, class-string> $varModelMap */
        $varModelMap = [];

        if ($method->stmts === null) {
            return $varModelMap; // @codeCoverageIgnore
        }

        /** @var list<Node> $found */
        $found = $finder->find($method->stmts, fn (Node $n) => $n instanceof Assign);

        foreach ($found as $assign) {
            if (! $assign instanceof Assign) {
                continue; // @codeCoverageIgnore
            }

            if (! $assign->var instanceof Variable || ! is_string($assign->var->name)) {
                continue;  // @codeCoverageIgnore
            }

            $varName = $assign->var->name;
            $rhs = $assign->expr;

            if (! $rhs instanceof MethodCall || ! $rhs->name instanceof Identifier) {
                continue;
            }

            if (! in_array($rhs->name->toString(), self::PAGINATOR_METHODS, true)) {
                continue;
            }

            $modelFqcn = $this->resolveModelFromChain($rhs->var);

            if ($modelFqcn !== null) {
                $varModelMap[$varName] = $modelFqcn;
            }
        }

        return $varModelMap;
    }

    /**
     * Resolve the model behind a paginator call written inline as an argument, with no intermediate
     * variable — `new C(Team::query()->paginate(10))` rather than `$teams = …; new C($teams)`.
     *
     * @return class-string<Model>|null
     */
    private function resolveInlinePaginatorModel(Expr $expr): ?string
    {
        if (! $expr instanceof MethodCall || ! $expr->name instanceof Identifier) {
            return null;
        }

        if (! in_array($expr->name->toString(), self::PAGINATOR_METHODS, true)) {
            return null;
        }

        return $this->resolveModelFromChain($expr->var);
    }

    /**
     * Walk a method call chain back to its root StaticCall and return its Eloquent Model FQCN.
     *
     * Does not record a cache dependency: the controller file is already recorded via AstParser,
     * and recording the model class here would be an unmeasured second behavior change.
     *
     * @return class-string<Model>|null
     */
    private function resolveModelFromChain(Expr $node): ?string
    {
        return resolve(CallChainWalker::class)->resolveRootClass($node, Model::class, recordDependency: false);
    }

    /**
     * Extract a prop key => variable name map from the Inertia::render() props argument.
     *
     * @return array<string, string> prop key => variable name
     */
    private function resolvePropVariables(ClassMethod $method, NodeFinder $finder): array
    {
        $locator = resolve(InertiaRenderLocator::class);
        $renderCall = $locator->findRenderCall($method);

        if ($renderCall === null) {
            return [];
        }

        $propsExpr = $locator->propsArg($renderCall);

        if ($propsExpr === null) {
            return [];
        }

        return $this->extractPropVarMap($propsExpr);
    }

    /**
     * Extract a prop key => variable name map from an array literal or `compact()` call.
     *
     * @return array<string, string>
     */
    private function extractPropVarMap(Expr $propsExpr): array
    {
        /** @var array<string, string> $map */
        $map = [];

        if ($propsExpr instanceof Array_) {
            foreach ($propsExpr->items as $item) {
                if (! $item->key instanceof String_) {
                    continue;
                }

                if (! $item->value instanceof Variable || ! is_string($item->value->name)) {
                    continue;
                }

                $map[$item->key->value] = $item->value->name;
            }
        }

        if (
            $propsExpr instanceof FuncCall
            && $propsExpr->name instanceof Name
            && $propsExpr->name->getLast() === 'compact'
        ) {
            foreach ($propsExpr->args as $arg) {
                if ($arg instanceof Node\Arg && $arg->value instanceof String_) {
                    $varName = $arg->value->value;
                    $map[$varName] = $varName;
                }
            }
        }

        return $map;
    }

    /**
     * Bucket `SomeResource::collection(...)` props by whether their argument is a paginated variable.
     *
     * @param  array<string, class-string>  $varModelMap  Variable name => model FQCN (from paginator analysis).
     * @return array{nonPaginated: array<string, class-string>, paginated: array<string, class-string>}
     */
    private function resolveStaticCollectionProps(ClassMethod $method, NodeFinder $finder, array $varModelMap = []): array
    {
        $locator = resolve(InertiaRenderLocator::class);
        $renderCall = $locator->findRenderCall($method);

        if ($renderCall === null) {
            return ['nonPaginated' => [], 'paginated' => []];
        }

        $propsArray = $locator->propsArray($renderCall);

        if ($propsArray === null) {
            return ['nonPaginated' => [], 'paginated' => []];
        }

        /** @var array<string, class-string> $nonPaginated */
        $nonPaginated = [];

        /** @var array<string, class-string> $paginated */
        $paginated = [];

        /** @var array<Expr\ArrayItem> $items */
        $items = $propsArray->items;

        foreach ($items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            if (! $item->value instanceof StaticCall) {
                continue;
            }

            if (! $item->value->name instanceof Identifier || $item->value->name->toString() !== 'collection') {
                continue;
            }

            if (! $item->value->class instanceof Name) {
                continue;
            }

            $resourceFqcn = $item->value->class->toString();

            if (! class_exists($resourceFqcn)) {
                continue;
            }

            /** @var class-string $resourceFqcn */
            $propKey = $item->key->value;

            $isPaginated = false;

            if ($item->value->args !== [] && $item->value->args[0] instanceof Node\Arg) {
                $firstArg = $item->value->args[0]->value;

                $isPaginated = ($firstArg instanceof Variable
                        && is_string($firstArg->name)
                        && isset($varModelMap[$firstArg->name]))
                    || $this->resolveInlinePaginatorModel($firstArg) !== null;
            }

            if ($isPaginated) {
                $paginated[$propKey] = $resourceFqcn;
            } else {
                $nonPaginated[$propKey] = $resourceFqcn;
            }
        }

        return ['nonPaginated' => $nonPaginated, 'paginated' => $paginated];
    }
}
