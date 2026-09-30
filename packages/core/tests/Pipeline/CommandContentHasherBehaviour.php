<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Pipeline\Domain\CommandContentHasher;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every CommandContentHasher does, run against the CodecCommandContentHasher the core binds
 * and the FakeCommandContentHasher the pipeline's tests use, so the fake cannot drift from the
 * hash the idempotency store keeps (PRD 6.1): equal input under the same name and version gives
 * equal hashes, from two hashers too, and other input, another version or another name gives
 * another hash.
 */
trait CommandContentHasherBehaviour
{
    /** Another command's name for the same input, which the hasher under test must know. */
    public const string OTHER_NAME = 'probe.revise';

    /**
     * A hasher that knows entry.revise versions 1 and 2 and OTHER_NAME version 1, each read as
     * ReviseEntry.
     */
    abstract protected function hasher(): CommandContentHasher;

    #[Test]
    public function it_gives_equal_input_equal_hashes_in_every_hasher(): void
    {
        $name = new CommandName('entry.revise');

        Assert::assertTrue($this->hasher()->hash($name, 1, self::revise('Harbour'))->equals($this->hasher()->hash($name, 1, self::revise('Harbour'))));
    }

    #[Test]
    public function it_gives_other_input_another_hash(): void
    {
        $name = new CommandName('entry.revise');
        $hasher = $this->hasher();

        Assert::assertFalse($hasher->hash($name, 1, self::revise('Harbour'))->equals($hasher->hash($name, 1, self::revise('Harbour opens'))));
        Assert::assertFalse($hasher->hash($name, 1, self::revise('Harbour'))->equals($hasher->hash($name, 1, self::revise('Harbour', 3))));
    }

    #[Test]
    public function it_gives_the_same_input_under_another_version_or_name_another_hash(): void
    {
        $hasher = $this->hasher();
        $input = self::revise('Harbour');
        $first = $hasher->hash(new CommandName('entry.revise'), 1, $input);

        Assert::assertFalse($first->equals($hasher->hash(new CommandName('entry.revise'), 2, $input)));
        Assert::assertFalse($first->equals($hasher->hash(new CommandName(self::OTHER_NAME), 1, $input)));
    }

    private static function revise(string $title, int $version = 2): ReviseEntry
    {
        return new ReviseEntry(
            EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000065e1'),
            new AggregateVersion($version),
            new FieldValues(new FieldMap(new NamedValue(new FieldHandle('headline'), new TextValue($title)))),
        );
    }
}
