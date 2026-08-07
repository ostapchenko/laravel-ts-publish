<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Listeners;

use AbeTwoThree\LaravelTsPublish\Commands\TsPublishCommand;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Console\Kernel as Artisan;

class PostMigrateRunner
{
    public static bool $shouldRun = false;

    public function __construct(protected Artisan $artisan) {}

    /**
     * Handle the event.
     */
    public function handle(CommandFinished $event): void
    {
        if (! self::$shouldRun) {
            return;
        }

        self::$shouldRun = false;

        // The database schema has no source file, so it is not part of the cache fingerprint.
        // --fresh is the only way the post-migration republish sees the new schema.
        $this->artisan->call(TsPublishCommand::class, ['--fresh' => true], $event->output);
    }
}
