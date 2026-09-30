<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Override;

/**
 * The committer of the contract kernel: it hands every changeset to the FakeChangesetCommitter
 * the current scenario gives it, so one command pipeline, which a surface may keep for the whole
 * test, commits as each scenario says.
 */
final class ContractCommitter implements ChangesetCommitter
{
    public function __construct(public FakeChangesetCommitter $current) {}

    #[Override]
    public function commit(PendingChangeset $changeset): CommitOutcome
    {
        return $this->current->commit($changeset);
    }
}
