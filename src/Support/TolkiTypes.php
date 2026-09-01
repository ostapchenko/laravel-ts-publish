<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Support;

/**
 * The PHP classes the `@tolki/types` package declares TypeScript types for, and the dot-notation
 * helpers that read and rewrite those names inside a rendered page-prop type string.
 */
final class TolkiTypes
{
    /**
     * Maps PHP FQCNs to their TypeScript names in the `@tolki/types` package.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'Illuminate\\Pagination\\LengthAwarePaginator' => 'LengthAwarePaginator',
        'Illuminate\\Pagination\\Paginator' => 'SimplePaginator',
        'Illuminate\\Pagination\\CursorPaginator' => 'CursorPaginator',
        'Illuminate\\Contracts\\Pagination\\LengthAwarePaginator' => 'LengthAwarePaginator',
        'Illuminate\\Contracts\\Pagination\\Paginator' => 'SimplePaginator',
        'Illuminate\\Contracts\\Pagination\\CursorPaginator' => 'CursorPaginator',
        'Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection' => 'AnonymousResourceCollection',
    ];

    /**
     * Extract PHP FQCNs from a type string containing dot-notation class references.
     *
     * `Inertia.*` is skipped: it is a TypeScript global namespace, not a PHP class.
     *
     * @return list<class-string>
     */
    public static function extractDotNotationFqcns(string $typeString): array
    {
        preg_match_all('/\b([A-Z][A-Za-z0-9]*(?:\.[A-Z][A-Za-z0-9]*)+)\b/', $typeString, $matches);

        /** @var list<class-string> $fqcns */
        $fqcns = [];

        foreach (array_unique($matches[1]) as $dotNotation) {
            if (str_starts_with($dotNotation, 'Inertia.')) {
                continue;
            }

            $fqcn = str_replace('.', '\\', $dotNotation);

            if (class_exists($fqcn) || enum_exists($fqcn) || interface_exists($fqcn)) {
                /** @var class-string $fqcn */
                $fqcns[] = $fqcn;
            }
        }

        return $fqcns;
    }

    /**
     * Rewrite dot-notation class references in a type string to their base names.
     *
     * @param  list<class-string>  $fqcns
     */
    public static function rewriteDotNotationToBasenames(string $typeString, array $fqcns): string
    {
        foreach ($fqcns as $fqcn) {
            $dotNotation = str_replace('\\', '.', $fqcn);
            $basename = self::MAP[$fqcn] ?? class_basename($fqcn);
            $typeString = str_replace($dotNotation, $basename, $typeString);
        }

        return $typeString;
    }
}
