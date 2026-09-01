<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Analyzers\ResourceAstAnalyzer;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\BinaryOpHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\CastHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ClassConstantHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ClosureHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\CoalesceHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ConditionalMethodHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ConstFetchHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\FirstClassCallableHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\InlineArrayHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\KnownFunctionCallHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\KnownMethodRuleHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\MethodChainHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\NewResourceHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\PropertyChainHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\RelationCollectionChainHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\RelationFilterHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ScalarHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\StaticCallHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\TernaryHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ThisPropertyHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\ToResourceHandler;
use AbeTwoThree\LaravelTsPublish\Ast\Handlers\VariableHandler;
use AbeTwoThree\LaravelTsPublish\Ast\MethodAnalysis;
use AbeTwoThree\LaravelTsPublish\Ast\ResourceExpressionHandlers;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VariadicPlaceholder;
use Workbench\App\Http\Resources\CommentResource;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;

/**
 * The documented dispatch order for the resource profile — Tasks 14-22's chain-derived precedence,
 * moved unchanged by Task 23. A change here without a matching change in
 * ResourceExpressionHandlers::handlers() is exactly the defect this test exists to catch.
 *
 * @return list<class-string<ExpressionHandler>>
 */
function resourceExpressionHandlerOrder(): array
{
    return [
        FirstClassCallableHandler::class,
        CastHandler::class,
        ScalarHandler::class,
        ConstFetchHandler::class,
        ClassConstantHandler::class,
        BinaryOpHandler::class,
        CoalesceHandler::class,
        KnownFunctionCallHandler::class,
        ClosureHandler::class,
        ConditionalMethodHandler::class,
        ToResourceHandler::class,
        StaticCallHandler::class,
        NewResourceHandler::class,
        ThisPropertyHandler::class,
        RelationFilterHandler::class,
        InlineArrayHandler::class,
        MethodChainHandler::class,
        PropertyChainHandler::class,
        RelationCollectionChainHandler::class,
        VariableHandler::class,
        TernaryHandler::class,
        KnownMethodRuleHandler::class,
    ];
}

/** A throwaway engine: make()/generic() only construct handlers, they never resolve anything. */
function resourceExpressionHandlersTestEngine(): ExpressionEngine
{
    return new class implements ExpressionEngine
    {
        public function resolve(Expr $expr): array
        {
            throw new RuntimeException('resolve() must not be called while merely constructing handlers');
        }

        public function spreadAnalysis(string $methodName): ?MethodAnalysis
        {
            throw new RuntimeException('spreadAnalysis() must not be called while merely constructing handlers');
        }

        public function returnArrayAnalysis(Array_ $array): MethodAnalysis
        {
            throw new RuntimeException('returnArrayAnalysis() must not be called while merely constructing handlers');
        }
    };
}

it('returns all 22 handlers in the documented dispatch order', function () {
    $classes = array_map(
        fn (ExpressionHandler $handler): string => $handler::class,
        ResourceExpressionHandlers::make(resourceExpressionHandlersTestEngine()),
    );

    expect($classes)->toBe(resourceExpressionHandlerOrder());
});

it('excludes exactly the three resource-only handlers from generic(), same relative order', function () {
    $classes = array_map(
        fn (ExpressionHandler $handler): string => $handler::class,
        ResourceExpressionHandlers::generic(),
    );

    $expected = array_values(array_filter(
        resourceExpressionHandlerOrder(),
        fn (string $class): bool => ! in_array($class, [
            ConditionalMethodHandler::class,
            ToResourceHandler::class,
            RelationFilterHandler::class,
        ], true),
    ));

    expect($classes)->toHaveCount(19)
        ->and($classes)->toBe($expected);
});

// Ordering-contract pin #1: FirstClassCallableHandler and ConditionalMethodHandler are the only two
// registered handlers claiming MethodCall ahead of a first-class-callable $this->when(...), and both
// really claim it — ConditionalMethodHandler::isThisMethodCall() matches on method name alone,
// ignoring args entirely. If ConditionalMethodHandler ran first it would call MethodCall::getArgs(),
// which asserts !isFirstClassCallable() and throws under this suite's zend.assertions=1. Swapping
// their positions in ResourceExpressionHandlers::handlers() was confirmed to turn this pass into a
// fatal AssertionError; see task-23-report.md for the exact mutation and captured output.
it('tries FirstClassCallableHandler before ConditionalMethodHandler for a first-class-callable $this->when(...)', function () {
    $expr = new MethodCall(new Variable('this'), 'when', [new VariadicPlaceholder]);
    $analyzer = new ResourceAstAnalyzer(new ReflectionClass(CommentResource::class), Comment::class);

    expect($analyzer->resolve($expr))->toBe(['type' => 'unknown', 'optional' => false]);
});

// Ordering-contract pin #2: RelationFilterHandler and MethodChainHandler are the only two registered
// handlers claiming NullsafeMethodCall. MethodChainHandler::analyzeMethodChain() never returns null —
// its floor is ValueResult::unknown() — so if it ran first it would win every NullsafeMethodCall,
// including this one, and RelationFilterHandler would never get a turn. Swapping their positions was
// confirmed to degrade this from a Pick<> reference to plain 'unknown'; see task-23-report.md.
it('tries RelationFilterHandler before MethodChainHandler for $this->relation?->only([...])', function () {
    $expr = new NullsafeMethodCall(
        new PropertyFetch(new Variable('this'), 'post'),
        'only',
        [new Arg(new Array_([
            new ArrayItem(new String_('id')),
            new ArrayItem(new String_('title')),
        ]))],
    );
    $analyzer = new ResourceAstAnalyzer(new ReflectionClass(CommentResource::class), Comment::class);

    expect($analyzer->resolve($expr))->toBe([
        'type' => "Pick<Post, 'id' | 'title'> | null",
        'optional' => false,
        'modelFqcn' => Post::class,
    ]);
});
