<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Tests\TestCase;
use Closure;
use Override;

/**
 * EntryPreviewsBehaviour against the fake the entry.preview action's tests use, over the
 * EntryListingWorld as each reader's context reads it.
 */
final class FakeEntryPreviewsBehaviourTest extends TestCase
{
    use EntryPreviewsBehaviour;

    #[Override]
    protected function previewAs(string $reader, Closure $read): void
    {
        $read(EntryListingWorld::previews($reader));
    }
}
