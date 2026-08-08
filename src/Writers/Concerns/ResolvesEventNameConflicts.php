<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Writers\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Aliases broadcast events whose PHP short class names collide, keeping TypeScript identifiers unique.
 */
trait ResolvesEventNameConflicts
{
    /**
     * Resolve conflicts when two events share the same short class name.
     *
     * Each event must carry at least an `eventName` and a `namespacePath` key.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return Collection<int, array<string, mixed>>
     */
    protected function resolveEventNameConflicts(Collection $events): Collection
    {
        $byName = $events->groupBy('eventName');

        return $events->map(function (array $event) use ($byName): array {
            /** @var string $eventName */
            $eventName = $event['eventName'];

            if (($byName->get($eventName)?->count() ?? 1) <= 1) {
                /** @var array<string, mixed> $entry */
                $entry = [...$event, 'importedAs' => $eventName, 'exportedName' => $eventName];

                return $entry;
            }

            /** @var string $namespacePath */
            $namespacePath = $event['namespacePath'];
            $alias = $this->computeEventAlias($namespacePath, $eventName);

            /** @var array<string, mixed> $entry */
            $entry = [
                ...$event,
                ...$this->extraConflictFields($event, $alias),
                'importedAs' => $eventName.' as '.$alias,
                'exportedName' => $alias,
            ];

            return $entry;
        });
    }

    /**
     * Return extra array fields to include when an event name conflict is resolved.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    protected function extraConflictFields(array $event, string $alias): array
    {
        return [];
    }

    /**
     * Compute a unique alias for an event using its namespace path as a discriminator.
     *
     * Namespace 'crm/events' and event 'UserSynced' give 'CrmUserSynced'.
     */
    private function computeEventAlias(string $namespacePath, string $eventName): string
    {
        $segments = array_values(array_filter(explode('/', $namespacePath)));
        $prefixSegments = array_slice($segments, 0, -1);
        $skip = ['events'];

        foreach (array_reverse($prefixSegments) as $segment) {
            if (! in_array($segment, $skip, true)) {
                return Str::studly($segment).$eventName;
            }
        }

        $first = reset($prefixSegments);

        return ($first !== false ? Str::studly($first) : '').$eventName;
    }
}
