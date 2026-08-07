<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Concerns;

use Illuminate\Routing\Route;

trait FiltersRoutes
{
    /**
     * Determine whether a route should be included in publishing.
     *
     * @param  list<string>  $only
     * @param  list<string>  $except
     * @param  list<string>  $excludeMiddleware
     */
    protected function shouldIncludeRoute(Route $route, array $only, array $except, bool $onlyNamed, array $excludeMiddleware): bool
    {
        // 'generated::' names are Laravel route-cache artifacts, not real routes.
        $name = $route->getName();

        if ($name !== null && str_starts_with($name, 'generated::')) {
            return false;
        }

        if ($route->isFallback) {
            return false;
        }

        // Closure-only routes have no controller to group the generated file under.
        if ($route->getControllerClass() === null) {
            return false;
        }

        if ($onlyNamed && $name === null) {
            return false;
        }

        if ($excludeMiddleware !== []) {
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && in_array($mw, $excludeMiddleware, true)) {
                    return false;
                }
            }
        }

        if ($only !== []) {
            return $name !== null && $this->matchesPatterns($name, $only);
        }

        if ($except !== [] && $name !== null && $this->matchesPatterns($name, $except)) {
            return false;
        }

        return true;
    }

    /**
     * Check whether a route name matches any of the given patterns.
     *
     * Supports wildcards ('posts.*') and negation ('!posts.index'). Negations beat positives, and a
     * negation-only list matches every name it does not negate.
     *
     * @param  list<string>  $patterns
     */
    protected function matchesPatterns(string $name, array $patterns): bool
    {
        $hasPositive = false;

        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '!')) {
                if (fnmatch(substr($pattern, 1), $name)) {
                    return false;
                }
            } else {
                $hasPositive = true;
            }
        }

        if (! $hasPositive) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if (! str_starts_with($pattern, '!') && fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
