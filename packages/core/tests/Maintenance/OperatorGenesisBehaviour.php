<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance;

use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Core\Maintenance\Domain\Dto\Genesis;
use Cbox\Cms\Core\Maintenance\Domain\InstallRefused;
use Cbox\Cms\Core\Maintenance\Domain\OperatorGenesis;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every OperatorGenesis does, the fake and the Postgres one alike: it creates the operator once,
 * keeps the first operator for every later genesis, and refuses without the owner connection
 * before it writes anything.
 */
trait OperatorGenesisBehaviour
{
    /** The time of the genesis, which the Postgres test covers with partitions. */
    public const string GENESIS_AT = '2026-03-10T12:00:00Z';

    public const string FIRST_OPERATOR = '019cd79e-4600-7000-8000-0000000005c1';

    public const string FIRST_CHANGESET = '019cd79e-4600-7000-8000-0000000005c2';

    public const string SECOND_OPERATOR = '019cd79e-4600-7000-8000-0000000005c3';

    public const string SECOND_CHANGESET = '019cd79e-4600-7000-8000-0000000005c4';

    /** The genesis under test, with the owner connection. */
    abstract protected function operatorGenesis(): OperatorGenesis;

    /** The genesis under test in a process without the owner connection. */
    abstract protected function genesisWithoutOwner(): OperatorGenesis;

    /** The operator the installation names now, read back as the kernel reads it. */
    abstract protected function installedOperator(): ?ActorId;

    #[Test]
    public function it_creates_the_operator_once_and_names_it_in_the_installation(): void
    {
        $installed = $this->operatorGenesis()->install($this->genesis(self::FIRST_OPERATOR, self::FIRST_CHANGESET));

        Assert::assertTrue($installed->created);
        Assert::assertSame(self::FIRST_OPERATOR, $installed->operator->toString());
        Assert::assertSame(self::FIRST_OPERATOR, $this->installedOperator()?->toString());
    }

    #[Test]
    public function it_keeps_the_first_operator_and_creates_no_second(): void
    {
        $genesis = $this->operatorGenesis();
        $genesis->install($this->genesis(self::FIRST_OPERATOR, self::FIRST_CHANGESET));

        $again = $genesis->install($this->genesis(self::SECOND_OPERATOR, self::SECOND_CHANGESET));

        Assert::assertFalse($again->created);
        Assert::assertSame(self::FIRST_OPERATOR, $again->operator->toString());
        Assert::assertSame(self::FIRST_OPERATOR, $this->installedOperator()?->toString());
    }

    #[Test]
    public function it_refuses_without_the_owner_connection_and_writes_nothing(): void
    {
        try {
            $this->genesisWithoutOwner()->install($this->genesis(self::FIRST_OPERATOR, self::FIRST_CHANGESET));
            Assert::fail('The genesis ran without the owner connection.');
        } catch (InstallRefused $refused) {
            Assert::assertStringStartsWith('['.InstallRefused::CODE.']', $refused->getMessage());
        }

        Assert::assertNull($this->installedOperator());
    }

    protected function genesis(string $operator, string $changeset): Genesis
    {
        return new Genesis(
            ActorId::fromString($operator),
            ChangesetId::fromString($changeset),
            new DateTimeImmutable(self::GENESIS_AT),
            new CorrelationId('genesis-'.$operator),
        );
    }
}
