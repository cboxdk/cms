<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Override;

/**
 * Records every pending changeset it is handed, and answers with the outcome a test gives it, or
 * by default commits it as the changeset CHANGESET at the wait level the envelope asks for.
 */
final class FakeChangesetCommitter implements ChangesetCommitter
{
    public const string CHANGESET = '01936f5e-8a2b-7c3d-9e4f-0000000000c5';

    /** @var list<PendingChangeset> */
    public array $pending = [];

    public function __construct(private readonly ?CommitOutcome $outcome = null) {}

    #[Override]
    public function commit(PendingChangeset $changeset): CommitOutcome
    {
        $this->pending[] = $changeset;

        return $this->outcome ?? new Committed(Receipt::committed(
            ChangesetId::fromString(self::CHANGESET),
            $changeset->envelope->waitLevel,
            RetentionClass::Standard,
        ));
    }
}
