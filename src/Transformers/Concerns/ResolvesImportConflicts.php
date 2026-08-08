<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Transformers\Concerns;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Shared import conflict resolution helpers for transformers.
 */
trait ResolvesImportConflicts
{
    /** @var array<string, string> FQCN => aliased TypeScript name (only for conflicting imports) */
    protected array $importAliases = [];

    /** @var array<string, string> FQCN => aliased TypeScript const name (only for conflicting imports) */
    protected array $constImportAliases = [];

    /**
     * Format an import name, applying "OriginalName as Alias" syntax when aliased.
     */
    protected function formatImportName(string $fqcn, string $typeName): string
    {
        $alias = $this->importAliases[$fqcn] ?? null;

        if ($alias !== null && $alias !== $typeName) {
            return $typeName.' as '.$alias;
        }

        return $typeName;
    }

    /**
     * Format a const import name, applying "OriginalName as Alias" syntax when aliased.
     */
    protected function formatConstImportName(string $fqcn): string
    {
        $constName = $this->enumConstMap[$fqcn];
        $alias = $this->constImportAliases[$fqcn] ?? null;

        if ($alias !== null && $alias !== $constName) {
            return $constName.' as '.$alias;
        }

        return $constName;
    }

    /**
     * Compute a distinguishing namespace prefix for alias generation.
     *
     * @param  list<string>  $skip  Namespace segments to skip (e.g. ['Models', 'Enums', 'App'])
     */
    protected function computeNamespacePrefix(string $fqcn, array $skip = ['Models', 'Enums', 'App']): string
    {
        $namespace = Str::beforeLast($fqcn, '\\');

        $prefix = Config::string('ts-publish.namespace_strip_prefix', '');

        if ($prefix !== '' && str_starts_with($namespace, $prefix)) {
            $namespace = substr($namespace, strlen($prefix));
        }

        $segments = array_filter(explode('\\', $namespace));

        foreach (array_reverse($segments) as $segment) {
            if (! in_array($segment, $skip, true)) {
                return Str::studly($segment);
            }
        }

        $first = reset($segments);

        return $first !== false ? Str::studly($first) : '';
    }
}
