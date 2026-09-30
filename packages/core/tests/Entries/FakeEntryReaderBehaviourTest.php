<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Cbox\Cms\Core\Tests\Entries\Fakes\FakeEntryReader;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * EntryReaderBehaviour against the fake the entry actions' tests use. The fake keeps no snapshots
 * apart from revisions, so both kinds of head read the same.
 */
final class FakeEntryReaderBehaviourTest extends TestCase
{
    use EntryReaderBehaviour;

    #[Override]
    protected function entryReader(bool $snapshots = false): EntryReader
    {
        $node = NodeId::fromString(self::NODE);
        $type = TypeId::fromString(self::TYPE);

        return new FakeEntryReader()
            ->withNode($node, new AggregateVersion(3))
            ->withEntry(EntryId::fromString(self::ENTRY), $type, $node, new AggregateVersion(2))
            ->withHead(EntryId::fromString(self::ENTRY), VariantKey::shared(), new StoredHead(new AggregateVersion(5), new RevisionNumber(4)))
            ->withEntry(EntryId::fromString(self::BARE_ENTRY), $type, $node, new AggregateVersion(1));
    }
}
