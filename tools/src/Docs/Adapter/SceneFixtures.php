<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Adapter;

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
use Cbox\Cms\Identity\Doctor\Domain\Probes\LoginPolicyProbe;
use Cbox\Cms\Identity\Doctor\Domain\Probes\PasswordHashingProbe;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakeCredentialStoreProbe;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakeLoginPolicyProbe;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakePasswordHashingProbe;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Tooling\Docs\Domain\Scene;
use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;

/**
 * Binds the fixtures of a scene into the workbench application: a FakeClock at FIXED_TIME and the
 * fake doctor probes of the core and the identity module, so the doctor runs its real checks, in its real order and with its
 * real messages, on answers that never change. The probes name their targets `fake`, so an image
 * never passes for the output of a real server.
 */
final readonly class SceneFixtures
{
    public const string FIXED_TIME = '2026-03-10T12:00:00Z';

    /** The partitioned tables of the core's migrations, with the end of each one's runway. */
    private const array RUNWAYS = [
        'receipts_standard' => '2026-03-24T00:00:00Z',
        'receipts_evidence' => '2026-05-01T00:00:00Z',
        'receipt_projections_standard' => '2026-03-24T00:00:00Z',
        'receipt_projections_evidence' => '2026-05-01T00:00:00Z',
        'idempotency_keys' => '2026-03-24T00:00:00Z',
    ];

    public static function bind(Application $app, Scene $scene): void
    {
        $runways = [];

        foreach (self::RUNWAYS as $table => $until) {
            $runways[] = new PartitionCoverage($table, new DateTimeImmutable($until));
        }

        $app->instance(Clock::class, new FakeClock(new DateTimeImmutable(self::FIXED_TIME)));
        $app->instance(RuntimeProbe::class, new FakeRuntimeProbe);
        $app->instance(PhpSettingsProbe::class, new FakePhpSettingsProbe(allowUrlFopen: $scene === Scene::AllowUrlFopen));
        $app->instance(PostgresProbe::class, new FakePostgresProbe);
        $app->instance(LcMessagesProbe::class, new FakeLcMessagesProbe);
        $app->instance(ValkeyProbe::class, new FakeValkeyProbe);
        $app->instance(PartitionRunwayProbe::class, new FakePartitionRunwayProbe($runways));
        $app->instance(RegistryCacheProbe::class, new FakeRegistryCacheProbe);
        $app->instance(EventLogProbe::class, new FakeEventLogProbe);
        $app->instance(OperatorProbe::class, new FakeOperatorProbe);
        $app->instance(ToolProbe::class, new FakeToolProbe);
        $app->instance(CredentialStoreProbe::class, new FakeCredentialStoreProbe);
        $app->instance(PasswordHashingProbe::class, new FakePasswordHashingProbe);
        $app->instance(LoginPolicyProbe::class, new FakeLoginPolicyProbe('local', ['staff' => ['local_factors' => 'password']]));
    }
}
