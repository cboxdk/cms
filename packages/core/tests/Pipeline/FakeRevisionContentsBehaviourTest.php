<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Pipeline\Domain\RevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * RevisionContentsBehaviour against the fake the release tests use.
 */
final class FakeRevisionContentsBehaviourTest extends TestCase
{
    use RevisionContentsBehaviour;

    #[Override]
    protected function revisionContents(): RevisionContents
    {
        $entry = EntryId::fromString(self::ENTRY);

        return new FakeRevisionContents()
            ->with($entry, VariantKey::shared(), RevisionNumber::first(), 3, self::noteFields())
            ->with($entry, VariantKey::shared(), new RevisionNumber(2), 2, self::noteFields())
            ->snapshot(EntryId::fromString(self::SNAPSHOT_ENTRY), VariantKey::shared(), new RevisionNumber(4), 3, self::noteFields());
    }
}
