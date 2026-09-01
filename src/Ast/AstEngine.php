<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Ast;

use AbeTwoThree\LaravelTsPublish\Analyzers\ResourceAstAnalyzer;
use AbeTwoThree\LaravelTsPublish\Ast\Concerns\DispatchesFqcnResults;
use AbeTwoThree\LaravelTsPublish\Facades\LaravelTsPublish;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use ReflectionProperty;

/**
 * Public entry point: analyze any class method (or constructor) into a MethodAnalysis DTO.
 */
final class AstEngine
{
    use DispatchesFqcnResults;

    /**
     * Analyze a method body's return shape. Resources get full resource semantics ('toArray'
     * default); any other class/method runs the same engine with the same handlers.
     *
     * @param  class-string  $class
     * @param  class-string<Model>|null  $modelClass  Backing model for `$this->prop` resolution; null to skip.
     */
    public function analyzeMethod(string $class, string $method = 'toArray', ?string $modelClass = null): MethodAnalysis
    {
        $reflection = new ReflectionClass($class);

        if ($modelClass === null && is_a($class, JsonResource::class, true)) {
            $modelClass = resolve(ModelClassResolver::class)->resolve($reflection);
        }

        return new ResourceAstAnalyzer($reflection, $modelClass, $method)->analyze();
    }

    /**
     * Analyze a class's public properties — promoted constructor params AND class-body declarations,
     * `@var` docblock first, native type second — into properties + enum/model FQCN channels.
     * Never marks a property optional: nullability is `| null`, optionality is a #[TsCasts] concern.
     *
     * @param  class-string  $class
     */
    public function analyzePublicProperties(string $class): MethodAnalysis
    {
        $reflection = new ReflectionClass($class);
        $traitProperties = $this->traitPropertyNames($reflection);
        $reader = resolve(PropertyDocblockTypeReader::class);
        $acceptor = resolve(ReflectedTypeAcceptor::class);
        $analysis = new MethodAnalysis;

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if (in_array($name, $traitProperties, true)) {
                continue;
            }

            $result = $reader->read($property)
                ?? $acceptor->accept(LaravelTsPublish::propertyTypes($reflection, $name))
                ?? ValueResult::unknown();

            $analysis->properties[] = [
                'name' => $name,
                'type' => $result['type'],
                'optional' => false,
                'description' => '',
            ];

            $this->dispatchFqcnResults(
                $name,
                $result,
                $analysis->enumResources,
                $analysis->directEnumFqcns,
                $analysis->nestedResources,
                $analysis->modelFqcns,
                $analysis->multiEnumResourceFqcns,
            );

            foreach ($result['customImports'] ?? [] as $path => $types) {
                $analysis->customImports[$path] = [...($analysis->customImports[$path] ?? []), ...$types];
            }
        }

        return $analysis;
    }

    /**
     * Names of every property a used trait declares, transitively.
     *
     * Trait properties are reflected as the using class's own, so only the name distinguishes them;
     * a #[TsExtends] trait already supplies its fields, and emitting them again duplicates a field.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return list<string>
     */
    private function traitPropertyNames(ReflectionClass $reflection): array
    {
        $names = [];
        $pending = $reflection->getTraits();

        while ($pending !== []) {
            $trait = array_shift($pending);

            foreach ($trait->getProperties() as $property) {
                $names[] = $property->getName();
            }

            $pending = [...$pending, ...$trait->getTraits()];
        }

        return array_values(array_unique($names));
    }
}
