<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Cli\Boundary\CliCredential;
use Cbox\Cms\Cli\Domain\CliActions;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/*
 * The CLI surface end to end (GUARDRAILS 2.1: exit codes from the error catalog): cms:run runs the
 * test-only command probe.rename, which the registry exposes on the CLI, through the shared core
 * action RunExposedCommand with the PipelineWorld's fakes (GUARDRAILS 9), as the service actor of
 * the ExposedWorld, whose credential is the configured cbox-cms.cli.credential. It covers the
 * committed receipt, a validation error, a version conflict, a dry run and a receipt that did not
 * reach its wait level, each with the catalog's exit code, and a run without a configured
 * credential.
 */

const RUN_COMMAND = 'cms:run';

/**
 * The world, with probe.rename version 1 exposed on the CLI and its credential configured.
 *
 * @param  list<Surface>  $surfaces
 */
function cliWorld(array $surfaces = [Surface::Rest, Surface::Cli], bool $configured = true): ExposedWorld
{
    $world = new ExposedWorld;

    app()->instance(CliActions::class, new CliActions(new CompiledRegistry(
        [new CommandEntry(new CommandName(ExposedWorld::COMMAND), 1, RenameProbe::class, 'acme/probe')],
        [],
        [new ActionEntry(RenameProbeAction::class, 'acme/probe', ActionKind::Write, new CommandName(ExposedWorld::COMMAND), 1, RenameProbe::class, $surfaces)],
    )));
    app()->instance(CommandCodecs::class, ExposedWorld::codecs());
    app()->instance(RunExposedCommand::class, $world->action());
    config()->set(CliCredential::CONFIG_KEY, $configured ? $world->credential()->reveal() : null);

    return $world;
}

/**
 * Runs cms:run for probe.rename version 1 with the world's document, or the one given.
 *
 * @param  array<string, bool|string>  $options
 * @return array{int, list<string>}
 */
function runCli(ExposedWorld $world, array $options, ?string $document = null, string $name = ExposedWorld::COMMAND, string $version = '1'): array
{
    $status = Artisan::call(RUN_COMMAND, ['name' => $name, 'version' => $version, 'document' => $document ?? $world->document(), ...$options]);
    $lines = array_values(array_filter(array_map(rtrim(...), explode("\n", Artisan::output())), static fn (string $line): bool => $line !== ''));

    return [$status, $lines];
}

/**
 * The one line of JSON --json printed, decoded.
 *
 * @param  list<string>  $lines
 * @return array<array-key, mixed>
 */
function cliJson(array $lines): array
{
    expect($lines)->toHaveCount(1);

    $document = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);

    return is_array($document) ? $document : throw new RuntimeException('The output is not a JSON object.');
}

/**
 * The errors of a problem, each as "<code> <field>".
 *
 * @param  array<array-key, mixed>  $problem
 * @return list<string>
 */
function cliProblemErrors(array $problem): array
{
    $errors = is_array($problem['errors'] ?? null) ? $problem['errors'] : [];

    return array_map(
        static fn (mixed $error): string => is_array($error) && is_string($error['code'] ?? null)
            ? $error['code'].' '.(is_string($error['field'] ?? null) ? $error['field'] : '-')
            : throw new RuntimeException('A problem error is not an object with a code.'),
        array_values($errors),
    );
}

it('commits the command as the actor of the configured credential and exits 0 with the receipt as JSON', function (): void {
    $world = cliWorld();

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-success', '--json' => true]);
    $receipt = cliJson($lines);
    $pending = $world->world->committer->pending;

    expect($status)->toBe(0)
        ->and($receipt['outcome'])->toBe('committed')
        ->and($receipt['changeset_id'])->toBeString()
        ->and($receipt['wait_level'])->toBe('commit')
        ->and($pending)->toHaveCount(1)
        ->and($pending[0]->envelope->idempotencyKey->value)->toBe('cli-success')
        ->and($pending[0]->envelope->surface)->toBe(IssuingSurface::Cli)
        ->and($pending[0]->envelope->issuerKind)->toBe(EnvelopeIssuer::System)
        ->and($pending[0]->envelope->actor->equals($world->service))->toBeTrue()
        ->and($pending[0]->envelope->dryRun)->toBeFalse();
});

it('says what it committed in a sentence without --json', function (): void {
    $world = cliWorld();

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-text']);

    expect($status)->toBe(0)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0])->toMatch('/\ACommitted changeset [0-9a-f-]{36}, wait level commit reached\.\z/');
});

it('rejects a validation error with the catalog\'s exit code and the problem with every code and path', function (): void {
    $world = cliWorld();
    $fields = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('colour'), new TextValue('red'))));

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-invalid', '--json' => true], $world->document($fields));
    $problem = cliJson($lines);

    expect($status)->toBe(65)
        ->and($problem['code'])->toBe('validation_failed')
        ->and($problem['status'])->toBe(422)
        ->and(cliProblemErrors($problem))->toBe(['validation_failed -', 'validation_required fields.label', 'validation_unknown_field fields.colour'])
        ->and($world->world->committer->pending)->toBe([]);
});

it('prints each error of a rejection and the error reference without --json', function (): void {
    $world = cliWorld();
    $fields = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('colour'), new TextValue('red'))));

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-invalid-text'], $world->document($fields));

    expect($status)->toBe(65)
        ->and($lines)->toHaveCount(4)
        ->and($lines[0])->toStartWith('validation_failed: ')
        ->and($lines[1])->toBe('validation_required at fields.label: is required.')
        ->and($lines[2])->toStartWith('validation_unknown_field at fields.colour: ')
        ->and($lines[3])->toBe('Rejected; nothing was committed. See docs/reference/errors.md#validation_failed.');
});

it('rejects a version conflict with the catalog\'s exit code', function (): void {
    $world = cliWorld();
    $world->world->commitWith(new VersionConflict(new StaleRead($world->world->entry(), null, new AggregateVersion(1))));
    app()->instance(RunExposedCommand::class, $world->action());

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-conflict', '--json' => true]);
    $problem = cliJson($lines);

    expect($status)->toBe(65)
        ->and($problem['code'])->toBe('version_conflict')
        ->and($problem['status'])->toBe(409)
        ->and($problem['retryable'])->toBeFalse()
        ->and(cliProblemErrors($problem))->toBe(['version_conflict -']);
});

it('runs a dry run with the catalog\'s exit code of dry_run and commits nothing', function (): void {
    $world = cliWorld();

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-dry-run', '--dry-run' => true, '--json' => true]);

    expect($status)->toBe(0)
        ->and(cliJson($lines)['outcome'])->toBe('dry_run')
        ->and($world->world->committer->pending)->toBe([]);

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-dry-run-text', '--dry-run' => true]);

    expect($status)->toBe(0)
        ->and($lines)->toBe(['Dry run: the command would commit. Nothing was committed; run it again without --dry-run to commit it.']);
});

it('exits 0 for a receipt that did not reach its wait level, because the change is committed', function (): void {
    $world = cliWorld();
    $world->world->commitWith(new Committed(Receipt::committedWaitTimeout(
        ChangesetId::fromString(FakeChangesetCommitter::CHANGESET),
        WaitLevel::Origin,
        RetentionClass::Standard,
        new CommitPosition(FakeChangesetCommitter::POSITION),
    )));
    app()->instance(RunExposedCommand::class, $world->action());

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-wait', '--wait-level' => 'origin', '--json' => true]);
    $receipt = cliJson($lines);

    expect($status)->toBe(0)
        ->and($receipt['outcome'])->toBe('committed_wait_timeout')
        ->and($receipt['changeset_id'])->toBe(FakeChangesetCommitter::CHANGESET)
        ->and($receipt['wait_level'])->toBe('origin');

    [, $lines] = runCli($world, ['--idempotency-key' => 'cli-wait', '--wait-level' => 'origin']);

    expect($lines)->toBe([sprintf('Committed changeset %s, but wait level origin was not reached in time. The change is committed; do not run it again.', FakeChangesetCommitter::CHANGESET)]);
});

it('refuses a run without a configured credential as unauthorized, with the catalog\'s exit code', function (): void {
    $world = cliWorld(configured: false);

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-anonymous', '--json' => true]);
    $problem = cliJson($lines);

    expect($status)->toBe(77)
        ->and($problem['code'])->toBe('unauthorized')
        ->and($problem['status'])->toBe(403)
        ->and($world->world->committer->pending)->toBe([])
        ->and($world->contexts->asked)->toBe([]);
});

it('refuses a configured credential the verifier refuses with the verifier\'s code', function (): void {
    $world = cliWorld();
    config()->set(CliCredential::CONFIG_KEY, 'not-a-token');

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-malformed', '--json' => true]);

    expect($status)->toBe(77)
        ->and(cliJson($lines)['code'])->toBe('credential_malformed')
        ->and($world->world->committer->pending)->toBe([]);
});

it('reads the envelope options through the envelope\'s codec, refusing a value at its envelope path', function (): void {
    $world = cliWorld();

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-soon', '--wait-level' => 'soon', '--json' => true]);
    $problem = cliJson($lines);

    expect($status)->toBe(65)
        ->and($problem['code'])->toBe('json_invalid')
        ->and(cliProblemErrors($problem))->toBe(['json_invalid envelope.wait_level'])
        ->and($world->contexts->asked)->toBe([]);

    [$status, $lines] = runCli($world, ['--json' => true]);

    expect($status)->toBe(65)
        ->and(cliJson($lines)['code'])->toBe('json_invalid')
        ->and($world->world->committer->pending)->toBe([]);

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-soon', '--wait-level' => 'soon']);

    expect($status)->toBe(65)
        ->and($lines[0])->toStartWith('json_invalid at envelope.wait_level: ');
});

it('rejects a document that is not JSON with json_malformed', function (): void {
    $world = cliWorld();

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-broken', '--json' => true], '{"label":');

    expect($status)->toBe(65)
        ->and(cliJson($lines)['code'])->toBe('json_malformed')
        ->and($world->world->committer->pending)->toBe([]);
});

/**
 * @return array<string, array{string, string, bool, string}>
 */
function unexposedCliCalls(): array
{
    return [
        'another command' => ['probe.other', '1', true, 'The CLI exposes no version 1 of the command probe.other. It exposes: probe.rename 1.'],
        'another version' => [ExposedWorld::COMMAND, '2', true, 'The CLI exposes no version 2 of the command probe.rename. It exposes: probe.rename 1.'],
        'an action not on the CLI' => [ExposedWorld::COMMAND, '1', false, 'The CLI exposes no version 1 of the command probe.rename. It exposes no command: no write action lists Surface::Cli in its #[Action], or cms:build has not run since one did.'],
        'version zero' => [ExposedWorld::COMMAND, '0', true, 'The version of the command must be a whole number from 1, such as 1; it is "0".'],
        'a version with a leading zero' => [ExposedWorld::COMMAND, '01', true, 'The version of the command must be a whole number from 1, such as 1; it is "01".'],
    ];
}

it('exits 64 for a command or version the CLI does not expose, naming what it exposes', function (string $name, string $version, bool $onCli, string $message): void {
    $world = cliWorld($onCli ? [Surface::Rest, Surface::Cli] : [Surface::Rest]);

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-unexposed', '--json' => true], name: $name, version: $version);

    expect($status)->toBe(64)
        ->and($lines)->toBe([$message])
        ->and($world->world->committer->pending)->toBe([]);
})->with(unexposedCliCalls(...));

it('exits 64 for a name that is not a command name', function (): void {
    $world = cliWorld();

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-name'], name: 'Probe.Rename');

    expect($status)->toBe(64)
        ->and($lines)->toHaveCount(1)
        ->and($world->world->committer->pending)->toBe([]);
});

it('exits 78 for a configured credential that is not a token', function (mixed $value, string $described): void {
    $world = cliWorld();
    config()->set(CliCredential::CONFIG_KEY, $value);

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-config', '--json' => true]);

    expect($status)->toBe(78)
        ->and($lines)->toBe([sprintf('The setting cbox-cms.cli.credential must be the token of a service credential, or null for none; it is %s.', $described)])
        ->and($world->world->committer->pending)->toBe([]);
})->with([
    'a number' => [42, 'int'],
    'an empty string' => [' ', 'an empty string'],
]);

it('refuses a call with the registry cache\'s catalog code when the cache cannot be read', function (RuntimeException $failure, string $code): void {
    $world = cliWorld();
    app()->forgetInstance(CliActions::class);
    app()->bind(CliActions::class, static fn (): never => throw $failure);

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-registry', '--json' => true]);
    $problem = cliJson($lines);

    expect($status)->toBe(78)
        ->and($problem['code'])->toBe($code)
        ->and($world->world->committer->pending)->toBe([]);
})->with([
    'missing' => [RegistryCacheMissing::at('bootstrap/cache/cms/actions.php'), 'registry_cache_missing'],
    'malformed' => [MalformedRegistryCache::at('bootstrap/cache/cms/actions.php', 'entries', 'is not a list'), 'registry_cache_malformed'],
]);

it('exits 70 for a command the registry exposes on the CLI that no codec reads', function (): void {
    $world = cliWorld();
    app()->instance(CommandCodecs::class, new CommandCodecs);

    [$status, $lines] = runCli($world, ['--idempotency-key' => 'cli-codec', '--json' => true]);

    expect($status)->toBe(70)
        ->and($lines)->toBe(['No codec reads version 1 of the command probe.rename, so no exposed surface can read it. Tag its generated codec with CommandCodecs::TAG.'])
        ->and($world->world->committer->pending)->toBe([]);
});

it('exits 64 for an envelope option that is not UTF-8 text', function (): void {
    $world = cliWorld();

    [$status, $lines] = runCli($world, ['--idempotency-key' => "cli-\xff"]);

    expect($status)->toBe(64)
        ->and($lines)->toBe(['An envelope option is not valid UTF-8 text.'])
        ->and($world->world->committer->pending)->toBe([]);
});
