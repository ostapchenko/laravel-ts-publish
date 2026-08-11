<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Support\ImportNameRegistry;

describe('ImportNameRegistry', function () {
    test('non-colliding names pass through unaliased', function () {
        $registry = new ImportNameRegistry;
        $registry->register('App\Models\User', 'User');
        $registry->register('App\Models\Team', 'Team');

        expect($registry->resolve())->toBe([
            'App\Models\User' => 'User',
            'App\Models\Team' => 'Team',
        ]);
    });

    test('same basename from different namespaces gets namespace-prefixed aliases', function () {
        $registry = new ImportNameRegistry;
        $registry->register('App\Sales\Models\Report', 'Report');
        $registry->register('App\Marketing\Models\Report', 'Report');

        expect($registry->resolve())->toBe([
            'App\Sales\Models\Report' => 'SalesReport',
            'App\Marketing\Models\Report' => 'MarketingReport',
        ]);
    });

    test('colliding one-segment prefixes extend until unique (the MailPrice bug)', function () {
        $registry = new ImportNameRegistry;
        $registry->register('Eagle\Customer\Engineering\MailPrice\Models\MailPrice', 'MailPrice');
        $registry->register('Eagle\Engineering\MailPrice\Models\MailPrice', 'MailPrice');

        $resolved = $registry->resolve();

        // Depth 1 ('MailPriceMailPrice') and depth 2 ('EngineeringMailPriceMailPrice')
        // collide for BOTH; the whole group advances to depth 3 together, so neither
        // keeps an ambiguous shallow alias.
        expect($resolved['Eagle\Customer\Engineering\MailPrice\Models\MailPrice'])
            ->toBe('CustomerEngineeringMailPriceMailPrice')
            ->and($resolved['Eagle\Engineering\MailPrice\Models\MailPrice'])
            ->toBe('EagleEngineeringMailPriceMailPrice')
            ->and(array_unique(array_values($resolved)))->toHaveCount(2);
        // In eagle itself, namespace_strip_prefix ('Eagle') removes the Eagle segment,
        // yielding EngineeringMailPriceMailPrice for the second model.
    });

    test('identical namespaces fall back to numeric suffixes', function () {
        $registry = new ImportNameRegistry;
        $registry->register('A\B\Thing', 'Thing');
        $registry->register('A\B\ThingAlias', 'Thing'); // same TS name, same prefix path

        $resolved = $registry->resolve();

        expect(array_unique(array_values($resolved)))->toHaveCount(2);
    });

    test('a reserved name forces the import to alias', function () {
        $registry = new ImportNameRegistry;
        $registry->reserve('Order');
        $registry->register('App\Models\Order', 'Order');

        expect($registry->resolve()['App\Models\Order'])->toBe('ModelsOrder');
    });

    test('preferred alias wins when unique and falls back when it collides', function () {
        $registry = new ImportNameRegistry;
        $registry->register('App\Models\A\User', 'User', preferredAlias: 'OwnerUser');
        $registry->register('App\Models\B\User', 'User', preferredAlias: 'OwnerUser');

        $resolved = $registry->resolve();

        expect(array_unique(array_values($resolved)))->toHaveCount(2)
            ->and($resolved)->not->toContain('OwnerUser');
        // Both preferred aliases collide, so both fall back to namespace prefixes.
    });

    test('an alias never collides with an unaliased import name', function () {
        $registry = new ImportNameRegistry;
        $registry->register('App\Models\SalesReport', 'SalesReport');
        $registry->register('App\Sales\Models\Report', 'Report');
        $registry->register('App\Marketing\Models\Report', 'Report');

        $resolved = $registry->resolve();

        // Sales\Report's depth-1 alias would be 'SalesReport' — taken by a real import.
        expect(array_unique(array_values($resolved)))->toHaveCount(3);
    });

    test('a given FQCN gets the same alias regardless of registration order', function () {
        $a = new ImportNameRegistry;
        $a->register('Eagle\Customer\Engineering\MailPrice\Models\MailPrice', 'MailPrice');
        $a->register('Eagle\Engineering\MailPrice\Models\MailPrice', 'MailPrice');

        $b = new ImportNameRegistry;
        $b->register('Eagle\Engineering\MailPrice\Models\MailPrice', 'MailPrice');
        $b->register('Eagle\Customer\Engineering\MailPrice\Models\MailPrice', 'MailPrice');

        foreach ($a->resolve() as $fqcn => $alias) {
            expect($b->resolve()[$fqcn])->toBe($alias);
        }
    });
});
