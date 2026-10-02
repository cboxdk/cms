<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Doctor\Domain\Probes\EventLogProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\LcMessagesProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\OperatorProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PartitionRunwayProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PhpSettingsProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\RegistryCacheProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\RuntimeProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\ToolProbe;
use Cbox\Cms\Core\Doctor\Domain\Probes\ValkeyProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeEventLogProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeLcMessagesProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeOperatorProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePartitionRunwayProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePhpSettingsProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePostgresProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeRegistryCacheProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeRuntimeProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeToolProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeValkeyProbe;
use Cbox\Cms\Identity\Doctor\Domain\Probes\CredentialStoreProbe;
use Cbox\Cms\Identity\Doctor\Domain\Probes\PasswordHashingProbe;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakeCredentialStoreProbe;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakePasswordHashingProbe;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * The fake probes bound for one test, the core's and the identity module's, all healthy until the
 * test changes one.
 */
final class DoctorFakes
{
    public FakeRuntimeProbe $runtime;

    public FakePhpSettingsProbe $phpSettings;

    public FakePostgresProbe $postgres;

    public FakeLcMessagesProbe $lcMessages;

    public FakeValkeyProbe $valkey;

    public FakePartitionRunwayProbe $partitions;

    public FakeRegistryCacheProbe $registry;

    public FakeToolProbe $tools;

    public FakeEventLogProbe $events;

    public FakeOperatorProbe $operator;

    public FakeCredentialStoreProbe $credentialStore;

    public FakePasswordHashingProbe $passwordHashing;

    public FakeLogger $log;

    public function __construct()
    {
        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));

        $this->runtime = new FakeRuntimeProbe;
        $this->phpSettings = new FakePhpSettingsProbe;
        $this->postgres = new FakePostgresProbe;
        $this->lcMessages = new FakeLcMessagesProbe;
        $this->valkey = new FakeValkeyProbe;
        $this->partitions = new FakePartitionRunwayProbe([new PartitionCoverage('receipts_standard', new DateTimeImmutable('2026-03-24T00:00:00Z'))]);
        $this->registry = new FakeRegistryCacheProbe;
        $this->tools = new FakeToolProbe;
        $this->events = new FakeEventLogProbe;
        $this->operator = new FakeOperatorProbe;
        $this->credentialStore = new FakeCredentialStoreProbe;
        $this->passwordHashing = new FakePasswordHashingProbe;

        app()->instance(Clock::class, $clock);
        app()->instance(RuntimeProbe::class, $this->runtime);
        app()->instance(PhpSettingsProbe::class, $this->phpSettings);
        app()->instance(PostgresProbe::class, $this->postgres);
        app()->instance(LcMessagesProbe::class, $this->lcMessages);
        app()->instance(ValkeyProbe::class, $this->valkey);
        app()->instance(PartitionRunwayProbe::class, $this->partitions);
        app()->instance(RegistryCacheProbe::class, $this->registry);
        app()->instance(ToolProbe::class, $this->tools);
        app()->instance(EventLogProbe::class, $this->events);
        app()->instance(OperatorProbe::class, $this->operator);
        app()->instance(CredentialStoreProbe::class, $this->credentialStore);
        app()->instance(PasswordHashingProbe::class, $this->passwordHashing);

        $this->log = new FakeLogger;
        app()->instance(LoggerInterface::class, $this->log);
    }
}
