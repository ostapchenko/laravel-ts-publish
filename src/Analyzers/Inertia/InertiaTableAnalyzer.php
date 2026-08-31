<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Analyzers\Inertia;

use AbeTwoThree\LaravelTsPublish\Ast\CallChainWalker;
use AbeTwoThree\LaravelTsPublish\Ast\CallMatcher;
use AbeTwoThree\LaravelTsPublish\Ast\InertiaRenderLocator;
use AbeTwoThree\LaravelTsPublish\Ast\MethodLocator;
use AbeTwoThree\LaravelTsPublish\Cache\DependencyRecorder;
use AbeTwoThree\LaravelTsPublish\Concerns\ResolvesClassNames;
use AbeTwoThree\LaravelTsPublish\Support\PackageJson;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionType;

/**
 * Statically detects Inertia UI Table props on a controller action and builds its page-prop type data.
 *
 * Everything here is reflection and AST only: instantiating a table or calling its `toArray()` pulls in
 * PhpSpreadsheet, which fatals during analysis.
 *
 * @phpstan-type TablePageData = array{component: string, pageType: string, classFqcns: list<class-string>, externalImports: array<string, list<string>>}
 */
class InertiaTableAnalyzer
{
    use ResolvesClassNames;

    private const TABLE_BASE = 'InertiaUI\\Table\\Table';

    private const TABLE_TYPE = 'TableResource';

    private const TABLE_PACKAGES = ['@inertiaui/table-vue', '@inertiaui/table-react'];

    /**
     * Resolve the Inertia component name from a controller action's first `Inertia::render()` argument.
     */
    public function resolveComponent(string $uses): ?string
    {
        if (! str_contains($uses, '@')) {
            return null;
        }

        [$controllerClass, $methodName] = explode('@', $uses, 2);

        if (! class_exists($controllerClass)) {
            return null;
        }

        /** @var class-string $controllerClass */
        $context = $this->methodContext($controllerClass, $methodName);

        if ($context === null) {
            return null;
        }

        $locator = resolve(InertiaRenderLocator::class);
        $renderCall = $locator->findRenderCall($context['method']);

        if ($renderCall === null) {
            return null;
        }

        return $locator->componentName($renderCall);
    }

    /**
     * Determine whether analyzing this route would parse a file referencing an `InertiaUI\Table\Table` subclass.
     */
    public function isTainted(string $uses): bool
    {
        if (! str_contains($uses, '@')) {
            return false;
        }

        [$controllerClass, $methodName] = explode('@', $uses, 2);

        if (! class_exists($controllerClass)) {
            return false;
        }

        /** @var class-string $controllerClass */
        $context = $this->methodContext($controllerClass, $methodName);

        if ($context === null) {
            return false;
        }

        ['reflection' => $reflection, 'method' => $method] = $context;

        $controllerFile = $reflection->getFileName();

        if ($controllerFile !== false) {
            $fileStmts = $this->parseAndResolveAst((string) file_get_contents($controllerFile));

            if ($this->containsTableReference($fileStmts)) {
                return true;
            }
        }

        // Ranger parses the whole controller file, including constructor-injected classes, so a table-bearing
        // dependency taints every action — even ones with no Inertia::render() at all.
        if ($this->controllerDependsOnTable($reflection)) {
            return true;
        }

        $locator = resolve(InertiaRenderLocator::class);
        $renderCall = $locator->findRenderCall($method);

        if ($renderCall === null) {
            return false;
        }

        $call = $locator->propsArg($renderCall);

        if (! $call instanceof MethodCall) {
            return false;
        }

        if (! $call->name instanceof Identifier) {
            return false;
        }

        $resourceClass = resolve(CallMatcher::class)->resolveThisPropertyClass($reflection, $call->var);

        if ($resourceClass === null) {
            return false;
        }

        $resourceContext = $this->methodContext($resourceClass, $call->name->toString());

        if ($resourceContext === null) {
            return false;
        }

        /** @var ReflectionClass<object> $resourceReflection */
        $resourceReflection = $resourceContext['reflection'];
        $resourceFile = $resourceReflection->getFileName();

        if ($resourceFile === false) {
            return false;
        }

        $resourceStmts = $this->parseAndResolveAst((string) file_get_contents($resourceFile));

        return $this->containsTableReference($resourceStmts);
    }

    /**
     * Whether the controller depends on a table-bearing class via a constructor param or typed property.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    protected function controllerDependsOnTable(ReflectionClass $reflection): bool
    {
        /** @var array<class-string, true> $candidates */
        $candidates = [];

        $constructor = $reflection->getConstructor();

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $this->collectClassType($parameter->getType(), $candidates);
            }
        }

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $this->collectClassType($property->getType(), $candidates);
        }

        foreach (array_keys($candidates) as $class) {
            if ($this->classFileContainsTable($class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a non-builtin, existing class type to the candidate set.
     *
     * @param  array<class-string, true>  $candidates
     */
    protected function collectClassType(?ReflectionType $type, array &$candidates): void
    {
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return;
        }

        $class = $type->getName();

        if (class_exists($class)) {
            /** @var class-string $class */
            $candidates[$class] = true;
        }
    }

    /**
     * Whether the file declaring the given class references an Inertia UI Table subclass.
     *
     * @param  class-string  $class
     */
    protected function classFileContainsTable(string $class): bool
    {
        $file = (new ReflectionClass($class))->getFileName();

        if ($file === false) {
            return false;
        }

        DependencyRecorder::record($file);

        return $this->containsTableReference($this->parseAndResolveAst((string) file_get_contents($file)));
    }

    /**
     * Analyze a controller action for Inertia UI Table props.
     *
     * @return TablePageData|null
     */
    public function analyze(string $uses): ?array
    {
        if (! str_contains($uses, '@')) {
            return null;
        }

        [$controllerClass, $methodName] = explode('@', $uses, 2);

        if (! class_exists($controllerClass)) {
            return null;
        }

        /** @var class-string $controllerClass */
        $context = $this->methodContext($controllerClass, $methodName);

        if ($context === null) {
            return null;
        }

        ['reflection' => $reflection, 'method' => $method] = $context;

        $locator = resolve(InertiaRenderLocator::class);
        $renderCall = $locator->findRenderCall($method);

        if ($renderCall === null) {
            return null;
        }

        $component = $locator->componentName($renderCall);

        if ($component === null) {
            return null;
        }

        $props = $this->resolvePropsFromRenderCall($reflection, $renderCall);

        if ($props === []) {
            return null;
        }

        $package = $this->resolveTablePackage();
        $parts = [];
        $modelFqcns = [];

        foreach ($props as $key => $modelFqcn) {
            $parts[] = $key.': '.self::TABLE_TYPE.'<'.class_basename($modelFqcn).'>';

            if (! in_array($modelFqcn, $modelFqcns, true)) {
                $modelFqcns[] = $modelFqcn;
            }
        }

        return [
            'component' => $component,
            'pageType' => 'Inertia.SharedData & { '.implode(', ', $parts).' }',
            'classFqcns' => $modelFqcns,
            'externalImports' => [$package => [self::TABLE_TYPE]],
        ];
    }

    /**
     * Resolve the npm package that exports the table `TableResource` type.
     *
     * Priority: config value → package.json detection → @inertiaui/table-vue.
     */
    protected function resolveTablePackage(): string
    {
        $configured = config('ts-publish.inertia.ui_table_package');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return PackageJson::firstInstalled(self::TABLE_PACKAGES) ?? self::TABLE_PACKAGES[0];
    }

    /**
     * Locate a class method's own-file declaration via MethodLocator, recording its file dependency.
     *
     * @param  class-string  $class
     * @return array{reflection: ReflectionClass<object>, method: ClassMethod, finder: NodeFinder}|null
     */
    protected function methodContext(string $class, string $methodName): ?array
    {
        $context = resolve(MethodLocator::class)->locateOwn($class, $methodName);

        if ($context === null) {
            return null;
        }

        return ['reflection' => $context->reflection, 'method' => $context->method, 'finder' => new NodeFinder];
    }

    /**
     * Resolve table props from the second argument of Inertia::render(...).
     *
     * @param  ReflectionClass<object>  $controllerReflection
     * @return array<string, class-string<Model>>
     */
    protected function resolvePropsFromRenderCall(ReflectionClass $controllerReflection, StaticCall $renderCall): array
    {
        $propsExpr = resolve(InertiaRenderLocator::class)->propsArg($renderCall);

        if ($propsExpr === null) {
            return [];
        }

        return $this->resolvePropsExpression($controllerReflection, $propsExpr);
    }

    /**
     * Resolve table props from an inline array literal or a service-method call.
     *
     * @param  ReflectionClass<object>  $controllerReflection
     * @return array<string, class-string<Model>>
     */
    protected function resolvePropsExpression(ReflectionClass $controllerReflection, Expr $expr): array
    {
        if ($expr instanceof Array_) {
            return $this->resolvePropsArray($expr);
        }

        if ($expr instanceof MethodCall) {
            return $this->resolvePropsFromServiceMethod($controllerReflection, $expr);
        }

        return [];
    }

    /**
     * Map each string-keyed array item holding a table expression to its backing model class.
     *
     * @return array<string, class-string<Model>>
     */
    protected function resolvePropsArray(Array_ $array): array
    {
        /** @var array<string, class-string<Model>> $props */
        $props = [];

        foreach ($array->items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            $modelFqcn = $this->resolveModelFromTableExpression($item->value);

            if ($modelFqcn !== null) {
                $props[$item->key->value] = $modelFqcn;
            }
        }

        return $props;
    }

    /**
     * Resolve table props returned from a service method invoked on a typed controller property.
     *
     * @param  ReflectionClass<object>  $controllerReflection
     * @return array<string, class-string<Model>>
     */
    protected function resolvePropsFromServiceMethod(ReflectionClass $controllerReflection, MethodCall $call): array
    {
        if (! $call->name instanceof Identifier) {
            return [];
        }

        $serviceClass = resolve(CallMatcher::class)->resolveThisPropertyClass($controllerReflection, $call->var);

        if ($serviceClass === null) {
            return [];
        }

        $context = $this->methodContext($serviceClass, $call->name->toString());

        if ($context === null || $context['method']->stmts === null) {
            return [];
        }

        foreach ($context['method']->stmts as $stmt) {
            if ($stmt instanceof Return_ && $stmt->expr instanceof Array_) {
                return $this->resolvePropsArray($stmt->expr);
            }
        }

        return [];
    }

    /**
     * Walk a table prop expression back to its root table class and resolve that table's backing model.
     *
     * @return class-string<Model>|null
     */
    protected function resolveModelFromTableExpression(Expr $expr): ?string
    {
        $tableFqcn = resolve(CallChainWalker::class)
            ->resolveRootClass($expr, self::TABLE_BASE, allowNew: true, recordDependency: true);

        if ($tableFqcn === null) {
            return null;
        }

        return $this->resolveTableModel($tableFqcn);
    }

    /**
     * Resolve the backing Eloquent model for a table without instantiating it.
     *
     * Inertia UI Table exposes the model two ways: a `$resource` property default, or `Model::query()`
     * returned from a `query()` method.
     *
     * @param  class-string  $tableFqcn
     * @return class-string<Model>|null
     */
    protected function resolveTableModel(string $tableFqcn): ?string
    {
        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($tableFqcn);

        return $this->resolveModelFromResourceProperty($reflection)
            ?? $this->resolveModelFromQueryMethod($reflection);
    }

    /**
     * Read the model FQCN from `protected ?string $resource = Model::class;`.
     *
     * The model lives in the property's default value; its declared type is only `?string`.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return class-string<Model>|null
     */
    protected function resolveModelFromResourceProperty(ReflectionClass $reflection): ?string
    {
        if (! $reflection->hasProperty('resource')) {
            return null;
        }

        $property = $reflection->getProperty('resource');

        if (! $property->hasDefaultValue()) {
            return null;
        }

        $default = $property->getDefaultValue();

        if (! is_string($default) || ! class_exists($default) || ! is_a($default, Model::class, true)) {
            return null;
        }

        /** @var class-string<Model> $default */
        DependencyRecorder::recordClass($default);

        return $default;
    }

    /**
     * Read the model FQCN from the return expression of a `query()` method.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return class-string<Model>|null
     */
    protected function resolveModelFromQueryMethod(ReflectionClass $reflection): ?string
    {
        if (! $reflection->hasMethod('query')) {
            return null;
        }

        $context = $this->methodContext($reflection->getName(), 'query');

        if ($context === null || $context['method']->stmts === null) {
            return null;
        }

        foreach ($context['method']->stmts as $stmt) {
            if ($stmt instanceof Return_ && $stmt->expr instanceof Expr) {
                return $this->resolveModelFromQueryExpression($stmt->expr);
            }
        }

        return null;
    }

    /**
     * Resolve a model class from a `Model::query()`, `Model::class`, or model-rooted query chain.
     *
     * @return class-string<Model>|null
     */
    protected function resolveModelFromQueryExpression(Expr $expr): ?string
    {
        return resolve(CallChainWalker::class)
            ->resolveRootClass($expr, Model::class, allowClassConst: true, recordDependency: true);
    }

    /**
     * Report whether a parsed AST references an `InertiaUI\Table\Table` subclass.
     *
     * @param  array<Node>  $stmts
     */
    protected function containsTableReference(array $stmts): bool
    {
        $finder = new NodeFinder;

        /** @var array<StaticCall|New_> $candidates */
        $candidates = $finder->find($stmts, fn (Node $node): bool => $node instanceof StaticCall || $node instanceof New_);

        foreach ($candidates as $node) {
            $className = null;

            if ($node instanceof StaticCall && $node->class instanceof Name) {
                $className = $node->class->toString();
            }

            if ($node instanceof New_ && $node->class instanceof Name) {
                $className = $node->class->toString();
            }

            if (is_string($className) && class_exists($className) && is_a($className, self::TABLE_BASE, true)) {
                return true;
            }
        }

        return false;
    }
}
