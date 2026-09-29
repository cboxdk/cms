<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Tally;

use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Override;

/**
 * The version lock of the test-only tally aggregate: its row in the scratch table, FOR UPDATE or
 * FOR SHARE, on the app connection inside the command transaction. It records each lock it took.
 */
final class TallyVersionLock implements VersionLock
{
    /** @var list<string> each lock taken, as "<aggregate key> <strength>" */
    public array $locked = [];

    #[Override]
    public function kind(): string
    {
        return TallyTable::KIND;
    }

    #[Override]
    public function lock(AggregateRef $aggregate, LockStrength $strength): ?AggregateVersion
    {
        if (! $aggregate instanceof TallyId) {
            throw new InvalidArgumentException(sprintf('The tally lock locks tallies, not "%s".', $aggregate->aggregateKey()));
        }

        $this->locked[] = $aggregate->aggregateKey().' '.$strength->value;
        $version = DB::connection()->scalar(
            sprintf('select version from tally_counters where id = ? for %s', $strength === LockStrength::Update ? 'update' : 'share'),
            [$aggregate->toString()],
        );

        return is_int($version) ? new AggregateVersion($version) : null;
    }
}
