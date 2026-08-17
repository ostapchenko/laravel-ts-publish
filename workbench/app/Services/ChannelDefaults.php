<?php

declare(strict_types=1);

namespace Workbench\App\Services;

/**
 * Class constants referenced from ClassConstantResource (Task 17A): resolveClassConstantValueExpression()
 * reads a constant's value via reflection and feeds it back through analyzeValueExpression() as a
 * synthetic AST node, rather than a second value-to-TS mapper.
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
     * A plain sequential list — analyzeInlineArray() shapes record-style arrays keyed by name, so
     * a list has nothing to key properties from. Must stay unknown rather than misreport as {}.
     *
     * @var list<string>
     */
    public const array CHANNEL_TAGS = ['in_app', 'digest', 'email'];
}
