<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Harness;

use Cbox\Cms\Testkit\Postgres\Boundary\ChildPayload;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\ChildProcesses;
use InvalidArgumentException;

/*
 * What a child process reads on standard input.
 */

function settings(): ConnectionSettings
{
    return new ConnectionSettings('pgsql', 'postgres', 5432, 'cms_test', 'cms_app', 'cms_app', 'cms');
}

it('round-trips a serialised closure, bytes included', function (): void {
    $closure = "O:1:\"x\":0:{}\0binary\n";
    $decoded = ChildPayload::decode(new ChildPayload(settings(), closure: $closure)->encode());

    expect($decoded->closure)->toBe($closure)
        ->and($decoded->script)->toBeNull()
        ->and($decoded->connection)->toEqual(settings());
});

it('round-trips a script path', function (): void {
    $decoded = ChildPayload::decode(new ChildPayload(settings(), script: '/tmp/child.php')->encode());

    expect($decoded->script)->toBe('/tmp/child.php')->and($decoded->closure)->toBeNull();
});

it('takes either a closure or a script, not both and not neither', function (?string $closure, ?string $script): void {
    expect(static fn (): ChildPayload => new ChildPayload(settings(), $closure, $script))
        ->toThrow(InvalidArgumentException::class, 'either a closure or a script');
})->with([
    'both' => ['c', 's'],
    'neither' => [null, null],
]);

it('rejects input that is not a payload', function (string $json, string $message): void {
    expect(static fn (): ChildPayload => ChildPayload::decode($json))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'not JSON' => ['{', 'not valid JSON'],
    'no connection' => ['{"closure":null}', 'has no connection'],
    'no port' => ['{"connection":{"name":"a","host":"h","database":"d","username":"u","password":"p","search_path":"s"},"script":"x"}', 'has no port'],
    'bad closure' => ['{"connection":{"name":"a","host":"h","port":1,"database":"d","username":"u","password":"p","search_path":"s"},"closure":"***"}', 'invalid closure'],
]);

it('finds the entry script and the autoloader that loads the testkit', function (): void {
    expect(ChildProcesses::entryScript())->toBeFile()
        ->and(ChildProcesses::autoloader())->toBeFile()->toEndWith('/vendor/autoload.php');
});
