<?php

declare(strict_types=1);

namespace AbeTwoThree\LaravelTsPublish\Analyzers\FormRequest;

/**
 * Internal dot-path trie node for FormRequestRulesAnalyzer's rule composition — not part of the public API.
 *
 * A plain class, not a recursive `@phpstan-type` array shape: PHPStan reports "Circular definition
 * detected in type alias" for the recursive alias form.
 *
 * @phpstan-import-type RuleLeafData from FormRequestRulesAnalyzer
 */
final class FormRequestRuleTrieNode
{
    /**
     * The rule declared at exactly this path, or null for an ancestor reached only en route
     * to a deeper path (e.g. `order` when just `order.id` was declared).
     *
     * @var RuleLeafData|null
     */
    public ?array $own = null;

    /**
     * Child nodes keyed by dot-segment; a `*` key marks "array of this node".
     *
     * @var array<string, FormRequestRuleTrieNode>
     */
    public array $children = [];
}
