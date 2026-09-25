<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

/**
 * The domain categories of GUARDRAILS 2.1 and 2.2 that sit below Domain, each in its own
 * namespace segment. Classes in them are final readonly.
 */
enum Category: string
{
    case Commands = 'Commands';
    case Queries = 'Queries';
    case Dto = 'Dto';
    case Receipts = 'Receipts';

    public static function of(string $namespace): ?self
    {
        foreach (array_reverse(explode('\\', $namespace)) as $segment) {
            $category = self::tryFrom($segment);

            if ($category !== null) {
                return $category;
            }
        }

        return null;
    }
}
