<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Ast\AstParser;
use AbeTwoThree\LaravelTsPublish\Ast\MethodLocator;
use Workbench\App\Http\Resources\UserResource;
use Workbench\App\Models\User;

it('locates a method declared in the class own file', function () {
    $locator = new MethodLocator(new AstParser);

    $context = $locator->locateOwn(UserResource::class, 'toArray');

    expect($context)->not->toBeNull()
        ->and($context->method->name->toString())->toBe('toArray')
        ->and($context->reflection->getName())->toBe(UserResource::class);
});

it('locateOwn misses an inherited method, locate finds it in the declaring file', function () {
    $locator = new MethodLocator(new AstParser);

    // User inherits save() from Eloquent's Model; it is not declared in User's own file.
    expect($locator->locateOwn(User::class, 'save'))->toBeNull()
        ->and($locator->locate(User::class, 'save'))->not->toBeNull();
});

it('locate matches method names case-insensitively, like PHP dispatch', function () {
    $locator = new MethodLocator(new AstParser);

    expect($locator->locate(UserResource::class, 'TOARRAY'))->not->toBeNull();
});

it('returns null for a missing class or method', function () {
    $locator = new MethodLocator(new AstParser);

    expect($locator->locateOwn('Not\A\Class', 'x'))->toBeNull()
        ->and($locator->locateOwn(User::class, 'notAMethod'))->toBeNull();
});

it('memoizes a miss so a repeated lookup never re-parses the file', function () {
    // A spy AstParser counts parseFile() calls so we can prove the second lookup skips parsing entirely,
    // not merely that it returns an equal result.
    $parser = new class extends AstParser
    {
        public int $calls = 0;

        public function parseFile(string $path): array
        {
            $this->calls++;

            return parent::parseFile($path);
        }
    };
    $locator = new MethodLocator($parser);

    expect($locator->locateOwn(User::class, 'save'))->toBeNull();

    $callsAfterFirstMiss = $parser->calls;

    expect($callsAfterFirstMiss)->toBeGreaterThan(0)
        ->and($locator->locateOwn(User::class, 'save'))->toBeNull()
        ->and($parser->calls)->toBe($callsAfterFirstMiss);
});
