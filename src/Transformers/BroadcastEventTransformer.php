<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Transformers;

use AbeTwoThree\LaravelTsPublish\Analyzers\SurveyorTypeMapper;
use AbeTwoThree\LaravelTsPublish\Concerns\ParsesTsCasts;
use AbeTwoThree\LaravelTsPublish\Dtos\Contracts\Datable;
use AbeTwoThree\LaravelTsPublish\Dtos\TsBroadcastEventDto;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use AbeTwoThree\LaravelTsPublish\Transformers\Concerns\ParsesTsExtends;
use AbeTwoThree\LaravelTsPublish\Transformers\Concerns\ResolvesImportConflicts;
use AbeTwoThree\LaravelTsPublish\Transformers\Concerns\SnapshotsTransformerState;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Laravel\Surveyor\Analyzed\ClassResult;
use Laravel\Surveyor\Analyzer\Analyzer;
use Laravel\Surveyor\Types\ArrayType;
use Laravel\Surveyor\Types\ClassType;
use Laravel\Surveyor\Types\Contracts\Type;
use Laravel\Surveyor\Types\IntersectionType;
use Laravel\Surveyor\Types\StringType;
use Laravel\Surveyor\Types\UnionType;
use Override;
use ReflectionClass;
use UnitEnum;

/**
 * Transforms a broadcast event class into a TsBroadcastEventDto.
 *
 * @phpstan-import-type TypesImportMap from Datable
 * @phpstan-import-type PropertyInfo from TsBroadcastEventDto
 * @phpstan-import-type PropertiesList from TsBroadcastEventDto
 *
 * @extends CoreTransformer<ShouldBroadcast>
 */
class BroadcastEventTransformer extends CoreTransformer
{
    use ParsesTsCasts;
    use ParsesTsExtends;
    use ResolvesImportConflicts;
    use SnapshotsTransformerState;

    /** Short PHP class name, e.g. 'OrderShipped'. */
    public protected(set) string $eventName;

    /** Echo event string: '.Namespace.ClassName' by default, or the broadcastAs() return value. */
    public protected(set) string $broadcastName;

    /** Absolute path to the PHP source file. */
    public protected(set) string $filePath;

    /** Namespace-based directory path, e.g. 'workbench/app/events'. */
    public protected(set) string $namespacePath;

    /**
     * Payload property map: name → ['type' => 'number', 'optional' => false].
     *
     * @var PropertiesList
     */
    public protected(set) array $properties = [];

    /**
     * Model FQCNs found in properties (FQCN => short TS type name).
     *
     * @var array<class-string, string>
     */
    protected array $modelFqcnMap = [];

    /**
     * Enum FQCNs found in properties (FQCN => 'NameType').
     *
     * @var array<class-string, string>
     */
    protected array $enumFqcnMap = [];

    /**
     * Resolved type imports: import path => list of type names.
     *
     * @var TypesImportMap
     */
    public protected(set) array $typeImports = [];

    /**
     * Per-property FQCN tracking — maps property name to list of FQCNs added by that property's type.
     *
     * @var array<string, list<class-string>>
     */
    protected array $propertyFqcns = [];

    /**
     * Always empty — only present because ResolvesImportConflicts::formatConstImportName() reads it.
     *
     * @var array<class-string, string>
     */
    protected array $enumConstMap = [];

    /**
     * TsCasts attribute overrides: property name => TypeScript type string.
     *
     * @var array<string, string>
     */
    protected array $tsTypeOverrides = [];

    /**
     * Import paths declared alongside TsCasts overrides: property name => import path.
     *
     * @var array<string, string>
     */
    protected array $tsCastsImportPaths = [];

    /**
     * Optional overrides from #[TsCasts]: property name => optional flag.
     *
     * @var array<string, bool>
     */
    protected array $optionalOverrides = [];

    /**
     * TypeScript extends clauses parsed from #[TsExtends] attributes and config.
     *
     * @var list<string>
     */
    public protected(set) array $tsExtends = [];

    /**
     * Import entries from TsExtends to be merged into typeImports.
     *
     * @var array<string, list<string>>
     */
    protected array $tsExtendsImports = [];

    /** Surveyor class analysis result used across transformation steps. */
    protected ClassResult $analyzed;

    /** @var ReflectionClass<ShouldBroadcast> */
    protected ReflectionClass $reflection;

    /** @return list<string> */
    protected function transientProperties(): array
    {
        return ['reflection', 'analyzed', 'analyzer'];
    }

    /**
     * @param  class-string<ShouldBroadcast>  $findable
     */
    public function __construct(
        string $findable,
        protected Analyzer $analyzer,
    ) {
        parent::__construct($findable);
    }

    #[Override]
    public function transform(): self
    {
        $this->runAnalysis()
            ->initEventData()
            ->parseTsExtends()
            ->parseTsCasts()
            ->transformBroadcastName()
            ->transformProperties()
            ->resolveImportConflicts()
            ->buildTypeImports();

        return $this;
    }

    /**
     * Run the Surveyor analyzer and store the result for subsequent steps.
     */
    protected function runAnalysis(): self
    {
        $this->analyzer->analyzeClass($this->findable);

        /** @var ClassResult $result */
        $result = $this->analyzer->result();
        $this->analyzed = $result;

        return $this;
    }

    /**
     * Initialize core event metadata from the analyzer result and reflection.
     */
    protected function initEventData(): self
    {
        $this->reflection = new ReflectionClass($this->findable);
        $this->eventName = $this->reflection->getShortName();
        $this->filePath = $this->analyzed->filePath();
        $this->namespacePath = LaravelTsPublish::namespaceToPath($this->findable);

        return $this;
    }

    /**
     * Resolve and store the Echo broadcast event string.
     */
    protected function transformBroadcastName(): self
    {
        $this->broadcastName = $this->resolveBroadcastName($this->analyzed);

        return $this;
    }

    /**
     * Resolve and store the payload property map.
     */
    protected function transformProperties(): self
    {
        $this->properties = $this->resolveProperties($this->analyzed);

        return $this;
    }

    /**
     * Parse #[TsExtends] attribute extends clauses and their import entries from the event class.
     */
    protected function parseTsExtends(): self
    {
        $result = $this->parseTsExtendsFromReflection($this->reflection, 'broadcast_events');

        $this->tsExtends = $result['extends'];
        $this->tsExtendsImports = $result['imports'];

        return $this;
    }

    /**
     * Parse #[TsCasts] attribute overrides from the event class.
     */
    protected function parseTsCasts(): self
    {
        $result = $this->parseTsCastsFromReflection($this->reflection);

        $this->tsTypeOverrides = $result['overrides'];
        $this->tsCastsImportPaths = $result['importPaths'];
        $this->optionalOverrides = $result['optionalOverrides'];

        return $this;
    }

    #[Override]
    public function filename(): string
    {
        return $this->eventName;
    }

    #[Override]
    public function data(): TsBroadcastEventDto
    {
        return new TsBroadcastEventDto(
            eventName: $this->eventName,
            broadcastName: $this->broadcastName,
            fqcn: $this->fqcn(),
            description: '@see '.$this->fqcn(),
            filename: $this->filename(),
            namespacePath: $this->namespacePath,
            properties: $this->properties,
            typeImports: $this->typeImports,
            tsExtends: $this->tsExtends,
        );
    }

    /**
     * Resolve the Echo broadcast event string.
     */
    protected function resolveBroadcastName(ClassResult $analyzed): string
    {
        if ($analyzed->hasMethod('broadcastAs')) {
            $returnType = $analyzed->getMethod('broadcastAs')->returnType();

            if ($returnType instanceof StringType && $returnType->value !== null && $returnType->value !== '') {
                return $returnType->value;
            }
        }

        return '.'.str_replace('\\', '.', $this->findable);
    }

    /**
     * Resolve the payload properties from broadcastWith() or public constructor props.
     *
     * @return PropertiesList
     */
    protected function resolveProperties(ClassResult $analyzed): array
    {
        $arrayType = $this->resolveArrayType($analyzed);

        /** @var PropertiesList $result */
        $result = [];

        foreach ($arrayType->value as $name => $type) {
            if (! $type instanceof Type) {
                continue;
            }

            $propName = (string) $name;

            if (isset($this->tsTypeOverrides[$propName])) {
                $result[$propName] = [
                    'type' => $this->tsTypeOverrides[$propName],
                    'optional' => $this->optionalOverrides[$propName] ?? $type->isOptional(),
                ];

                continue;
            }

            $result[$propName] = [
                'type' => $this->convertType($type),
                'optional' => $type->isOptional(),
            ];

            $this->propertyFqcns[$propName] = array_values(array_unique($this->collectPropertyFqcns($type)));
        }

        return $result;
    }

    /**
     * Recursively collect enum and model FQCNs from a type tree, keeping FQCNs already seen on other properties.
     *
     * @return list<class-string>
     */
    protected function collectPropertyFqcns(Type $type): array
    {
        if ($type instanceof ClassType) {
            $fqcn = ltrim($type->value, '\\');
            if (enum_exists($fqcn) || (class_exists($fqcn) && is_subclass_of($fqcn, Model::class))) {
                return [$fqcn];
            }

            return [];
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $fqcns = [];

            foreach ($type->types as $inner) {
                if ($inner instanceof Type) {
                    $fqcns = array_merge($fqcns, $this->collectPropertyFqcns($inner));
                }
            }

            return $fqcns;
        }

        if ($type instanceof ArrayType) {
            $fqcns = [];

            foreach ($type->value as $inner) {
                if ($inner instanceof Type) {
                    $fqcns = array_merge($fqcns, $this->collectPropertyFqcns($inner));
                }
            }

            return $fqcns;
        }

        return [];
    }

    /**
     * Get an ArrayType representing the event payload.
     */
    protected function resolveArrayType(ClassResult $analyzed): ArrayType
    {
        if ($analyzed->hasMethod('broadcastWith')) {
            $returnType = $analyzed->getMethod('broadcastWith')->returnType();

            if ($returnType instanceof ArrayType) {
                return $returnType;
            }
        }

        return new ArrayType(
            collect($analyzed->publicProperties())
                ->mapWithKeys(fn ($prop) => [$prop->name => $prop->type])
                ->all(),
        );
    }

    /**
     * Convert a Surveyor type to a TypeScript string, tracking any enums and models it references.
     */
    protected function convertType(Type $type): string
    {
        if ($type instanceof ClassType) {
            return $this->convertClassType($type);
        }

        if ($type instanceof UnionType) {
            /** @var array<array-key, mixed> $types */
            $types = $type->types;
            $parts = array_map(
                fn (mixed $t): string => $t instanceof Type ? $this->convertType($t) : 'unknown',
                $types,
            );

            return implode(' | ', $parts);
        }

        if ($type instanceof IntersectionType) {
            /** @var array<array-key, mixed> $types */
            $types = $type->types;
            $parts = array_map(
                fn (mixed $t): string => $t instanceof Type ? $this->convertType($t) : 'unknown',
                $types,
            );

            return implode(' & ', $parts);
        }

        return SurveyorTypeMapper::convert($type);
    }

    /**
     * Convert a ClassType, mapping PHP enums to '{Name}Type' and Eloquent models to 'Partial<Name>'.
     */
    protected function convertClassType(ClassType $type): string
    {
        $fqcn = ltrim($type->value, '\\');
        $nullSuffix = $type->isNullable() ? ' | null' : '';

        if (enum_exists($fqcn)) {
            $typeName = class_basename($fqcn).'Type';
            /** @var class-string<UnitEnum> $fqcn */
            $this->enumFqcnMap[$fqcn] = $typeName;

            return $typeName.$nullSuffix;
        }

        if (class_exists($fqcn) && is_subclass_of($fqcn, Model::class)) {
            $typeName = class_basename($fqcn);
            /** @var class-string<Model> $fqcn */
            $this->modelFqcnMap[$fqcn] = $typeName;

            return 'Partial<'.$typeName.'>'.$nullSuffix;
        }

        return SurveyorTypeMapper::convert($type);
    }

    /**
     * Detect conflicting import names and alias them by namespace prefix (App\Models\User → AppUser).
     */
    protected function resolveImportConflicts(): self
    {
        /** @var array<string, list<array{fqcn: string, kind: 'enum'|'model'}>> $reverseMap */
        $reverseMap = [];

        foreach ($this->enumFqcnMap as $fqcn => $typeName) {
            $reverseMap[$typeName][] = ['fqcn' => $fqcn, 'kind' => 'enum'];
        }

        foreach ($this->modelFqcnMap as $fqcn => $typeName) {
            $reverseMap[$typeName][] = ['fqcn' => $fqcn, 'kind' => 'model'];
        }

        foreach ($reverseMap as $entries) {
            if (count($entries) <= 1) {
                continue;
            }

            foreach ($entries as $entry) {
                $fqcn = $entry['fqcn'];
                $originalName = $entry['kind'] === 'enum'
                    ? $this->enumFqcnMap[$fqcn]
                    : $this->modelFqcnMap[$fqcn];

                $this->importAliases[$fqcn] =
                    $this->computeNamespacePrefix($fqcn, ['Events', 'Enums', 'Models']).$originalName;
            }
        }

        if ($this->importAliases !== []) {
            $this->rewriteTypeReferences();
        }

        return $this;
    }

    /**
     * Rewrite property type references to use aliases.
     */
    protected function rewriteTypeReferences(): void
    {
        foreach ($this->importAliases as $fqcn => $alias) {
            $originalName = $this->enumFqcnMap[$fqcn] ?? $this->modelFqcnMap[$fqcn] ?? null;

            if ($originalName === null || $originalName === $alias) {
                continue;
            }

            $pattern = '/(?<![A-Za-z0-9_$])'.preg_quote($originalName, '/').'(?![A-Za-z0-9_$])/';

            foreach ($this->properties as $key => $entry) {
                if (! in_array($fqcn, $this->propertyFqcns[$key] ?? [], true)) {
                    continue;
                }

                $this->properties[$key]['type'] =
                    preg_replace($pattern, $alias, $entry['type'], 1) ?? $entry['type'];
            }
        }
    }

    /**
     * Build the TypeScript type import map from tracked model and enum FQCNs.
     */
    protected function buildTypeImports(): self
    {
        /** @var TypesImportMap $imports */
        $imports = [];

        foreach ($this->modelFqcnMap as $fqcn => $typeName) {
            $targetPath = LaravelTsPublish::namespaceToPath($fqcn);
            $importPath = LaravelTsPublish::relativeImportPath($this->namespacePath, $targetPath);
            $imports[$importPath][] = $this->formatImportName($fqcn, $typeName);
        }

        foreach ($this->enumFqcnMap as $fqcn => $typeName) {
            $targetPath = LaravelTsPublish::namespaceToPath($fqcn);
            $importPath = LaravelTsPublish::relativeImportPath($this->namespacePath, $targetPath);
            $imports[$importPath][] = $this->formatImportName($fqcn, $typeName);
        }

        foreach ($this->tsCastsImportPaths as $property => $importPath) {
            $type = $this->tsTypeOverrides[$property] ?? null;

            if ($type !== null) {
                foreach (LaravelTsPublish::extractImportableTypes($type) as $importName) {
                    $imports[$importPath][] = $importName;
                }
            }
        }

        foreach ($this->tsExtendsImports as $importPath => $typeNames) {
            foreach ($typeNames as $typeName) {
                $imports[$importPath][] = $typeName;
            }
        }

        foreach ($imports as $path => $types) {
            $unique = array_values(array_unique($types));
            sort($unique);
            $imports[$path] = $unique;
        }

        $this->typeImports = LaravelTsPublish::sortImportPaths($imports);

        return $this;
    }

    /**
     * Build a map of import aliases to their globally-qualified names.
     *
     * @return array<string, string> alias => 'dot.separated.namespace.TypeName'
     */
    public function globalAliasMap(): array
    {
        $map = [];

        foreach ($this->importAliases as $fqcn => $alias) {
            if (isset($this->enumFqcnMap[$fqcn])) {
                $ns = str_replace('/', '.', LaravelTsPublish::namespaceToPath($fqcn));
                $map[$alias] = $ns.'.'.$this->enumFqcnMap[$fqcn];
            } elseif (isset($this->modelFqcnMap[$fqcn])) {
                $ns = str_replace('/', '.', LaravelTsPublish::namespaceToPath($fqcn));
                $map[$alias] = $ns.'.'.$this->modelFqcnMap[$fqcn];
            }
        }

        return $map;
    }

    /**
     * Map every type token appearing in this event's properties to its globally-qualified name.
     *
     * Name-based qualification alone is ambiguous when one short name exists in several namespaces
     * (App\Models\User vs Crm\Models\User), so GlobalsWriter resolves through this map instead.
     *
     * @return array<string, string> typeName|alias => 'dot.separated.namespace.TypeName'
     */
    public function globalTypeReferenceMap(): array
    {
        $map = [];

        foreach ($this->enumFqcnMap as $fqcn => $typeName) {
            $key = $this->importAliases[$fqcn] ?? $typeName;
            $ns = str_replace('/', '.', LaravelTsPublish::namespaceToPath($fqcn));
            $map[$key] = $ns.'.'.$typeName;
        }

        foreach ($this->modelFqcnMap as $fqcn => $typeName) {
            $key = $this->importAliases[$fqcn] ?? $typeName;
            $ns = str_replace('/', '.', LaravelTsPublish::namespaceToPath($fqcn));
            $map[$key] = $ns.'.'.$typeName;
        }

        return $map;
    }
}
