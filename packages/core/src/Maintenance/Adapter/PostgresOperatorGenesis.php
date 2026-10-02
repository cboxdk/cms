<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Changesets\Domain\Dto\ChangesetRecord;
use Cbox\Cms\Core\Changesets\Infrastructure\ChangesetWriter;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Events\Infrastructure\EventWriter;
use Cbox\Cms\Core\Identity\Domain\Events\ActorActivated;
use Cbox\Cms\Core\Identity\Domain\Events\ActorActivatedV1;
use Cbox\Cms\Core\Identity\Domain\Events\ActorRegistered;
use Cbox\Cms\Core\Identity\Domain\Events\ActorRegisteredV1;
use Cbox\Cms\Core\Maintenance\Domain\Dto\Genesis;
use Cbox\Cms\Core\Maintenance\Domain\Dto\InstalledOperator;
use Cbox\Cms\Core\Maintenance\Domain\InstallRefused;
use Cbox\Cms\Core\Maintenance\Domain\OperatorGenesis;
use Cbox\Cms\Core\Partitions\Boundary\SqlError;
use Cbox\Cms\Core\Pipeline\Adapter\ConnectionCommandTransaction;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Override;
use Throwable;
use UnexpectedValueException;

/**
 * The genesis on Postgres, in a transaction of its own on the owner connection, at READ COMMITTED:
 *
 * 1. The actor context of the operator it creates (ActorContext), so the changeset's row level
 *    security holds the changeset to its actor.
 * 2. INSTALL, `cms_install_operator()`, which only the owner role may run: under a lock on
 *    `installation`, and only while it has no row, the operator, a service actor active at version
 *    2, and the row of `installation`; with a row there it raises 23505.
 * 3. The genesis changeset (ChangesetWriter): the command installation.genesis version 1 by the
 *    operator, from the issuer maintenance with the issuer kind system and the key derived from the
 *    unit of work install:operator, retention class Standard, with its audit row on the actor.
 * 4. The events actor.registered at version 1 and actor.activated at version 2 on the interactive
 *    stream (EventWriter), as actor.register and actor.activate would have written them.
 *
 * Then it commits. An install that finds an operator, because another committed first, rolls back
 * and answers with that operator. Any other failure rolls back and goes on; a time no partition
 * covers is PartitionMissing.
 */
#[Internal]
final readonly class PostgresOperatorGenesis implements OperatorGenesis
{
    /** The genesis, as the owner role (see the migration that adds it). */
    public const string INSTALL = 'select cms_install_operator(?::uuid, ?::uuid, ?::timestamptz)';

    /** The operator of the installation, or null. */
    public const string OPERATOR = 'select cms_installation_operator()::text';

    /** unique_violation: the installation has an operator. */
    private const string INSTALLED = '23505';

    /** insufficient_privilege: the connection's role may not run the genesis. */
    private const string NOT_OWNER = '42501';

    /**
     * @param  string|null  $ownerConnection  the owner role's connection; null when this process has none
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private ?string $ownerConnection,
    ) {}

    #[Override]
    public function install(Genesis $genesis): InstalledOperator
    {
        $name = $this->ownerConnection ?? throw InstallRefused::ownerConnectionMissing();
        $db = $this->connections->connection($name);
        $db->beginTransaction();

        try {
            $db->statement(ConnectionCommandTransaction::READ_COMMITTED);
            $this->write($db, $name, $genesis);
            $db->commit();
        } catch (QueryException $exception) {
            $db->rollBack();
            $error = SqlError::of($exception);

            if ($error->is(self::INSTALLED)) {
                return new InstalledOperator($this->existing($db), false);
            }

            if ($error->is(self::NOT_OWNER)) {
                throw InstallRefused::notOwner($name);
            }

            throw $exception;
        } catch (Throwable $thrown) {
            $db->rollBack();

            throw $thrown;
        }

        return new InstalledOperator($genesis->operator, true);
    }

    private function write(ConnectionInterface $db, string $name, Genesis $genesis): void
    {
        $operator = $genesis->operator;

        new ActorContext($this->connections, $name)->set(new AccessContext(
            new ActorPrincipal($operator, [], IssuerKind::Service, IssuerKind::Service->maximumCeiling()),
            [],
            ClassificationAccess::Public,
        ));

        $db->statement(self::INSTALL, [$operator->toString(), $genesis->changeset->toString(), Timestamps::of($genesis->at)]);

        new ChangesetWriter($this->connections, $name)->write(new ChangesetRecord(
            $genesis->changeset,
            $genesis->at,
            RetentionClass::Standard,
            new CommandName(Genesis::COMMAND),
            Genesis::COMMAND_VERSION,
            Envelope::internal(IssuingSurface::Maintenance, EnvelopeIssuer::System, $operator, new UnitOfWork(Genesis::UNIT_OF_WORK), $genesis->correlationId),
            [$operator],
        ));

        new EventWriter($this->connections, $this->clock, $name)->write($genesis->changeset, EventStream::Interactive, [
            new ActorRegistered(1, new ActorRegisteredV1($operator, ActorClass::Service, null, 1)),
            new ActorActivated(2, new ActorActivatedV1($operator)),
        ]);
    }

    private function existing(ConnectionInterface $db): ActorId
    {
        $id = $db->scalar(self::OPERATOR, [], false);

        if (! is_string($id)) {
            throw new UnexpectedValueException('The genesis found an operator in the installation, and then read none.');
        }

        return ActorId::fromString($id);
    }
}
