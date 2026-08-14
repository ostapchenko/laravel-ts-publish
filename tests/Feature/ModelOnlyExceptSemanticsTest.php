<?php

declare(strict_types=1);

use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * Ground-truth check for what HasAttributes::only()/except() actually return at runtime, so
 * ResourceAstAnalyzer's Pick<>/Omit<> optimization targets real Eloquent semantics, not a guess.
 *
 * Confirmed below: except() returns real DB columns minus excluded keys, never a relation or unbacked accessor.
 */
function createPersistedPost(): Post
{
    $user = User::create([
        'name' => 'Ground Truth',
        'email' => 'ground-truth-'.uniqid().'@example.com',
        'password' => bcrypt('secret'),
    ]);

    $post = Post::create([
        'title' => 'Empirical Post',
        'content' => 'Body text',
        'user_id' => $user->id,
    ]);

    Comment::create([
        'content' => 'A comment',
        'post_id' => $post->id,
        'user_id' => $user->id,
    ]);

    // Re-fetch from the database: the realistic JsonResource case ($this->post->except([...])) is a
    // model hydrated from a SELECT, not the in-memory instance returned by create() — which only
    // carries the attributes explicitly passed to create() plus the auto-incremented key, not the
    // column defaults the database filled in (e.g. category's default 'news').
    /** @var Post $fresh */
    $fresh = Post::find($post->id);

    return $fresh;
}

/** @return list<string> */
function postColumnsExceptCreatedUpdated(): array
{
    return [
        'category',
        'category_id',
        'content',
        'deleted_at',
        'featured_image_url',
        'id',
        'is_pinned',
        'metadata',
        'options',
        'priority',
        'published_at',
        'rating',
        'reading_time_minutes',
        'status',
        'title',
        'user_id',
        'visibility',
        'word_count',
    ];
}

test('Model::except() on a DB-fetched instance is exactly the raw database columns', function () {
    $post = createPersistedPost();

    $keys = array_keys($post->except(['created_at', 'updated_at']));
    sort($keys);

    expect($keys)->toBe(postColumnsExceptCreatedUpdated());
});

test('Model::except() still excludes relations and get-only accessors even after they are touched', function () {
    $post = createPersistedPost();

    // Load a relation and touch both get-only accessors before calling except(), so this reflects the
    // worst case (accessors cached, relation loaded) rather than a coincidentally-clean instance.
    // (Post::titleDisplay() is get+set but crashes on a null raw 'title_display' attribute when called
    // directly — it is only ever exercised through static type inference elsewhere in this suite, never
    // at runtime — so it is intentionally left untouched here rather than worked around.)
    $post->load('comments');
    $post->excerpt;
    $post->reading_time;

    $keys = array_keys($post->except(['created_at', 'updated_at']));
    sort($keys);

    // Identical to the untouched case — touching a relation or a get-only accessor first changes
    // nothing about except()'s output.
    expect($keys)->toBe(postColumnsExceptCreatedUpdated());
    expect($keys)->not->toContain('excerpt', 'reading_time', 'author', 'categoryRel', 'comments', 'tags', 'images', 'attachment');
});

test('Model::only() resolves accessors and relations explicitly requested by name', function () {
    // Unlike except(), only() calls getAttribute() per requested key, which does resolve accessors
    // and relations — the analyzer's existing inline-expansion fallback covers this case (a key that
    // isn't a plain column is never routed through Pick<>).
    $post = createPersistedPost();

    $result = $post->only(['id', 'excerpt', 'author']);

    expect($result)->toHaveKeys(['id', 'excerpt', 'author'])
        ->and($result['excerpt'])->toBeString()
        ->and($result['author'])->toBeInstanceOf(User::class);
});
