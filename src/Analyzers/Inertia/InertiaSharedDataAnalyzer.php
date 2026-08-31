<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Analyzers\Inertia;

use AbeTwoThree\LaravelTsPublish\Analyzers\SurveyorTypeMapper;
use AbeTwoThree\LaravelTsPublish\Ast\TsCastsReader;
use AbeTwoThree\LaravelTsPublish\Attributes\TsCasts;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use AbeTwoThree\LaravelTsPublish\Support\TsCastsImportResolver;
use Composer\ClassMapGenerator\ClassMapGenerator;
use Laravel\Ranger\Collectors\InertiaSharedData as InertiaSharedDataCollector;
use Laravel\Ranger\Components\InertiaSharedData as SharedDataComponent;
use Laravel\Surveyor\Types\Contracts\Type;
use ReflectionClass;

/**
 * @phpstan-import-type TsCastsUnpacked from TsCastsReader
 *
 * @phpstan-type TsCastsParseResult = array{
 *     overrides: array<string, string>,
 *     importPaths: array<string, string>,
 * }
 * @phpstan-type SharedDataResult = array{
 *     sharedPageProps: string,
 *     withAllErrors: bool,
 *     importStatements: list<string>,
 * }
 * @phpstan-type OverrideEntry = array{type: string, optional: bool}
 */
class InertiaSharedDataAnalyzer
{
    /** @var list<string> */
    protected array $appPaths = [];

    public function __construct(
        protected InertiaSharedDataCollector $collector,
    ) {}

    /**
     * Set the app path(s) for the Inertia shared data collector.
     */
    public function setAppPaths(string ...$paths): void
    {
        $this->appPaths = array_values($paths);
        $this->collector->setAppPaths(...$paths);
    }

    /**
     * Collect and convert Inertia shared data from HandleInertiaRequests middleware.
     *
     * @return SharedDataResult|null Null when no shared data components are collected.
     */
    public function analyze(): ?array
    {
        $sharedDataComponents = $this->collector->collect();

        if ($sharedDataComponents->isEmpty()) {
            return null;
        }

        /** @var SharedDataComponent $component */
        $component = $sharedDataComponents->first();

        $middlewareClass = $this->discoverMiddlewareClass();

        return $this->buildResult($component, $middlewareClass);
    }

    /**
     * Discover the first HandleInertiaRequests middleware class from the app paths.
     *
     * @return class-string|null
     */
    protected function discoverMiddlewareClass(): ?string
    {
        if ($this->appPaths === []) {
            return null;
        }

        foreach ($this->appPaths as $path) {
            $classes = array_keys(ClassMapGenerator::createMap($path));

            foreach ($classes as $class) {
                if (class_exists($class) && is_subclass_of($class, 'Inertia\Middleware')) {
                    return $class;
                }
            }
        }

        return null;
    }

    /**
     * Build the result array from a SharedDataComponent.
     *
     * Type resolution priority: #[TsCasts] > @return docblock > Surveyor inference.
     *
     * @param  class-string|null  $middlewareClass
     * @return SharedDataResult
     */
    protected function buildResult(SharedDataComponent $component, ?string $middlewareClass): array
    {
        /** @var array<string|int, mixed> $props */
        $props = $component->data->value;

        $tsCasts = $this->parseTsCastsFromMiddleware($middlewareClass);
        $docblockOverrides = $this->parseDocblockFromMiddleware($middlewareClass);

        $resolver = new TsCastsImportResolver;
        $resolvedTsCasts = $resolver->resolve($tsCasts['overrides'], $tsCasts['importPaths']);

        $mergedOverrides = array_merge($docblockOverrides, $resolvedTsCasts['overrides']);
        $propsType = $this->buildTypeStringWithOverrides($props, $mergedOverrides);

        return [
            'sharedPageProps' => $propsType,
            'withAllErrors' => $component->withAllErrors,
            'importStatements' => $resolvedTsCasts['importStatements'],
        ];
    }

    /**
     * Parse #[TsCasts] attributes from the middleware class and its share() method.
     *
     * Method-level attributes take priority over class-level, matching Laravel's cast resolution order.
     *
     * @param  class-string|null  $className
     * @return TsCastsParseResult
     */
    protected function parseTsCastsFromMiddleware(?string $className): array
    {
        if ($className === null || ! class_exists($className)) {
            return ['overrides' => [], 'importPaths' => []];
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($className);

        $attributes = [];

        foreach ($reflection->getAttributes(TsCasts::class) as $attr) {
            $attributes[] = $attr->newInstance();
        }

        if ($reflection->hasMethod('share')) {
            foreach ($reflection->getMethod('share')->getAttributes(TsCasts::class) as $attr) {
                $attributes[] = $attr->newInstance();
            }
        }

        /** @var TsCastsUnpacked $unpacked */
        $unpacked = resolve(TsCastsReader::class)->unpack($attributes);

        return ['overrides' => $unpacked['overrides'], 'importPaths' => $unpacked['importPaths']];
    }

    /**
     * Extract per-key type overrides from the `@return array{...}` docblock on the middleware's share().
     *
     * @param  class-string|null  $className
     * @return array<string, string>
     */
    protected function parseDocblockFromMiddleware(?string $className): array
    {
        if ($className === null || ! class_exists($className)) {
            return [];
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($className);

        if (! $reflection->hasMethod('share')) {
            return [];
        }

        return LaravelTsPublish::parseDocblockReturnArrayShape($reflection->getMethod('share'));
    }

    /**
     * Build a TypeScript object type string, applying TsCasts overrides where present.
     *
     * @param  array<string|int, mixed>  $props  The Surveyor-analyzed properties.
     * @param  array<string, string>  $overrides  Type overrides keyed by property name.
     */
    protected function buildTypeStringWithOverrides(array $props, array $overrides): string
    {
        if ($props === [] && $overrides === []) {
            return 'Record<string, never>';
        }

        $normalized = $this->normalizeOverrideKeys($overrides);
        $parts = [];

        foreach ($props as $key => $value) {
            if (isset($normalized[$key])) {
                $parts[] = $key.($normalized[$key]['optional'] ? '?: ' : ': ').$normalized[$key]['type'];

                continue;
            }

            $optional = $value instanceof Type && $value->isOptional();
            $separator = $optional ? '?: ' : ': ';

            if (is_array($value)) {
                $tsType = SurveyorTypeMapper::objectToTypeString($value);
            } elseif ($value instanceof Type) {
                $tsType = SurveyorTypeMapper::convert($value);
            } else {
                $tsType = 'unknown';
            }

            $parts[] = $key.$separator.$tsType;
        }

        foreach ($normalized as $key => $override) {
            if (! array_key_exists($key, $props)) {
                $parts[] = $key.($override['optional'] ? '?: ' : ': ').$override['type'];
            }
        }

        return '{ '.implode(', ', $parts).' }';
    }

    /**
     * Split the docblock parser's key-embedded optional marker back out, so overrides can be matched
     * against Surveyor's plain property names — otherwise `filters?` misses `filters` and the entry is
     * emitted twice, which TypeScript rejects as a duplicate identifier.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, OverrideEntry>
     */
    protected function normalizeOverrideKeys(array $overrides): array
    {
        $normalized = [];

        // Insertion order carries priority: #[TsCasts] entries are merged after docblock ones, so an
        // attribute-supplied `filters` still wins over a docblock-supplied `filters?`.
        foreach ($overrides as $key => $type) {
            $optional = str_ends_with($key, '?');
            $name = $optional ? substr($key, 0, -1) : $key;

            $normalized[$name] = ['type' => $type, 'optional' => $optional];
        }

        return $normalized;
    }
}
