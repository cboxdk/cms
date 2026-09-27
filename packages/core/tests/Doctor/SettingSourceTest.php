<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Adapter\SettingSourceParser;
use Cbox\Cms\Core\Doctor\Domain\Dto\TimeoutSetting;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\SettingSource;
use PHPUnit\Framework\AssertionFailedError;

/*
 * pg_settings.source is a closed set (GUARDRAILS 2.2): the probes parse it into SettingSource, and
 * the checks decide on the enum, never on the text.
 */

it('has exactly the sources of pg_settings, by the text Postgres shows', function (): void {
    expect(array_map(static fn (SettingSource $source): string => $source->value, SettingSource::cases()))->toBe([
        'default',
        'environment variable',
        'configuration file',
        'command line',
        'global',
        'database',
        'user',
        'database user',
        'client',
        'override',
        'interactive',
        'test',
        'session',
    ]);
});

it('counts only ALTER ROLE settings as set on the role, and only the server\'s own sources as the server\'s', function (): void {
    $role = array_values(array_filter(SettingSource::cases(), static fn (SettingSource $source): bool => $source->isRole()));
    $server = array_values(array_filter(SettingSource::cases(), static fn (SettingSource $source): bool => $source->isServer()));

    expect($role)->toBe([SettingSource::User, SettingSource::DatabaseUser])
        ->and($server)->toBe([SettingSource::Default, SettingSource::EnvironmentVariable, SettingSource::ConfigurationFile, SettingSource::CommandLine]);
});

it('decides whether a timeout is set on the role from the enum', function (SettingSource $source, bool $onRole): void {
    expect(new TimeoutSetting('cms_app', 5000, $source)->isSetOnRole())->toBe($onRole);
})->with([
    'user' => [SettingSource::User, true],
    'database user' => [SettingSource::DatabaseUser, true],
    'database' => [SettingSource::Database, false],
    'global' => [SettingSource::Global, false],
    'client' => [SettingSource::Client, false],
    'session' => [SettingSource::Session, false],
]);

it('parses every source Postgres shows', function (): void {
    foreach (SettingSource::cases() as $source) {
        expect(SettingSourceParser::parse('transaction_timeout', $source->value))->toBe($source);
    }
});

it('fails the probe on a source Postgres does not have, instead of guessing', function (string $text): void {
    try {
        SettingSourceParser::parse('transaction_timeout', $text);

        throw new AssertionFailedError(sprintf('The source "%s" was parsed.', $text));
    } catch (ProbeFailed $failed) {
        expect($failed->kind)->toBe(FailureKind::Violation)
            ->and($failed->cause)->toStartWith(sprintf('Postgres reports the source "%s" for transaction_timeout, which is not one of the sources in pg_settings: default, environment variable,', $text));
    }
})->with(['role', 'User', 'database_user', '']);
