<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Testkit\Postgres\Infrastructure\SetupStatement;
use Cbox\Cms\Testkit\Postgres\Infrastructure\TestDatabaseSetup;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use UnexpectedValueException;

/*
 * The testkit sets up each checkout's test database with its own copy of the statements of
 * docker/postgres/sql/database.sql, because it cannot read docker/ when it is installed on its
 * own. This holds the copy equal to the file, statement for statement, after psql's variables are
 * substituted: the statements before `\connect :"db"`, the database it connects to, and the
 * statements after.
 */

const DATABASE_SQL = 'docker/postgres/sql/database.sql';

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * Reads a psql script the way database.sql is written: whole-line `--` comments, statements that
 * end with `;` at the end of a line or with a `\gexec` line, and `\connect` lines. It substitutes
 * `:'name'` with a quoted literal and `:"name"` with a quoted identifier, as psql does for values
 * without backslashes, and collapses whitespace.
 *
 * @param  array<string, string>  $variables
 * @return array{onServer: list<string>, connect: string, inDatabase: list<string>}
 */
function psqlScript(string $path, array $variables): array
{
    $substitute = static fn (string $text): string => (string) preg_replace_callback(
        '/:(["\'])([a-z_]+)\1/',
        static function (array $match) use ($variables): string {
            $value = $variables[$match[2]] ?? throw new UnexpectedValueException("{$match[2]} is not a psql variable of the script.");

            return $match[1] === '"' ? TestDatabaseSetup::identifier($value) : TestDatabaseSetup::literal($value);
        },
        $text,
    );
    $normalize = static fn (string $sql): string => trim((string) preg_replace('/\s+/', ' ', $sql));

    $parts = ['onServer' => [], 'inDatabase' => []];
    $part = 'onServer';
    $connect = '';
    $pending = '';

    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }

        if ($trimmed === '\gexec') {
            $parts[$part][] = $normalize($substitute($pending)).' \gexec';
            $pending = '';

            continue;
        }

        if (str_starts_with($trimmed, '\connect ')) {
            expect($pending)->toBe('', 'A statement is unfinished before \connect.');
            $connect = $substitute(substr($trimmed, strlen('\connect ')));
            $part = 'inDatabase';

            continue;
        }

        expect($trimmed)->not->toStartWith('\\', "The script uses the psql command {$trimmed}, which the harness does not run.");
        $pending .= ' '.$trimmed;

        if (str_ends_with($trimmed, ';')) {
            $parts[$part][] = $normalize($substitute(substr(trim($pending), 0, -1))).';';
            $pending = '';
        }
    }

    expect(trim($pending))->toBe('', 'The script ends with an unfinished statement.');

    return ['onServer' => $parts['onServer'], 'connect' => $connect, 'inDatabase' => $parts['inDatabase']];
}

/**
 * @return array{onServer: list<string>, connect: string, inDatabase: list<string>}
 */
function harnessSetup(TestDatabaseSetup $setup): array
{
    $psql = static fn (SetupStatement $statement): string => trim((string) preg_replace('/\s+/', ' ', $statement->psql()));

    return [
        'onServer' => array_map($psql, $setup->onServer()),
        'connect' => TestDatabaseSetup::identifier($setup->database),
        'inDatabase' => array_map($psql, $setup->inDatabase()),
    ];
}

/**
 * @return array<string, string>
 */
function setupVariables(): array
{
    return ['db' => 'cms_test_0123456789ab', 'owner_role' => 'cms_owner', 'app_role' => 'cms_app', 'schema' => 'cms'];
}

/**
 * @param  array<string, string>  $variables
 */
function harnessSetupOf(array $variables): TestDatabaseSetup
{
    return new TestDatabaseSetup($variables['db'], $variables['owner_role'], $variables['app_role'], $variables['schema']);
}

it('runs the statements of database.sql, statement for statement, with the psql variables substituted', function (): void {
    $variables = setupVariables();
    $script = psqlScript(Phpstan::root().'/'.DATABASE_SQL, $variables);

    expect(harnessSetup(harnessSetupOf($variables)))->toBe($script)
        ->and($script['onServer'])->toHaveCount(4)
        ->and($script['inDatabase'])->toHaveCount(7)
        ->and($script['onServer'][0])->toBe("SELECT format('CREATE DATABASE %I OWNER %I', 'cms_test_0123456789ab', 'cms_owner') WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'cms_test_0123456789ab') \\gexec");
});

it('quotes the names as psql does, also when they need quoting', function (): void {
    $variables = ['db' => 'Test "db"', 'owner_role' => "o'wner", 'app_role' => 'App', 'schema' => 'my schema'];

    expect(harnessSetup(harnessSetupOf($variables)))->toBe(psqlScript(Phpstan::root().'/'.DATABASE_SQL, $variables))
        ->and(harnessSetupOf($variables)->onServer()[1]->sql)->toBe('ALTER DATABASE "Test ""db""" OWNER TO "o\'wner"');
});

it('fails when one statement of a copy of database.sql changes', function (string $from, string $to): void {
    $original = (string) file_get_contents(Phpstan::root().'/'.DATABASE_SQL);
    $changed = str_replace($from, $to, $original);
    $copy = ScratchDirectory::write(ScratchDirectory::make('cbox-cms-database-sql-').'/database.sql', $changed);
    $variables = setupVariables();

    expect($changed)->not->toBe($original)
        ->and(harnessSetup(harnessSetupOf($variables)))->not->toBe(psqlScript($copy, $variables));
})->with([
    'a grant' => ['GRANT USAGE ON SCHEMA :"schema" TO :"app_role";', 'GRANT USAGE, CREATE ON SCHEMA :"schema" TO :"app_role";'],
    'a default privilege' => ['GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES', 'GRANT SELECT, INSERT, UPDATE ON TABLES'],
    'a removed statement' => ["REVOKE ALL ON DATABASE :\"db\" FROM PUBLIC;\n", ''],
    'an added statement' => ["GRANT CONNECT ON DATABASE :\"db\" TO :\"app_role\";\n", "GRANT CONNECT ON DATABASE :\"db\" TO :\"app_role\";\nGRANT TEMPORARY ON DATABASE :\"db\" TO :\"app_role\";\n"],
    'the connect' => ['\connect :"db"', '\connect postgres'],
    'the creation' => ["format('CREATE DATABASE %I OWNER %I'", "format('CREATE DATABASE %I'"],
]);
