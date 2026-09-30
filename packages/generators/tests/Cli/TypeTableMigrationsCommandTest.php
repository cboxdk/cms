<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Cli;

use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Illuminate\Contracts\Console\Kernel;

/*
 * cms:generate over a copy of the workbench's fixture blueprint and its committed type table
 * migrations and schema lock (PRD 11.6, 11.12): a new optional field writes one add_columns
 * migration and a new step of the lock, and changes nothing else in the migrations directory; a
 * removed field is refused with generate_field_removed, and nothing is written.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

const WORKBENCH_ARTICLE = 'workbench/schema/fixture_article.yaml';

/** The fixture addon's extension of the article, which the article's lock holds a column of. */
const WORKBENCH_ARTICLE_EXTENSION = 'workbench/addons/fixtureaddon/schema/fixture_article.yaml';

/** The article's committed migrations: its creation, the addon's column and version 2's field. */
const WORKBENCH_ARTICLE_MIGRATIONS = ['app__fixture_article.lock', 'app__fixture_article_0001_create.php', 'app__fixture_article_0002_add_columns.php', 'app__fixture_article_0003_add_columns.php'];

const WORKBENCH_MIGRATIONS = 'workbench/database/migrations/cms';

const ARTICLE_SUMMARY = <<<'YAML'
      - handle: fixture_summary
        label: Summary
        description: A short summary of the article.
        type: text
        classification: public

    YAML;

/**
 * A scratch root with a copy of the workbench's fixture article, the fixture addon's extension of
 * it and the article's committed migrations and lock, set as cbox-cms.generators.
 */
function articleCopy(): string
{
    $monorepo = dirname(__DIR__, 4);
    $root = SchemaFixtures::scratch();
    SchemaFixtures::write($root.'/schema/fixture_article.yaml', (string) file_get_contents($monorepo.'/'.WORKBENCH_ARTICLE));
    SchemaFixtures::write($root.'/addon/fixture_article.yaml', (string) file_get_contents($monorepo.'/'.WORKBENCH_ARTICLE_EXTENSION));

    foreach (WORKBENCH_ARTICLE_MIGRATIONS as $file) {
        SchemaFixtures::write($root.'/database/migrations/cms/'.$file, (string) file_get_contents($monorepo.'/'.WORKBENCH_MIGRATIONS.'/'.$file));
    }

    config()->set('cbox-cms.generators', [
        'root' => $root,
        'roots' => ['app' => 'schema', 'fixtureaddon' => 'addon'],
        'php_directory' => 'app/Cms/Generated',
        'php_namespace' => 'App\Cms\Generated',
        'typescript_directory' => 'resources/js/cms/generated',
        'migrations_directory' => 'database/migrations/cms',
    ]);

    return $root;
}

/**
 * @return array{int, string}
 */
function generateArticle(): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:generate');

    return [$status, $artisan->output()];
}

/**
 * @return array<string, string> file name to hash, of the migrations directory
 */
function migrationHashes(string $root): array
{
    $hashes = [];

    foreach (SchemaFixtures::files($root.'/database/migrations/cms') as $file) {
        $hashes[$file] = (string) hash_file('sha256', $root.'/database/migrations/cms/'.$file);
    }

    return $hashes;
}

it('keeps the committed migration and lock of an unchanged copy of the fixture', function (): void {
    $root = articleCopy();
    $before = migrationHashes($root);

    [$status, $output] = generateArticle();

    expect($status)->toBe(0, $output)
        ->and(migrationHashes($root))->toBe($before)
        ->and($output)->not->toContain('database/migrations/cms');
});

it('writes one ADD COLUMN migration when an optional field is added to a copy of the fixture', function (): void {
    $root = articleCopy();
    $before = migrationHashes($root);
    SchemaFixtures::write($root.'/schema/fixture_article.yaml', file_get_contents($root.'/schema/fixture_article.yaml').ARTICLE_SUMMARY);

    [$status, $output] = generateArticle();
    $after = migrationHashes($root);
    $migration = (string) file_get_contents($root.'/database/migrations/cms/app__fixture_article_0004_add_columns.php');

    expect($status)->toBe(0, $output)
        ->and(array_keys($after))->toBe([...WORKBENCH_ARTICLE_MIGRATIONS, 'app__fixture_article_0004_add_columns.php'])
        ->and(array_diff_key($after, ['app__fixture_article.lock' => true, 'app__fixture_article_0004_add_columns.php' => true]))->toBe(array_diff_key($before, ['app__fixture_article.lock' => true]))
        ->and($after['app__fixture_article.lock'])->not->toBe($before['app__fixture_article.lock'])
        ->and($output)->toContain('written: database/migrations/cms/app__fixture_article.lock')
        ->and($output)->toContain('written: database/migrations/cms/app__fixture_article_0004_add_columns.php')
        ->and(substr_count($migration, 'add column if not exists'))->toBe(1)
        ->and($migration)->toContain("alter table \"app__fixture_article\"\n                    add column if not exists \"fixture_summary\" text\n                        check (char_length(\"fixture_summary\") <= 255)\n")
        ->and($migration)->not->toContain('create index');
});

it('refuses to generate when a field is removed from a copy of the fixture, and writes nothing', function (): void {
    $root = articleCopy();
    $before = migrationHashes($root);
    $blueprint = (string) file_get_contents($root.'/schema/fixture_article.yaml');
    $start = (int) strpos($blueprint, '  - handle: fixture_reading_minutes');
    $end = (int) strpos($blueprint, '  - handle: fixture_featured');
    SchemaFixtures::write($root.'/schema/fixture_article.yaml', substr($blueprint, 0, $start).substr($blueprint, $end));

    [$status, $output] = generateArticle();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_SCHEMA)
        ->and($output)->toContain('[generate_field_removed]')
        ->and($output)->toContain('has no field for the column fixture_reading_minutes, which its schema lock app__fixture_article.lock has since step 1')
        ->and(migrationHashes($root))->toBe($before)
        ->and(is_dir($root.'/app'))->toBeFalse();
});
