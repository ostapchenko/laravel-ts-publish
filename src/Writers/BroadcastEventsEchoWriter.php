<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Writers;

use AbeTwoThree\LaravelTsPublish\Generators\BroadcastEventGenerator;
use AbeTwoThree\LaravelTsPublish\Support\PackageJson;
use AbeTwoThree\LaravelTsPublish\Writers\Concerns\EnsuresDirectoryExists;
use AbeTwoThree\LaravelTsPublish\Writers\Concerns\ResolvesEventNameConflicts;
use AbeTwoThree\LaravelTsPublish\Writers\Concerns\WritesGeneratedFiles;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * Writes an echo-broadcast-events.d.ts module augmentation file for Laravel Echo.
 *
 * Augments Echo's 'Events' interface so channel listeners (Echo.listen(), useEcho()) are type-safe.
 *
 * @phpstan-type RawEchoEvent = array{eventName: string, broadcastName: string, importPath: string, namespacePath: string}
 * @phpstan-type EchoEvent = array{eventName: string, broadcastName: string, importPath: string, namespacePath: string, importedAs: string, exportedName: string}
 */
class BroadcastEventsEchoWriter
{
    use EnsuresDirectoryExists;
    use ResolvesEventNameConflicts;
    use WritesGeneratedFiles;

    public function __construct(
        protected Filesystem $filesystem,
    ) {}

    /**
     * Render and optionally write the Echo module augmentation file.
     *
     * @param  Collection<int, BroadcastEventGenerator>  $generators
     */
    public function write(Collection $generators): string
    {
        if (! Config::boolean('ts-publish.broadcast_events.echo_augmentation.enabled')) {
            return '';
        }

        if ($generators->isEmpty()) {
            return '';
        }

        $echoPackage = $this->resolveEchoPackage();

        $content = $this->renderContent($generators, $echoPackage);

        if (Config::boolean('ts-publish.output_to_files')) {
            $this->writeFile($content);
        }

        return $content;
    }

    /**
     * Build the template data and render.
     *
     * @param  Collection<int, BroadcastEventGenerator>  $generators
     */
    protected function renderContent(Collection $generators, string $echoPackage): string
    {
        $rawEvents = $generators->map(function (BroadcastEventGenerator $generator): array {
            $transformer = $generator->transformer;
            $dto = $transformer->data();
            $relativePath = './'.$transformer->namespacePath.'/'.$transformer->filename();

            return [
                'eventName' => $dto->eventName,
                'broadcastName' => $dto->broadcastName,
                'importPath' => $relativePath,
                'namespacePath' => $transformer->namespacePath,
            ];
        })->sortBy('eventName')->values();

        /** @var Collection<int, EchoEvent> $events */
        $events = $this->resolveEventNameConflicts($rawEvents); // @phpstan-ignore argument.type

        $imports = $events->map(
            fn ($event) => "import type { {$event['importedAs']} } from '{$event['importPath']}';"
        )->values();

        /** @var view-string $template */
        $template = Config::string('ts-publish.broadcast_events.echo_augmentation.template');

        return view($template, [
            'echoPackage' => $echoPackage,
            'imports' => $imports->all(),
            'events' => $events->values()->all(),
        ])->render();
    }

    /**
     * Resolve the Echo npm package name for the declare module statement.
     */
    protected function resolveEchoPackage(): string
    {
        $configured = config('ts-publish.broadcast_events.echo_augmentation.echo_package');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return PackageJson::firstInstalled([
            '@laravel/echo-vue',
            '@laravel/echo-react',
            '@laravel/echo-svelte',
        ]) ?? '@laravel/echo';
    }

    /**
     * Write the rendered content to the configured output path.
     */
    protected function writeFile(string $content): void
    {
        $outputPath = $this->resolveOutputPath();
        $filename = Config::string('ts-publish.broadcast_events.echo_augmentation.filename', 'echo-broadcast-events.d.ts');

        $this->ensureDirectoryExists($outputPath);
        $this->putIfChanged("{$outputPath}/{$filename}", $content);
    }

    /**
     * Resolve the output directory for the Echo augmentation file.
     */
    protected function resolveOutputPath(): string
    {
        $echoOutputPath = Config::string('ts-publish.broadcast_events.echo_augmentation.output_directory');
        if (! empty($echoOutputPath)) {
            return $echoOutputPath;
        }

        $eventsOutputPath = Config::string('ts-publish.broadcast_events.output_directory');
        if (! empty($eventsOutputPath)) {
            return $eventsOutputPath;
        }

        return Config::string('ts-publish.output_directory');
    }
}
