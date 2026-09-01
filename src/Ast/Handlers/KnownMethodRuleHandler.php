<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast\Handlers;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Ast\AnalysisScope;
use AbeTwoThree\LaravelTsPublish\Ast\AuthUserResolver;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\AppliesKnownMethodRules;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\InspectsResourceSubject;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\ResolvesAuthHelperCalls;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\ResolvesModelRelationTypes;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionEngine;
use AbeTwoThree\LaravelTsPublish\Ast\Contracts\ExpressionHandler;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;

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
    use ResolvesAuthHelperCalls;
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
            $request = $this->requestMethodRule($expr, $scope);

            if ($request !== null) {
                return $request;
            }

            $known = $this->knownMethodRule($expr, $scope);

            if ($known !== null) {
                return $known;
            }
        }

        return null;
    }

    /**
     * Method-name rules for an `Illuminate\Http\Request` receiver, e.g. `$request->url()`.
     *
     * Gated on the receiver being a variable the scope knows holds a Request: these names
     * (`string`, `boolean`, `user`, …) are far too common to type on the name alone.
     *
     * @return ValueExpressionResult|null
     */
    private function requestMethodRule(MethodCall $expr, AnalysisScope $scope): ?array
    {
        if (! $expr->name instanceof Identifier
            || ! $expr->var instanceof Variable
            || ! is_string($expr->var->name)
            || ! isset($scope->requestVarNames[$expr->var->name])) {
            return null;
        }

        $method = $expr->name->toString();

        if ($method === 'user') {
            return $this->authMethodResult($method, resolve(AuthUserResolver::class)->model());
        }

        $type = match ($method) {
            'url', 'fullUrl', 'path', 'string' => 'string',
            'integer' => 'number',
            'boolean', 'hasCookie' => 'boolean',
            'cookie' => 'string | null',
            default => null,
        };

        return $type === null ? null : ['type' => $type, 'optional' => false];
    }
}
