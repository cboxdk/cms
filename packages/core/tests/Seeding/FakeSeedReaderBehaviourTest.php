<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Seeding\Domain\SeedReader;
use Cbox\Cms\Core\Tests\Seeding\Fakes\FakeSeedReader;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * SeedReaderBehaviour against the fake the seeder's action tests use.
 */
final class FakeSeedReaderBehaviourTest extends TestCase
{
    use SeedReaderBehaviour;

    #[Override]
    protected function seedReader(): SeedReader
    {
        return new FakeSeedReader()
            ->withNode(NodeId::fromString(self::NODE), new AggregateVersion(3))
            ->withEntry(EntryId::fromString(self::ENTRY))
            ->withEntry(EntryId::fromString(self::OTHER_ENTRY));
    }
}
