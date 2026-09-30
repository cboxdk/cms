<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Identity;

use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Closure;
use Override;

/**
 * A committer that commits through the real one and then runs a scheduled step before it returns,
 * while the command transaction is still open and holds every lock the commit took, such as the
 * row lock of the actor a deactivation changes. A test uses it to let another session meet those
 * locks before the transaction commits.
 */
final readonly class HoldingCommitter implements ChangesetCommitter
{
    /**
     * @param  Closure(): void  $afterCommit
     */
    public function __construct(
        private ChangesetCommitter $committer,
        private Closure $afterCommit,
    ) {}

    #[Override]
    public function commit(PendingChangeset $changeset): CommitOutcome
    {
        $outcome = $this->committer->commit($changeset);
        ($this->afterCommit)();

        return $outcome;
    }
}
