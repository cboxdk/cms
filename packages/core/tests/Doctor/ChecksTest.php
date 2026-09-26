<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Checks\AppRoleCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ChromiumCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\DdlPrivilegesCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\InvalidConfigurationCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\LaravelVersionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\NodeCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PartitionRunwayCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PhpVersionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PlaywrightCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresQueryFailure;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresReachableCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresVersionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PreparedTransactionsCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\RegistryCacheCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\RowSecurityCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\TransactionTimeoutCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\ValkeyReachableCheck;
use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleMembership;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePartitionRunwayProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePostgresProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeRegistryCacheProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeRuntimeProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeToolProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakeValkeyProbe;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateTimeImmutable;

/*
 * Each core check on its own, with fake probes: what it passes, what it fails, with which kind,
 * code, cause and fix.
 */

function expectFailure(CheckResult $result, FailureKind $kind, string $code, string $cause): void
{
    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->failure)->toBe($kind)
        ->and($result->code)->toBe($code)
        ->and($result->cause)->toContain($cause)
        ->and($result->fix)->not->toBeNull();
}

it('passes PHP 8.5 and newer and fails older PHP', function (): void {
    expect(new PhpVersionCheck(new FakeRuntimeProbe(php: '8.5.0'))->run()->passed())->toBeTrue()
        ->and(new PhpVersionCheck(new FakeRuntimeProbe(php: '8.6.1'))->run()->passed())->toBeTrue();

    expectFailure(new PhpVersionCheck(new FakeRuntimeProbe(php: '8.4.99'))->run(), FailureKind::Violation, PhpVersionCheck::CODE, 'PHP 8.4.99');
});

it('passes Laravel 13 only', function (string $version, bool $passes): void {
    $result = new LaravelVersionCheck(new FakeRuntimeProbe(laravel: $version))->run();

    expect($result->passed())->toBe($passes);

    if (! $passes) {
        expectFailure($result, FailureKind::Violation, LaravelVersionCheck::CODE, 'Laravel '.$version);
    }
})->with([['13.0.0', true], ['13.33.1', true], ['12.9.0', false], ['14.0.0', false], ['1.3.0', false]]);

it('reports an unreachable Postgres as unavailable and a refused login as a violation', function (): void {
    $postgres = new FakePostgresProbe;

    expect(new PostgresReachableCheck($postgres)->run()->explanation)->toContain('cms_app@fake:5432/cms');

    $postgres->connectFailure = ProbeFailed::unavailable('SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 1 failed: Connection refused');
    $unavailable = new PostgresReachableCheck($postgres)->run();
    expectFailure($unavailable, FailureKind::Unavailable, PostgresReachableCheck::CODE_UNAVAILABLE, 'Connection refused');
    expect($unavailable->fix)->toContain('DB_HOST and DB_PORT');

    $postgres->connectFailure = ProbeFailed::violation('FATAL: password authentication failed for user "cms_app"');
    $refused = new PostgresReachableCheck($postgres)->run();
    expectFailure($refused, FailureKind::Violation, PostgresReachableCheck::CODE_REFUSED, 'password authentication failed');
    expect($refused->fix)->toContain('DB_PASSWORD');
});

it('passes Postgres 17 and newer and fails 16', function (int $number, string $text, bool $passes): void {
    $postgres = new FakePostgresProbe;
    $postgres->versionNumber = $number;
    $postgres->versionText = $text;
    $result = new PostgresVersionCheck($postgres)->run();

    expect($result->passed())->toBe($passes);

    if (! $passes) {
        expectFailure($result, FailureKind::Violation, PostgresVersionCheck::CODE, 'Postgres '.$text);
    }
})->with([[170_000, '17.0', true], [180_001, '18.1', true], [160_004, '16.4', false], [150_010, '15.10', false]]);

it('fails an app role that is a superuser or bypasses row level security', function (): void {
    $postgres = new FakePostgresProbe;

    expect(new AppRoleCheck($postgres)->run()->passed())->toBeTrue();

    $postgres->bypassRowSecurity = true;
    expectFailure(new AppRoleCheck($postgres)->run(), FailureKind::Violation, AppRoleCheck::CODE_BYPASSRLS, 'cms_app has BYPASSRLS');

    $postgres->superuser = true;
    expectFailure(new AppRoleCheck($postgres)->run(), FailureKind::Violation, AppRoleCheck::CODE_SUPERUSER, 'cms_app has SUPERUSER');
});

it('fails an app role that is a member of a superuser, a BYPASSRLS role or an owner of relations, naming each', function (): void {
    $postgres = new FakePostgresProbe;
    $postgres->memberships = [
        new RoleMembership('cms_owner', false, false, true),
        new RoleMembership('platform_admin', true, true, false),
        new RoleMembership('reporting', false, true, true),
    ];
    $result = new AppRoleCheck($postgres)->run();

    expectFailure($result, FailureKind::Violation, AppRoleCheck::CODE_MEMBERSHIP, 'The role cms_app is a member of cms_owner, which owns relations; platform_admin, which has SUPERUSER and BYPASSRLS; reporting, which has BYPASSRLS and owns relations.');
    expect($result->fix)->toStartWith('Run REVOKE cms_owner, platform_admin, reporting FROM cms_app as a superuser');

    $postgres->bypassRowSecurity = true;
    expectFailure(new AppRoleCheck($postgres)->run(), FailureKind::Violation, AppRoleCheck::CODE_BYPASSRLS, 'cms_app has BYPASSRLS');
});

it('names the roles through which the app role owns relations, and fixes each way it owns them', function (): void {
    $postgres = new FakePostgresProbe;
    $postgres->ownedRelations = ['cms.receipts', 'cms.notes'];
    $postgres->ownerRoles = ['cms_owner'];
    $result = new DdlPrivilegesCheck($postgres)->run();

    expectFailure($result, FailureKind::Violation, DdlPrivilegesCheck::CODE, 'The role cms_app: it owns 2 relations through its membership of cms_owner, such as cms.receipts, cms.notes.');
    expect($result->fix)->toBe('REVOKE cms_owner FROM cms_app as a superuser, or revoke the grant that leads to it when the membership is indirect. Run the migrations as the owner role.');

    $postgres->ownerRoles = ['cms_app', 'cms_owner', 'legacy'];
    $result = new DdlPrivilegesCheck($postgres)->run();

    expectFailure($result, FailureKind::Violation, DdlPrivilegesCheck::CODE, 'it owns 2 relations, directly and through its membership of cms_owner, legacy, such as cms.receipts, cms.notes');
    expect($result->fix)->toContain('REASSIGN OWNED BY cms_app')
        ->and($result->fix)->toContain('REVOKE cms_owner, legacy FROM cms_app');
});

it('passes a transaction_timeout above zero that comes from the role', function (int $milliseconds, string $source, bool $passes, string $cause): void {
    $postgres = new FakePostgresProbe;
    $postgres->transactionTimeoutMs = $milliseconds;
    $postgres->transactionTimeoutSource = $source;
    $result = new TransactionTimeoutCheck($postgres)->run();

    expect($result->passed())->toBe($passes);

    if (! $passes) {
        expectFailure($result, FailureKind::Violation, TransactionTimeoutCheck::CODE, $cause);
        expect($result->fix)->toContain("ALTER ROLE cms_app SET transaction_timeout = '5s'");
    }
})->with([
    'set on the role' => [5000, 'user', true, ''],
    'set on the role in the database' => [5000, 'database user', true, ''],
    'reset on the role' => [0, 'default', false, 'is 0 (off) for the role cms_app'],
    'turned off in the database' => [0, 'database user', false, 'is 0 (off)'],
    'set for the whole server' => [5000, 'configuration file', false, 'took it from "configuration file", not from the role'],
    'set by the connection' => [5000, 'client', false, 'took it from "client"'],
]);

it('fails prepared transactions that are enabled', function (): void {
    $postgres = new FakePostgresProbe;

    expect(new PreparedTransactionsCheck($postgres)->run()->passed())->toBeTrue();

    $postgres->maxPreparedTransactions = 5;
    expectFailure(new PreparedTransactionsCheck($postgres)->run(), FailureKind::Violation, PreparedTransactionsCheck::CODE, 'max_prepared_transactions is 5');
});

it('fails an app role that owns relations or may create objects', function (): void {
    $postgres = new FakePostgresProbe;

    expect(new DdlPrivilegesCheck($postgres)->run()->passed())->toBeTrue();

    $postgres->ownedRelations = ['cms.receipts', 'cms.notes'];
    $postgres->createOnDatabase = true;
    $postgres->schemasWithCreate = ['cms', 'public'];
    $result = new DdlPrivilegesCheck($postgres)->run();

    expectFailure($result, FailureKind::Violation, DdlPrivilegesCheck::CODE, 'it owns 2 relations, such as cms.receipts, cms.notes');
    expect($result->cause)->toContain('CREATE on the database cms')
        ->and($result->cause)->toContain('CREATE on the schemas cms, public')
        ->and($result->fix)->toContain('REASSIGN OWNED BY cms_app')
        ->and($result->fix)->toContain('REVOKE CREATE ON SCHEMA cms, public FROM cms_app');
});

it('fails a table with row level security that does not force it, and blocks', function (): void {
    $postgres = new FakePostgresProbe;
    $check = new RowSecurityCheck($postgres);

    expect($check->blocking())->toBeTrue()
        ->and($check->requires()[0]->value)->toBe(PostgresReachableCheck::ID)
        ->and($check->run()->passed())->toBeTrue()
        ->and($check->run()->explanation)->toBe('All 2 tables with row level security in the database cms force it on their owner.');

    $postgres->rowSecurityTables = 0;

    expect($check->run()->passed())->toBeTrue()
        ->and($check->run()->explanation)->toBe('No table in the database cms has row level security yet.');

    $postgres->rowSecurityTables = 3;
    $postgres->unforcedTables = ['cms.entries', 'cms.entries_p20260310'];
    $result = $check->run();

    expectFailure($result, FailureKind::Violation, RowSecurityCheck::CODE, '2 of the 3 tables with row level security in the database cms do not force it, such as cms.entries, cms.entries_p20260310.');
    expect($result->blocking)->toBeTrue()
        ->and($result->fix)->toContain('ALTER TABLE cms.entries FORCE ROW LEVEL SECURITY');
});

it('fails a Postgres check whose query fails with the probe\'s kind', function (FailureKind $kind): void {
    $postgres = new FakePostgresProbe;
    $postgres->queryFailure = $kind === FailureKind::Unavailable ? ProbeFailed::unavailable('server closed the connection') : ProbeFailed::violation('permission denied');

    foreach ([new PostgresVersionCheck($postgres), new AppRoleCheck($postgres), new TransactionTimeoutCheck($postgres), new PreparedTransactionsCheck($postgres), new DdlPrivilegesCheck($postgres), new RowSecurityCheck($postgres)] as $check) {
        $result = $check->run();

        expect($result->failure)->toBe($kind)
            ->and($result->code)->toBe(PostgresQueryFailure::CODE)
            ->and($result->id->value)->toBe($check->id()->value);
    }
})->with([FailureKind::Unavailable, FailureKind::Violation]);

it('reports Valkey that cannot be reached as unavailable and a refused login as a violation', function (): void {
    expect(new ValkeyReachableCheck(new FakeValkeyProbe)->run()->passed())->toBeTrue();

    expectFailure(new ValkeyReachableCheck(new FakeValkeyProbe(ProbeFailed::unavailable('Connection refused')))->run(), FailureKind::Unavailable, ValkeyReachableCheck::CODE_UNAVAILABLE, 'Connection refused');
    expectFailure(new ValkeyReachableCheck(new FakeValkeyProbe(ProbeFailed::violation('WRONGPASS invalid username-password pair')))->run(), FailureKind::Violation, ValkeyReachableCheck::CODE_REFUSED, 'WRONGPASS');
});

it('needs every managed table to have partitions the runway ahead, and does not block', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
    $probe = new FakePartitionRunwayProbe([
        new PartitionCoverage('receipts_standard', new DateTimeImmutable('2026-03-17T12:00:00Z')),
        new PartitionCoverage('receipts_evidence', new DateTimeImmutable('2026-04-01T00:00:00Z')),
    ]);
    $check = new PartitionRunwayCheck($probe, $clock, 7);

    expect($check->blocking())->toBeFalse()
        ->and($check->run()->passed())->toBeTrue()
        ->and($probe->askedAt?->format('Y-m-d\TH:i:s.u\Z'))->toBe('2026-03-10T12:00:00.000000Z')
        ->and($check->run()->explanation)->toContain('receipts_standard until 2026-03-17T12:00:00Z (7.0 days)');

    $clock->set(new DateTimeImmutable('2026-03-10T12:00:00.000001Z'));
    $short = $check->run();

    expectFailure($short, FailureKind::Violation, PartitionRunwayCheck::CODE_SHORT, 'receipts_standard until 2026-03-17T12:00:00Z (7.0 days)');
    expect($short->blocking)->toBeFalse()
        ->and($short->cause)->not->toContain('receipts_evidence')
        ->and($short->fix)->toContain('cms:partitions:maintain');

    $probe->runways = [new PartitionCoverage('idempotency_keys', null)];
    expectFailure($check->run(), FailureKind::Violation, PartitionRunwayCheck::CODE_SHORT, 'idempotency_keys has no partition for now');

    $probe->runways = [];
    expect($check->run()->passed())->toBeTrue();

    $probe->failure = ProbeFailed::violation('The table "receipts_standard" is listed in [cms.database.partitions.tables] but does not exist.');
    expectFailure($check->run(), FailureKind::Violation, PartitionRunwayCheck::CODE_UNMANAGEABLE, 'does not exist');
});

it('fails a registry cache that is missing, damaged, stale or has no vendor manifest to compare with', function (): void {
    $probe = new FakeRegistryCacheProbe;
    $check = new RegistryCacheCheck($probe);

    expect($check->run()->passed())->toBeTrue();

    $probe->state = FakeRegistryCacheProbe::build(builtAt: new DateTimeImmutable('2026-01-01T10:00:00Z'));
    expect($check->run()->passed())->toBeTrue('A cache written in the same second is not older.');

    $probe->state = FakeRegistryCacheProbe::build(builtAt: null, missing: ['actions.php', 'hooks.php']);
    expectFailure($check->run(), FailureKind::Violation, RegistryCacheCheck::CODE_MISSING, '/app/bootstrap/cache/cms lacks actions.php, hooks.php');

    $probe->state = FakeRegistryCacheProbe::build(builtAt: new DateTimeImmutable('2026-01-01T10:00:05Z'), damage: 'The registry file hooks.php is malformed.');
    expectFailure($check->run(), FailureKind::Violation, RegistryCacheCheck::CODE_DAMAGED, 'hooks.php is malformed');

    $probe->state = FakeRegistryCacheProbe::build(builtAt: new DateTimeImmutable('2026-01-01T10:00:05Z'), manifestChangedAt: null);
    expectFailure($check->run(), FailureKind::Violation, RegistryCacheCheck::CODE_NO_MANIFEST, '/app/vendor/composer/installed.json does not exist');

    $probe->state = FakeRegistryCacheProbe::build(builtAt: new DateTimeImmutable('2026-01-01T09:59:59Z'));
    $stale = $check->run();
    expectFailure($stale, FailureKind::Violation, RegistryCacheCheck::CODE_STALE, 'written at 2026-01-01T09:59:59Z; Composer wrote /app/vendor/composer/installed.json at 2026-01-01T10:00:00Z');
    expect($stale->fix)->toContain('php artisan cms:build')
        ->and($stale->blocking)->toBeTrue();
});

it('checks Node against the minimum, then Playwright and its Chromium, without blocking', function (): void {
    $tools = new FakeToolProbe;

    foreach ([new NodeCheck($tools, '22.13.0'), new PlaywrightCheck($tools), new ChromiumCheck($tools)] as $check) {
        expect($check->run()->passed())->toBeTrue()
            ->and($check->blocking())->toBeFalse();
    }

    expect(new PlaywrightCheck($tools)->requires()[0]->value)->toBe(NodeCheck::ID)
        ->and(new ChromiumCheck($tools)->requires()[0]->value)->toBe(PlaywrightCheck::ID);

    $tools->node = '20.18.0';
    expectFailure(new NodeCheck($tools, '22.13.0')->run(), FailureKind::Violation, NodeCheck::CODE_VERSION, 'version 20.18.0');

    $tools->node = null;
    expectFailure(new NodeCheck($tools, '22.13.0')->run(), FailureKind::Violation, NodeCheck::CODE_MISSING, 'no node on PATH');

    $tools->playwright = null;
    expectFailure(new PlaywrightCheck($tools)->run(), FailureKind::Violation, PlaywrightCheck::CODE, 'Cannot find module');

    $tools->chromium = null;
    $chromium = new ChromiumCheck($tools)->run();
    expectFailure($chromium, FailureKind::Violation, ChromiumCheck::CODE, 'does not exist');
    expect($chromium->fix)->toContain('npx playwright install chromium');
});

it('fails for an invalid configuration with its cause', function (): void {
    expectFailure(new InvalidConfigurationCheck('The setting cms.doctor.partition_runway_days must be a whole number of at least 1; it is 0.')->run(), FailureKind::Violation, InvalidConfigurationCheck::CODE, 'partition_runway_days');
});
