<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\AppliesKnownMethodRules;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\InspectsResourceSubject;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\ResolvesModelRelationTypes;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;

/**
 * The dispatch floor: Laravel-convention method-name rules for method calls no earlier handler
 * claimed — e.g. `$request->user()->can(…)`, whose receiver is itself a MethodCall. Registered last.
 *
 * @phpstan-import-type ValueExpressionResult from ExpressionHandler
 */
final class KnownMethodRuleHandler implements ExpressionHandler
{
    use AppliesKnownMethodRules;
    use InspectsAstNodes;
    use InspectsResourceSubject;
    use ResolvesModelRelationTypes;

    /** @return list<class-string<Expr>> */
    public function nodeClasses(): array
    {
        return [MethodCall::class];
    }

    /** @return ValueExpressionResult|null */
    public function resolve(Expr $expr, AnalysisScope $scope, ExpressionEngine $engine): ?array
    {
        // Only MethodCall reaches here — every NullsafeMethodCall already returned via MethodChainHandler.
        if ($expr instanceof MethodCall) {
            $known = $this->knownMethodRule($expr, $scope);

            if ($known !== null) {
                return $known;
            }
        }

        return null;
    }
}
