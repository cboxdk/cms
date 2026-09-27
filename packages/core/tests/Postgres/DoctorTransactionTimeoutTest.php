<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Core\Doctor\Adapter\ConnectionPostgresProbe;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Domain\Checks\TransactionTimeoutCheck;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Doctor\Domain\SettingSource;
use Illuminate\Support\Facades\DB;

/*
 * postgres.transaction_timeout on the Postgres 18 service of compose.yaml (PRD 4.2): the probe
 * reads pg_settings as the app role, and the provisioned role has the setting from ALTER ROLE.
 */

afterEach(function (): void {
    DB::purge(DoctorConnection::NAME);
});

it('reads the app role\'s transaction_timeout with its source parsed from pg_settings', function (): void {
    $probe = app(PostgresProbe::class);
    $timeout = $probe->transactionTimeout();
    $result = new TransactionTimeoutCheck($probe)->run();

    expect($probe)->toBeInstanceOf(ConnectionPostgresProbe::class)
        ->and($timeout->role)->toBe('cms_app')
        ->and($timeout->milliseconds)->toBe(5000)
        ->and($timeout->source)->toBe(SettingSource::User)
        ->and($timeout->isSetOnRole())->toBeTrue()
        ->and($result->status)->toBe(CheckStatus::Pass, (string) $result->cause);
});
