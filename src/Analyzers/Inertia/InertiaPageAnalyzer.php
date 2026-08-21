<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Analyzers\Inertia;

use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\ChecksPreserveKeys;
use AbeTwoThree\LaravelTsPublish\Analyzers\Concerns\InspectsAstNodes;
use AbeTwoThree\LaravelTsPublish\Analyzers\SurveyorTypeMapper;
use AbeTwoThree\LaravelTsPublish\Attributes\TsCasts;
use AbeTwoThree\LaravelTsPublish\Cache\DependencyRecorder;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use Illuminate\Http\Resources\Json\ResourceCollection as LaravelResourceCollection;
use Illuminate\Support\Str;
use Laravel\Ranger\Collectors\InertiaComponents;
use Laravel\Ranger\Collectors\Response as ResponseCollector;
use Laravel\Ranger\Components\InertiaResponse;
use Laravel\Surveyor\Types\Contracts\Type;
use ReflectionClass;
use Throwable;

/**
 * Detects Inertia::render() calls in controller actions and extracts component names and page-prop types.
 *
 * @phpstan-type PageTypeResult = array{type: string, fqcns: list<class-string>, externalImports: array<string, list<string>>}
 * @phpstan-type InertiaPageData = array{
 *     component: string|list<string>,
 *     pageType: string|list<string>|null,
 *     classFqcns: list<class-string>,
 *     externalImports?: array<string, list<string>>,
 * }
 */
class InertiaPageAnalyzer
{
    use ChecksPreserveKeys;
    use InspectsAstNodes;

    /**
     * Create the analyzer with Ranger's response collector and an optional table analyzer override.
     */
    public function __construct(
        protected ResponseCollector $responseCollector,
        protected ?InertiaTableAnalyzer $tableAnalyzer = null,
    ) {}

    /**
     * Analyze a controller action and extract Inertia page data.
     *
     * @param  array{uses: string}  $action  The route action array with 'uses' key (Controller@method format).
     * @return InertiaPageData|null Null when the action does not render an Inertia response.
     */
    public function analyze(array $action): ?array
    {
        $tableAnalyzer = $this->resolveTableAnalyzer();
        $tableData = $tableAnalyzer->analyze($action['uses']);

        if ($tableData !== null) {
            return $tableData;
        }

        // Autoloading an Inertia UI Table subclass through Ranger triggers a PhpSpreadsheet fatal, so any
        // action in a file that references one must be typed statically instead of via parseResponse().
        if (str_contains($action['uses'], '@') && $tableAnalyzer->isTainted($action['uses'])) {
            $component = $tableAnalyzer->resolveComponent($action['uses']);

            if ($component !== null) {
                [$controllerClass, $methodName] = explode('@', $action['uses'], 2);
                $parsed = $this->parseTsCastsFromMethod($controllerClass, $methodName);
                $castOverrides = $parsed['overrides'];
                $castImportMap = $parsed['importMap'];

                if ($castOverrides !== []) {
                    $typeBody = $this->buildTypeStringWithOverrides([], $castOverrides);

                    return [
                        'component' => $component,
                        'pageType' => 'Inertia.SharedData & '.$typeBody,
                        'classFqcns' => [],
                        'externalImports' => $castImportMap,
                    ];
                }

                return [
                    'component' => $component,
                    'pageType' => null,
                    'classFqcns' => [],
                    'externalImports' => [],
                ];
            }

            return null;
        }

        // InertiaComponents keeps a static registry that accumulates props across calls rendering the
        // same component name; reset it so this method sees only its own props.
        $componentsProperty = (new ReflectionClass(InertiaComponents::class))->getProperty('components');
        $componentsProperty->setValue(null, []);

        // Ranger's parseResponse() returns component name strings, despite its docblock claiming InertiaResponse.
        /** @var list<string|mixed> $responses */
        $responses = $this->responseCollector->parseResponse($action);

        /** @var list<string> $componentNames */
        $componentNames = array_values(array_filter(
            $responses,
            fn (mixed $response): bool => is_string($response),
        ));

        if ($componentNames === []) {
            return null;
        }

        /** @var list<InertiaResponse> $inertiaResponses */
        $inertiaResponses = array_map(
            fn (string $name): InertiaResponse => InertiaComponents::getComponent($name),
            $componentNames,
        );

        $methodOverrides = [];
        $methodImportMap = [];
        $paginatorModelMap = [];
        $paginatedResourceProps = [];
        $paginatedStaticCollectionProps = [];

        if (str_contains($action['uses'], '@')) {
            [$controllerClass, $methodName] = explode('@', $action['uses'], 2);
            $parsed = $this->parseTsCastsFromMethod($controllerClass, $methodName);
            $methodOverrides = $parsed['overrides'];
            $methodImportMap = $parsed['importMap'];

            try {
                /** @var class-string $typedClass */
                $typedClass = $controllerClass;
                $analyzer = new ControllerPaginatorAnalyzer($typedClass, $methodName);
                $paginatorModelMap = $analyzer->analyze();
                $paginatedResourceProps = $analyzer->analyzePaginatedResourceProps();
                $paginatedStaticCollectionProps = $analyzer->analyzePaginatedStaticCollectionProps();
            } catch (Throwable) {
                // Non-fatal: fall back gracefully to <unknown>
            }
        }

        return $this->buildPageData(
            $inertiaResponses,
            $methodOverrides,
            $methodImportMap,
            $paginatorModelMap,
            $paginatedResourceProps,
            $paginatedStaticCollectionProps
        );
    }

    /**
     * Resolve the static Inertia UI Table analyzer.
     */
    protected function resolveTableAnalyzer(): InertiaTableAnalyzer
    {
        return $this->tableAnalyzer ??= resolve(InertiaTableAnalyzer::class);
    }

    /**
     * Build the page data from one or more InertiaResponse instances.
     *
     * With multiple (conditional) components, `pageType` is a list parallel to `component` so the
     * transformer can key one against the other.
     *
     * @param  list<InertiaResponse>  $responses
     * @param  array<string, string>  $methodOverrides  TsCasts overrides from the controller method.
     * @param  array<string, list<string>>  $methodImportMap  Import map from TsCasts `import` keys.
     * @param  array<string, class-string>  $paginatorModelMap  Prop key => model FQCN from controller AST analysis.
     * @param  array<string, class-string<object>>  $paginatedResourceProps  Prop key => resource FQCN for paginated resource constructor props.
     * @param  array<string, class-string>  $paginatedStaticCollectionProps  Prop key => resource FQCN for paginated Resource::collection() props.
     * @return InertiaPageData
     */
    protected function buildPageData(
        array $responses,
        array $methodOverrides = [],
        array $methodImportMap = [],
        array $paginatorModelMap = [],
        array $paginatedResourceProps = [],
        array $paginatedStaticCollectionProps = [],
    ): array {
        $components = array_map(
            fn (InertiaResponse $r): string => $r->component,
            $responses,
        );

        $pageTypeResults = array_map(
            fn (InertiaResponse $response): array => $this->buildPageType(
                $response,
                $methodOverrides,
                $paginatorModelMap,
                $paginatedResourceProps,
                $paginatedStaticCollectionProps,
            ),
            $responses,
        );

        $pageTypes = array_map(fn (array $r): string => $r['type'], $pageTypeResults);

        /** @var list<class-string> $allFqcns */
        $allFqcns = array_values(array_unique(array_merge(
            ...array_map(fn (array $r): array => $r['fqcns'], $pageTypeResults),
        )));

        /** @var array<string, list<string>> $externalImports */
        $externalImports = $methodImportMap;

        foreach ($pageTypeResults as $result) {
            foreach ($result['externalImports'] as $path => $types) {
                foreach ($types as $type) {
                    if (! in_array($type, $externalImports[$path] ?? [], true)) {
                        $externalImports[$path][] = $type;
                    }
                }
            }
        }

        $component = count($components) === 1 ? $components[0] : $components;

        $pageType = count($pageTypes) === 1 ? $pageTypes[0] : $pageTypes;

        return [
            'component' => $component,
            'pageType' => $pageType,
            'classFqcns' => $allFqcns,
            'externalImports' => $externalImports,
        ];
    }

    /**
     * Build the TypeScript type string for a single InertiaResponse.
     *
     * @param  array<string, string>  $methodOverrides  TsCasts overrides from the controller method.
     * @param  array<string, class-string>  $paginatorModelMap  Prop key => model FQCN from controller AST analysis.
     * @param  array<string, class-string<object>>  $paginatedResourceProps  Prop key => resource FQCN for paginated resource constructor props.
     * @param  array<string, class-string>  $paginatedStaticCollectionProps  Prop key => resource FQCN for paginated Resource::collection() props.
     * @return PageTypeResult
     */
    protected function buildPageType(
        InertiaResponse $response,
        array $methodOverrides = [],
        array $paginatorModelMap = [],
        array $paginatedResourceProps = [],
        array $paginatedStaticCollectionProps = [],
    ): array {
        $sharedData = 'Inertia.SharedData';

        if (count($response->data) === 0 && $methodOverrides === [] && $paginatorModelMap === [] && $paginatedResourceProps === [] && $paginatedStaticCollectionProps === []) {
            return ['type' => $sharedData, 'fqcns' => [], 'externalImports' => []];
        }

        $propsType = $methodOverrides !== []
            ? $this->buildTypeStringWithOverrides($response->data, $methodOverrides)
            : SurveyorTypeMapper::objectToTypeString($response->data);

        $fqcns = SurveyorTypeMapper::extractDotNotationFqcns($propsType);

        [$propsType, $fqcns, $externalImports] = $this->rewriteResourceCollections($propsType, $fqcns);

        [$propsType, $fqcns] = $this->rewritePaginatorGenerics($propsType, $fqcns, $paginatorModelMap);

        $propsType = SurveyorTypeMapper::rewriteDotNotationToBasenames($propsType, $fqcns);

        [$propsType, $fqcns, $resourcePaginationImports] = $this->rewritePaginatedResourceProps(
            $propsType,
            $fqcns,
            $paginatedResourceProps,
        );

        foreach ($resourcePaginationImports as $path => $types) {
            foreach ($types as $type) {
                if (! in_array($type, $externalImports[$path] ?? [], true)) {
                    $externalImports[$path][] = $type;
                }
            }
        }

        [$propsType, $fqcns, $staticCollectionImports] = $this->rewritePaginatedStaticCollectionProps(
            $propsType,
            $fqcns,
            $paginatedStaticCollectionProps,
        );

        foreach ($staticCollectionImports as $path => $types) {
            foreach ($types as $type) {
                if (! in_array($type, $externalImports[$path] ?? [], true)) {
                    $externalImports[$path][] = $type;
                }
            }
        }

        return [
            'type' => $sharedData.' & '.$propsType,
            'fqcns' => $fqcns,
            'externalImports' => $externalImports,
        ];
    }

    /**
     * Build a TypeScript object type string, applying TsCasts overrides for specific props.
     *
     * @param  array<string|int, mixed>  $props  Surveyor-analyzed properties.
     * @param  array<string, string>  $overrides  TsCasts type overrides keyed by property name.
     */
    private function buildTypeStringWithOverrides(array $props, array $overrides): string
    {
        if ($props === [] && $overrides === []) {
            return 'Record<string, never>';
        }

        $parts = [];

        foreach ($props as $key => $value) {
            if (isset($overrides[$key])) {
                $parts[] = $key.': '.$overrides[$key];

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

        foreach ($overrides as $key => $type) {
            if (! array_key_exists($key, $props)) {
                $parts[] = $key.': '.$type;
            }
        }

        return '{ '.implode(', ', $parts).' }';
    }

    /**
     * Rewrite paginator generic types in the type string based on the paginator-model map.
     *
     * @param  list<class-string>  $fqcns
     * @param  array<string, class-string>  $paginatorModelMap  prop key => model FQCN
     * @return array{string, list<class-string>}
     */
    protected function rewritePaginatorGenerics(
        string $typeString,
        array $fqcns,
        array $paginatorModelMap,
    ): array {
        if ($paginatorModelMap === []) {
            return [$typeString, $fqcns];
        }

        foreach ($paginatorModelMap as $propKey => $modelFqcn) {
            $modelDotNotation = str_replace('\\', '.', $modelFqcn);

            foreach (array_keys(SurveyorTypeMapper::TOLKI_TYPES_MAP) as $paginatorFqcn) {
                $paginatorDot = str_replace('\\', '.', $paginatorFqcn);
                $pattern = '/'.preg_quote($propKey, '/').':\s+'.preg_quote($paginatorDot, '/').'<[^>]*>/';
                $replacement = $propKey.': '.$paginatorDot.'<'.$modelDotNotation.'>';

                if (preg_match($pattern, $typeString)) {
                    $typeString = (string) preg_replace($pattern, $replacement, $typeString);

                    if (! in_array($modelFqcn, $fqcns, true)) {
                        $fqcns[] = $modelFqcn;
                    }

                    break;
                }
            }
        }

        return [$typeString, $fqcns];
    }

    /**
     * Rewrite prop types for resource objects constructed with a paginated variable.
     *
     * A collection with `$wrap === null` emits no `data` key, so it becomes `JsonResourcePaginator<Singular>`
     * rather than the wrapped resource intersected with `ResourcePagination`.
     *
     * @param  list<class-string>  $fqcns
     * @param  array<string, class-string<object>>  $paginatedResourceProps  Prop key => resource FQCN.
     * @return array{string, list<class-string>, array<string, list<string>>}
     */
    protected function rewritePaginatedResourceProps(string $typeString, array $fqcns, array $paginatedResourceProps): array
    {
        /** @var array<string, list<string>> $externalImports */
        $externalImports = [];

        if ($paginatedResourceProps === []) {
            return [$typeString, $fqcns, $externalImports];
        }

        foreach ($paginatedResourceProps as $propKey => $resourceFqcn) {
            if (! class_exists($resourceFqcn)) {
                continue;
            }

            DependencyRecorder::recordClass($resourceFqcn);

            $reflection = new ReflectionClass($resourceFqcn);
            $baseName = $reflection->getShortName();
            $defaults = $reflection->getDefaultProperties();

            $isFlat = is_a($resourceFqcn, LaravelResourceCollection::class, true)
                && array_key_exists('wrap', $defaults)
                && $defaults['wrap'] === null;

            $pattern = '/\b'.preg_quote($propKey, '/').':\s+'.preg_quote($baseName, '/').'(?![A-Za-z0-9_])/';

            if ($isFlat) {
                $singularFqcn = $this->resolveSingularResourceFqcn($resourceFqcn);
                $singularBase = $singularFqcn !== null ? (new ReflectionClass($singularFqcn))->getShortName() : 'unknown';

                $paginator = $this->collectionPreservesKeys($reflection)
                    ? "Omit<JsonResourcePaginator<{$singularBase}>, 'data'> & { data: Record<string, {$singularBase}> }"
                    : 'JsonResourcePaginator<'.$singularBase.'>';

                $typeString = (string) preg_replace($pattern, $propKey.': '.$paginator, $typeString);

                $externalImports['@tolki/types'][] = 'JsonResourcePaginator';

                /** @var list<class-string> $fqcns */
                $fqcns = array_values(array_filter($fqcns, fn (string $f) => $f !== $resourceFqcn));

                if ($singularFqcn !== null && ! in_array($singularFqcn, $fqcns, true)) {
                    $fqcns[] = $singularFqcn;
                }
            } else {
                $typeString = (string) preg_replace($pattern, $propKey.': '.$baseName.' & ResourcePagination', $typeString);

                $externalImports['@tolki/types'][] = 'ResourcePagination';
            }
        }

        /** @var list<class-string> $fqcns */
        return [$typeString, $fqcns, $externalImports];
    }

    /**
     * Rewrite paginated `Resource::collection()` props to `JsonResourcePaginator<ResourceName>`.
     *
     * @param  list<class-string>  $fqcns
     * @param  array<string, class-string>  $paginatedStaticCollectionProps  Prop key => resource FQCN.
     * @return array{string, list<class-string>, array<string, list<string>>}
     */
    protected function rewritePaginatedStaticCollectionProps(string $typeString, array $fqcns, array $paginatedStaticCollectionProps): array
    {
        /** @var array<string, list<string>> $externalImports */
        $externalImports = [];

        if ($paginatedStaticCollectionProps === []) {
            return [$typeString, $fqcns, $externalImports];
        }

        foreach ($paginatedStaticCollectionProps as $propKey => $resourceFqcn) {
            if (! class_exists($resourceFqcn)) {
                continue;
            }

            DependencyRecorder::recordClass($resourceFqcn);

            $reflection = new ReflectionClass($resourceFqcn);
            $baseName = $reflection->getShortName();

            // Resource::collection() inherits the singular resource's preserve-keys state.
            $paginator = $this->collectionPreservesKeys($reflection)
                ? "Omit<JsonResourcePaginator<{$baseName}>, 'data'> & { data: Record<string, {$baseName}> }"
                : 'JsonResourcePaginator<'.$baseName.'>';

            // Paginated props are absent from paginatorModelMap, so rewritePaginatorGenerics left `<unknown>`.
            $pattern = '/\b'.preg_quote($propKey, '/').': AnonymousResourceCollection<unknown>/';
            $typeString = (string) preg_replace($pattern, $propKey.': '.$paginator, $typeString);

            $externalImports['@tolki/types'][] = 'JsonResourcePaginator';

            if (! in_array($resourceFqcn, $fqcns, true)) {
                $fqcns[] = $resourceFqcn;
            }
        }

        /** @var list<class-string> $fqcns */
        return [$typeString, $fqcns, $externalImports];
    }

    /**
     * Detect ResourceCollection subclasses in the FQCNs list and rewrite them in the type string.
     *
     * @param  list<class-string>  $fqcns
     * @return array{string, list<class-string>, array<string, list<string>>}
     */
    protected function rewriteResourceCollections(string $typeString, array $fqcns): array
    {
        /** @var array<string, list<string>> $externalImports */
        $externalImports = [];

        /** @var list<class-string> $rewrittenFqcns */
        $rewrittenFqcns = [];

        foreach ($fqcns as $fqcn) {
            // FQCNs in TOLKI_TYPES_MAP are handled upstream by resolvePageTypeImports()
            if (isset(SurveyorTypeMapper::TOLKI_TYPES_MAP[$fqcn])) {
                $rewrittenFqcns[] = $fqcn;

                continue;
            }

            if (! class_exists($fqcn) || ! is_a($fqcn, LaravelResourceCollection::class, true)) {
                DependencyRecorder::recordClass($fqcn);
                $rewrittenFqcns[] = $fqcn;

                continue;
            }

            DependencyRecorder::recordClass($fqcn);

            $dotNotation = str_replace('\\', '.', $fqcn);
            $collectionName = class_basename($fqcn);
            $typeString = str_replace($dotNotation, $collectionName, $typeString);

            $rewrittenFqcns[] = $fqcn;
        }

        return [$typeString, $rewrittenFqcns, $externalImports];
    }

    /**
     * Resolve the singular resource FQCN for a ResourceCollection subclass.
     *
     * Mirrors Laravel's own resolution order: #[Collects], then `$collects`, then `XCollection` → `XResource`.
     *
     * @param  class-string  $collectionFqcn
     * @return class-string|null
     */
    protected function resolveSingularResourceFqcn(string $collectionFqcn): ?string
    {
        return $this->resolveCollectedResourceClass($collectionFqcn);
    }

    /**
     * Parse the `#[TsCasts]` attribute from a controller method.
     *
     * @return array{overrides: array<string, string>, importMap: array<string, list<string>>}
     */
    protected function parseTsCastsFromMethod(string $controllerClass, string $methodName): array
    {
        if (! class_exists($controllerClass)) {
            return ['overrides' => [], 'importMap' => []];
        }

        DependencyRecorder::recordClass($controllerClass);

        $reflection = new ReflectionClass($controllerClass);

        if (! $reflection->hasMethod($methodName)) {
            return ['overrides' => [], 'importMap' => []];
        }

        $attrs = $reflection->getMethod($methodName)->getAttributes(TsCasts::class);

        if ($attrs === []) {
            return ['overrides' => [], 'importMap' => []];
        }

        /** @var TsCasts $tsCasts */
        $tsCasts = $attrs[0]->newInstance();

        $overrides = [];
        $importMap = [];

        foreach ($tsCasts->types as $prop => $value) {
            if (is_array($value)) {
                $overrides[$prop] = $value['type'];

                if (isset($value['import'])) {
                    foreach (LaravelTsPublish::extractImportableTypes($value['type']) as $typeName) {
                        $importMap[$value['import']][] = $typeName;
                    }
                }
            } else {
                $overrides[$prop] = $value;
            }
        }

        return ['overrides' => $overrides, 'importMap' => $importMap];
    }

    /**
     * Convert a component name to a fully-qualified Inertia namespace path.
     *
     * @example "Dashboard" → "Inertia.Pages.Dashboard"
     * @example "Settings/General" → "Inertia.Pages.Settings.General"
     */
    public function componentToFqn(string $component): string
    {
        $normalized = str_replace('::', '/', $component);

        return collect(explode('/', $normalized))
            ->map(fn (string $part): string => Str::studly($part))
            ->prepend('Inertia.Pages')
            ->implode('.');
    }
}
