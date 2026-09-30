<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Reads\Adapter\PostgresReadAudit;
use Cbox\Cms\Core\Reads\Domain\Dto\AuditedRead;
use Cbox\Cms\Core\Reads\Domain\Dto\ReadAuditRecord;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * The read audit on Postgres for tests: an actor to read as, the partitions of the clock's day,
 * the audit on the default connection, the actor context it writes under, and the rows as the
 * superuser, who passes row level security, reads them.
 *
 * It is made before a transaction opens on the default connection: it commits the actor on the
 * owner connection, and a transaction that has already taken its snapshot never sees that commit,
 * so the audit's foreign key to the actor would fail. When a REPEATABLE READ transaction takes its
 * snapshot depends on the client (with the CI image's PHP, already at SET TRANSACTION), so it is
 * refused inside any transaction.
 */
final readonly class ReadAuditTables
{
    public const string ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000000e1';

    public ActorId $actor;

    private function __construct(public FakeClock $clock)
    {
        $open = app(DatabaseManager::class)->connection()->transactionLevel();

        if ($open > 0) {
            throw new LogicException(sprintf('The read audit tables are made before a transaction opens on the default connection, which has %d open: the actor is committed on the owner connection, and a snapshot taken before that commit never sees it.', $open));
        }

        app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));
        $this->actor = PostgresIdentity::at($clock)->addActor(ActorClass::Service)->id;
    }

    public static function at(FakeClock $clock = new FakeClock): self
    {
        return new self($clock);
    }

    public function audit(): PostgresReadAudit
    {
        return new PostgresReadAudit(app(DatabaseManager::class), $this->clock, new FakeIdGenerator(seed: 26, clock: $this->clock));
    }

    /**
     * Sets the context of the actor, or of another actor, in the open transaction.
     */
    public function context(?ActorId $actor = null): void
    {
        new ActorContext(app(DatabaseManager::class))->set(new AccessContext(
            new ActorPrincipal($actor ?? $this->actor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [],
            ClassificationAccess::Public,
        ));
    }

    public function record(string ...$entries): ReadAuditRecord
    {
        return new ReadAuditRecord(
            $this->actor,
            new CommandName('probe.read'),
            2,
            new CommitPosition('4827'),
            array_map(
                static fn (string $entry): AuditedRead => new AuditedRead(EntryId::fromString($entry), ['diagnosis', 'ext.probe.code'], ClassificationAccess::Sensitive),
                $entries === [] ? [self::ENTRY] : array_values($entries),
            ),
        );
    }

    /**
     * The rows as text, one per row, ordered by entry.
     *
     * @return list<string>
     */
    public function rows(): array
    {
        return StorageTables::texts(StorageTables::superuser(), <<<'SQL'
            select concat_ws(' | ', read_id::text, entry_id::text, actor_id::text, query, query_version::text,
                classification, array_to_string(fields, ','), read_position::text) as value
            from read_audit
            order by entry_id
            SQL);
    }
}
