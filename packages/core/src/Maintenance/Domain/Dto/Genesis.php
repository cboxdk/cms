<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use DateTimeImmutable;

/**
 * The genesis of an installation: the id of the operator it creates, the id and time of its
 * changeset and the correlation id the changeset records.
 */
#[Internal]
final readonly class Genesis
{
    /** The name of the genesis changeset's command, which no action handles. */
    public const string COMMAND = 'installation.genesis';

    /** The version of the genesis changeset's command. */
    public const int COMMAND_VERSION = 1;

    /** The unit of work of cms:install, from which the genesis changeset's idempotency key is derived. */
    public const string UNIT_OF_WORK = 'install:operator';

    public function __construct(
        public ActorId $operator,
        public ChangesetId $changeset,
        public DateTimeImmutable $at,
        public CorrelationId $correlationId,
    ) {}
}
