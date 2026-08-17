<?php

declare(strict_types=1);

namespace Workbench\App\Services;

/**
 * Class constants referenced from ClassConstantResource (Task 17A): resolveClassConstantValueExpression()
 * reads a constant's value via reflection and feeds it back through analyzeConstantValue(), which
 * recurses into arrays and reuses analyzeValueExpression()'s dispatch for scalar leaves.
 */
class ChannelDefaults
{
    /**
     * The eaglesys OWNER_MINIMUM_CHANNELS shape: a nested array constant.
     *
     * @var array<string, array<string, bool>>
     */
    public const array DEFAULT_CHANNELS = [
        'in_app' => ['status_updates' => true, 'comments' => true],
        'digest' => ['status_updates' => true, 'comments' => false],
    ];

    public const int MAX_RETRIES = 3;

    /**
     * A plain sequential list — every element agrees, so this resolves to `string[]`.
     *
     * @var list<string>
     */
    public const array CHANNEL_TAGS = ['in_app', 'digest', 'email'];

    /**
     * A list whose elements don't agree — resolves to a union element array.
     *
     * @var list<string|int>
     */
    public const array MIXED_TAGS = ['in_app', 1];

    /**
     * A list nested inside a keyed constant — each value resolves to `string[]`, not the
     * `Record<string, unknown>` a keyless item would misreport through the AST pipeline.
     *
     * @var array<string, list<string>>
     */
    public const array NESTED_TAGS = [
        'primary' => ['red', 'green'],
        'secondary' => ['blue'],
    ];

    /**
     * References an undefined global constant — PHP evaluates this lazily, so it only throws when
     * getValue() is actually called. Must degrade to unknown, not abort the whole generation run.
     *
     * @var array<int, mixed>
     */
    public const array BROKEN = [1 => CHANNEL_DEFAULTS_UNDEFINED_CONSTANT];

    /**
     * 201 flat elements — one past MAX_CONSTANT_ARRAY_ELEMENTS. Must degrade to unknown.
     *
     * @var list<int>
     */
    public const array OVER_ELEMENT_LIMIT = [
        1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
        21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40,
        41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60,
        61, 62, 63, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75, 76, 77, 78, 79, 80,
        81, 82, 83, 84, 85, 86, 87, 88, 89, 90, 91, 92, 93, 94, 95, 96, 97, 98, 99, 100,
        101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113, 114, 115, 116, 117, 118, 119, 120,
        121, 122, 123, 124, 125, 126, 127, 128, 129, 130, 131, 132, 133, 134, 135, 136, 137, 138, 139, 140,
        141, 142, 143, 144, 145, 146, 147, 148, 149, 150, 151, 152, 153, 154, 155, 156, 157, 158, 159, 160,
        161, 162, 163, 164, 165, 166, 167, 168, 169, 170, 171, 172, 173, 174, 175, 176, 177, 178, 179, 180,
        181, 182, 183, 184, 185, 186, 187, 188, 189, 190, 191, 192, 193, 194, 195, 196, 197, 198, 199, 200,
        201,
    ];

    /**
     * Nested 6 levels deep — one past MAX_CONSTANT_ARRAY_DEPTH. Must degrade to unknown.
     *
     * @var array<string, mixed>
     */
    public const array OVER_DEPTH_LIMIT = [
        'a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too deep']]]]],
    ];
}
