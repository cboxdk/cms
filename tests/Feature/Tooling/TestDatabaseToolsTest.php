<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\TestDatabase\Boundary\PhpunitDatabase;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/*
 * The tools that reach a checkout's test database from outside the Pest suites, such as the
 * selftest's clean-up, read the owner connection from phpunit.xml and the environment as the
 * suites do. tests/Postgres/DropTestDatabaseTest.php runs the drop for real.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

function phpunitXml(string $env): string
{
    return ScratchDirectory::write(ScratchDirectory::make('cbox-cms-phpunit-xml-').'/phpunit.xml', <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit>
            <php>
        {$env}
            </php>
        </phpunit>
        XML);
}

it('reads the owner connection to the configured database as the suites build pgsql_owner', function (): void {
    $environment = getenv();
    $owner = PhpunitDatabase::owner(Phpstan::root().'/phpunit.xml', $environment);
    $suites = ConnectionSettings::of('pgsql_owner', config());

    expect($owner)->toEqual($suites->withDatabase('cms_test'))
        ->and($owner->database)->toBe('cms_test');
});

it('lets the environment win over phpunit.xml, unless the element is forced', function (): void {
    $xml = phpunitXml(<<<'XML'
                <env name="DB_HOST" value="127.0.0.1"/>
                <env name="DB_PORT" value="54317"/>
                <env name="DB_DATABASE" value="cms_test"/>
                <env name="DB_OWNER_USERNAME" value="cms_owner" force="true"/>
                <env name="DB_OWNER_PASSWORD" value="cms_owner"/>
        XML);

    $owner = PhpunitDatabase::owner($xml, ['DB_HOST' => 'postgres', 'DB_PORT' => '5432', 'DB_OWNER_USERNAME' => 'someone']);

    expect([$owner->host, $owner->port, $owner->database, $owner->username, $owner->password, $owner->searchPath])
        ->toBe(['postgres', 5432, 'cms_test', 'cms_owner', 'cms_owner', 'cms']);
});

it('refuses a phpunit.xml it cannot read, one without DB_DATABASE and a port that is not one', function (): void {
    expect(static fn (): ConnectionSettings => PhpunitDatabase::owner('/no/such/phpunit.xml', []))
        ->toThrow(UnexpectedValueException::class, 'Cannot read /no/such/phpunit.xml.')
        ->and(static fn (): ConnectionSettings => PhpunitDatabase::owner(phpunitXml('<env name="DB_HOST" value="h"/>'), []))
        ->toThrow(UnexpectedValueException::class, 'sets no DB_DATABASE.')
        ->and(static fn (): ConnectionSettings => PhpunitDatabase::owner(phpunitXml('<env name="DB_DATABASE" value="d"/>'), ['DB_PORT' => 'x']))
        ->toThrow(UnexpectedValueException::class, 'DB_PORT is not a port: x.');
});

it('exits 2 from the drop script without a checkout root that exists', function (array $arguments): void {
    $process = new Process([PHP_BINARY, 'tools/bin/drop-test-database.php', ...array_filter($arguments, is_string(...))], Phpstan::root());
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Usage: php tools/bin/drop-test-database.php <checkout root>');
})->with([
    'no argument' => [[]],
    'a missing directory' => [['/no/such/checkout']],
    'two arguments' => [['/tmp', '/tmp']],
]);
