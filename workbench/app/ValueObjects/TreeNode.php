<?php

declare(strict_types=1);

namespace Workbench\App\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A self-referential Arrayable DTO: end-to-end guard that shape expansion cannot recurse forever.
 *
 * @implements Arrayable<string, mixed>
 */
class TreeNode implements Arrayable
{
    /** @return array{label: string, child: TreeNode} */
    public function toArray(): array
    {
        return [];
    }
}
