<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Tests\TestCase;
use Closure;
use Override;

/**
 * EntryListingBehaviour against the fake the entry read actions' tests use, over the
 * EntryListingWorld as each reader's context reads it.
 */
final class FakeEntryListingBehaviourTest extends TestCase
{
    use EntryListingBehaviour;

    #[Override]
    protected function listAs(string $reader, Closure $read): void
    {
        $read(EntryListingWorld::listing($reader));
    }
}
