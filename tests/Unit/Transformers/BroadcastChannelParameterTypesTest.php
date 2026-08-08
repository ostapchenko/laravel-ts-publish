<?php

declare(strict_types=1);

use AbeTwoThree\LaravelTsPublish\Collectors\BroadcastChannelsCollector;
use AbeTwoThree\LaravelTsPublish\Transformers\BroadcastChannelsTransformer;

/**
 * Laravel resolves join() parameters at runtime (route model binding, enum coercion), but the
 * transformer only ever sees the channel name string, so every wildcard is `string | number`.
 */
describe('BroadcastChannelsTransformer — class-based channel parameter types', function () {

    /** PublicAnnouncementsChannel::join(User $user) — no wildcards. */
    describe('no parameters — static channel name', function () {
        it('produces a plain template literal const entry without a function wrapper', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['public-announcements']));

            expect($dto->constBody)
                ->toContain('"public-announcements": `public-announcements` as const');
        });

        it('produces a single-member type union', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['public-announcements']));

            expect($dto->typeUnion)
                ->toBe('export type BroadcastChannel = `public-announcements`;');
        });
    });

    /** OrderChannel::join(User $user, Order $order) — Laravel binds {orderId} to an Order model. */
    describe('1 model parameter — join(User $user, Order $order)', function () {
        it('produces a function wrapper with string|number param regardless of model binding', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['order.{orderId}']));

            expect($dto->constBody)
                ->toContain('order: (orderId: string | number) => `order.${orderId}` as const');
        });

        it('produces a template literal union type for the wildcard', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['order.{orderId}']));

            expect($dto->typeUnion)
                ->toContain('`order.${string | number}`');
        });
    });

    /** PostCommentChannel::join(User $user, Post $post, Comment $comment) — both wildcards bind to models. */
    describe('2 model parameters — join(User $user, Post $post, Comment $comment)', function () {
        it('produces nested function wrappers for each model wildcard', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['post.{postId}.comment.{commentId}']));

            expect($dto->constBody)
                ->toContain('post: (postId: string | number) => ({')
                ->toContain('comment: (commentId: string | number) => `post.${postId}.comment.${commentId}` as const');
        });

        it('produces a template literal union type with both model wildcards', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['post.{postId}.comment.{commentId}']));

            expect($dto->typeUnion)
                ->toContain('`post.${string | number}.comment.${string | number}`');
        });
    });

    /** OrderStatusChannel::join(User $user, Status $status) — {statusId} is coerced to an int-backed enum. */
    describe('int-backed enum parameter — join(User $user, Status $status)', function () {
        it('produces a function wrapper with string|number param, not int', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['order-status.{statusId}']));

            expect($dto->constBody)
                ->toContain('"order-status": (statusId: string | number) => `order-status.${statusId}` as const');
        });

        it('uses a quoted key because "order-status" contains a hyphen', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['order-status.{statusId}']));

            expect($dto->constBody)->toContain('"order-status":');
        });

        it('produces a template literal union type for the int-backed enum wildcard', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['order-status.{statusId}']));

            expect($dto->typeUnion)
                ->toContain('`order-status.${string | number}`');
        });
    });

    /** ColorThemeChannel::join(User $user, Color $color) — {colorId} is coerced to a string-backed enum. */
    describe('string-backed enum parameter — join(User $user, Color $color)', function () {
        it('produces a function wrapper with string|number param, not string', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['color-theme.{colorId}']));

            expect($dto->constBody)
                ->toContain('"color-theme": (colorId: string | number) => `color-theme.${colorId}` as const');
        });

        it('produces a template literal union type for the string-backed enum wildcard', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['color-theme.{colorId}']));

            expect($dto->typeUnion)
                ->toContain('`color-theme.${string | number}`');
        });
    });

    /** RoleDashboardChannel::join(User $user, Role $role) — {roleId} matches a pure enum by case name. */
    describe('non-backed enum parameter — join(User $user, Role $role)', function () {
        it('produces a function wrapper with string|number param for a pure enum wildcard', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['role-dashboard.{roleId}']));

            expect($dto->constBody)
                ->toContain('"role-dashboard": (roleId: string | number) => `role-dashboard.${roleId}` as const');
        });

        it('produces a template literal union type for the non-backed enum wildcard', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['role-dashboard.{roleId}']));

            expect($dto->typeUnion)
                ->toContain('`role-dashboard.${string | number}`');
        });
    });

    /** TeamRoomChannel::join(User $user, int $teamId, string $roomName) — both wildcards are primitives. */
    describe('primitive parameters — join(User $user, int $teamId, string $roomName)', function () {
        it('produces nested function wrappers for int and string primitives', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['teams.{teamId}.rooms.{roomName}']));

            expect($dto->constBody)
                ->toContain('teams: (teamId: string | number) => ({')
                ->toContain('rooms: (roomName: string | number) => `teams.${teamId}.rooms.${roomName}` as const');
        });

        it('produces a template literal union type for both primitive wildcards', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect(['teams.{teamId}.rooms.{roomName}']));

            expect($dto->typeUnion)
                ->toContain('`teams.${string | number}.rooms.${string | number}`');
        });
    });

    /** All 10 workbench channels together. */
    describe('full workbench fixture with all class-based channel types', function () {
        it('collects all 10 workbench channels including the 6 new class-based ones', function () {
            $collector = resolve(BroadcastChannelsCollector::class);
            $channels = $collector->collect();

            expect($channels)->toContain('orders.{orderId}')
                ->and($channels)->toContain('user.{userId}.notifications')
                ->and($channels)->toContain('chat.{roomId}.messages')
                ->and($channels)->toContain('public-announcements');

            expect($channels)->toContain('order.{orderId}')
                ->and($channels)->toContain('post.{postId}.comment.{commentId}')
                ->and($channels)->toContain('order-status.{statusId}')
                ->and($channels)->toContain('color-theme.{colorId}')
                ->and($channels)->toContain('role-dashboard.{roleId}')
                ->and($channels)->toContain('teams.{teamId}.rooms.{roomName}');
        });

        it('produces identical TypeScript wildcard types regardless of join() parameter type', function () {
            $transformer = new BroadcastChannelsTransformer;
            $dto = $transformer->transform(collect([
                'order.{orderId}',           // Model
                'post.{postId}.comment.{commentId}', // 2 Models
                'order-status.{statusId}',   // Int-backed enum
                'color-theme.{colorId}',     // String-backed enum
                'role-dashboard.{roleId}',   // Non-backed enum
                'teams.{teamId}.rooms.{roomName}', // Primitives
            ]));

            $typeUnion = $dto->typeUnion;
            expect(substr_count($typeUnion, 'string | number'))->toBeGreaterThanOrEqual(8);
        });
    });
});
