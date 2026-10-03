<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptTypeHandles;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Tests\Support\Node;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ComposerScripts;
use Cbox\Cms\Tooling\Protocol\Adapter\ProtocolGeneration;
use Cbox\Cms\Tooling\Protocol\Domain\PanelPageSchemas;
use Illuminate\Contracts\Console\Kernel;
use RuntimeException;
use Symfony\Component\Process\Process;

/*
 * Gate 6 of GUARDRAILS 10 for the workbench and the kernel (PRD 11.12, GUARDRAILS 2.2, 7.1):
 * `composer check:generated` fails unless the committed generated code is exactly what cms:generate
 * writes from the committed schema, and the kernel's committed codecs exactly what composer
 * generate:protocol writes from the kernel's JSON Schemas.
 *
 * The gate is run step by step from composer.json in a scratch git repository with a copy of the
 * workbench's blueprints and the fixture addon's, the kernel's schemas and the generated code, so the test does not depend
 * on the state of this working copy. cms:generate runs in-process with cbox-cms.generators.root
 * pointing at the copy, and generate:protocol in-process with the copy as its root.
 */

const GENERATED_PATHS = ['workbench/app/Cms/Generated', 'workbench/resources/js/cms/generated', 'workbench/database/migrations/cms'];

const PROTOCOL_PATH = ProtocolSchemas::PHP_DIRECTORY;

/** What generate:protocol writes for the panel's pages: their codecs and their TypeScript. */
const PANEL_PAGE_PATHS = [PanelPageSchemas::PHP_DIRECTORY, PanelPageSchemas::TYPESCRIPT_DIRECTORY];

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

const SUMMARY_FIELD = <<<'YAML'
      - handle: fixture_summary
        label: Summary
        description: A short summary of the article.
        type: text
        classification: public

    YAML;

const PAGE_TYPE = <<<'YAML'
    blueprint: 1
    kind: type
    type_id: 0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b
    handle: fixture_page
    label: Page
    version: 1
    capabilities:
      history: full
      stages: draft-release
      localization: none
    fields:
      - handle: fixture_title
        label: Title
        description: The title of the page.
        type: text
        classification: public

    YAML;

/**
 * A git repository with the workbench's blueprints, the fixture addon's, the kernel's schemas (the
 * contracts', the core's and the commands') and every file of the generated code, committed.
 */
function gateRepository(): string
{
    $root = SchemaFixtures::scratch();
    $files = [];

    foreach (['workbench/schema', 'workbench/addons/fixtureaddon/schema', ProtocolSchemas::SCHEMA_DIRECTORY, ProtocolSchemas::CORE_SCHEMA_DIRECTORY, ProtocolSchemas::COMMAND_SCHEMA_DIRECTORY, PanelPageSchemas::SCHEMA_DIRECTORY, ...GENERATED_PATHS, PROTOCOL_PATH, ...PANEL_PAGE_PATHS] as $directory) {
        foreach (SchemaFixtures::files(Phpstan::root().'/'.$directory) as $file) {
            $files[] = $directory.'/'.$file;
        }
    }

    foreach ($files as $file) {
        SchemaFixtures::write($root.'/'.$file, (string) file_get_contents(Phpstan::root().'/'.$file));
    }

    git($root, 'init', '--quiet');
    git($root, 'add', '--all');
    git($root, 'commit', '--quiet', '--message=fixture');

    config()->set('cbox-cms.generators.root', $root);

    return $root;
}

function git(string $root, string ...$arguments): string
{
    $process = new Process(['git', '-c', 'user.name=Gate Test', '-c', 'user.email=gate@example.test', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', ...array_values($arguments)], $root);
    $process->mustRun();

    return $process->getOutput();
}

/**
 * Runs `composer check:generated` step by step in the repository, and stops at the first step
 * that fails, like Composer does.
 *
 * @return array{int, string} the exit code and the output
 */
function runGate(string $root): array
{
    $output = '';

    foreach (ComposerScripts::steps('check:generated') as $step) {
        if (str_starts_with($step, '@php vendor/bin/testbench cms:generate')) {
            $artisan = app(Kernel::class);
            $status = $artisan->call('cms:generate');
            $output .= $artisan->output();
        } elseif ($step === '@php tools/bin/generate-protocol.php') {
            $out = fopen('php://memory', 'w+b');

            if ($out === false) {
                throw new RuntimeException('Cannot open a memory stream.');
            }

            $status = ProtocolGeneration::main($root, $out, $out);
            rewind($out);
            $output .= stream_get_contents($out);
        } else {
            $process = Process::fromShellCommandline($step, $root);
            $status = $process->run();
            $output .= $process->getOutput().$process->getErrorOutput();
        }

        if ($status !== 0) {
            return [$status, $output];
        }
    }

    return [0, $output];
}

function appendTo(string $path, string $text): void
{
    SchemaFixtures::write($path, (is_file($path) ? (string) file_get_contents($path) : '').$text);
}

it('regenerates, then fails on a diff or an untracked file under the generated paths, checked before and after', function (): void {
    $paths = implode(' ', [...GENERATED_PATHS, PROTOCOL_PATH, ...PANEL_PAGE_PATHS]);

    expect(ComposerScripts::steps('check:generated'))->toHaveCount(6)
        ->and(ComposerScripts::steps('check:generated')[2])->toBe('@php vendor/bin/testbench cms:generate --ansi')
        ->and(ComposerScripts::steps('check:generated')[3])->toBe('@php tools/bin/generate-protocol.php')
        ->and(ComposerScripts::steps('generate:protocol'))->toBe(['@php tools/bin/generate-protocol.php'])
        ->and(ComposerScripts::steps('check:generated:committed')[0])->toStartWith('git diff --exit-code -- '.$paths.' || ')
        ->and(ComposerScripts::steps('check:generated:committed')[1])->toStartWith('git ls-files --others --exclude-standard -- '.$paths.' | ')
        ->and(ComposerScripts::steps('check:generated'))->toBe([
            ...ComposerScripts::steps('check:generated:committed'),
            '@php vendor/bin/testbench cms:generate --ansi',
            '@php tools/bin/generate-protocol.php',
            ...ComposerScripts::steps('check:generated:committed'),
        ]);
});

it('points the gate at the directories cms:generate writes in the workbench', function (): void {
    expect([config('cbox-cms.generators.php_directory'), config('cbox-cms.generators.typescript_directory'), config('cbox-cms.generators.migrations_directory')])->toBe(GENERATED_PATHS);
});

it('passes on a clean tree and leaves it clean', function (): void {
    $root = gateRepository();

    [$status, $output] = runGate($root);

    expect($status)->toBe(0, $output)
        ->and(git($root, 'status', '--porcelain'))->toBe('');
});

it('fails after a manual edit to a generated PHP file, and passes again after git checkout of it', function (): void {
    $root = gateRepository();
    appendTo($root.'/workbench/app/Cms/Generated/TypeHandle.php', "// edited by hand\n");

    [$edited, $output] = runGate($root);
    git($root, 'checkout', '--', 'workbench/app/Cms/Generated/TypeHandle.php');
    [$restored] = runGate($root);

    expect($edited)->not->toBe(0)
        ->and($output)->toContain('+// edited by hand')
        ->and($output)->toContain('The generated code above is not the committed code.')
        ->and($restored)->toBe(0);
});

it('fails after a field or a type is added to the blueprints without regenerating', function (string $file, string $addition, string $generated): void {
    $root = gateRepository();
    appendTo($root.'/workbench/schema/'.$file, $addition);

    [$status, $output] = runGate($root);

    expect($status)->not->toBe(0)
        ->and($output)->toContain($generated);
})->with([
    'a field' => ['fixture_article.yaml', SUMMARY_FIELD, "+                'fixture_summary' => 'text',"],
    'a type' => ['fixture_page.yaml', PAGE_TYPE, "+    case AppFixturePage = 'app:fixture_page';"],
]);

it('passes once the regenerated code is staged with the blueprint change', function (): void {
    $root = gateRepository();
    appendTo($root.'/workbench/schema/fixture_article.yaml', SUMMARY_FIELD);
    app(Kernel::class)->call('cms:generate');
    git($root, 'add', '--all');

    [$status, $output] = runGate($root);

    expect($status)->toBe(0, $output);
});

it('fails after a manual edit to a type table migration or schema lock, and passes again after git checkout of it', function (string $file): void {
    $root = gateRepository();
    appendTo($root.'/workbench/database/migrations/cms/'.$file, "\n");

    [$edited, $output] = runGate($root);
    git($root, 'checkout', '--', 'workbench/database/migrations/cms/'.$file);
    [$restored, $restoredOutput] = runGate($root);

    expect($edited)->not->toBe(0)
        ->and($output)->toContain('The generated code above is not the committed code.')
        ->and($output)->toContain('workbench/database/migrations/cms/'.$file)
        ->and($restored)->toBe(0, $restoredOutput);
})->with(['a migration' => ['app__fixture_article_0001_create.php'], 'a schema lock' => ['app__fixture_article.lock']]);

it('writes the next step of the lock and an add_columns migration for a new optional field, which the gate wants committed', function (): void {
    $root = gateRepository();
    appendTo($root.'/workbench/schema/fixture_article.yaml', SUMMARY_FIELD);

    [$status, $output] = runGate($root);

    expect($status)->not->toBe(0)
        ->and($output)->toContain('+++ b/workbench/database/migrations/cms/app__fixture_article.lock')
        ->and($output)->toContain('+            "name": "fixture_summary",')
        ->and($output)->toContain('+            "step": 4,')
        ->and((string) file_get_contents($root.'/workbench/database/migrations/cms/app__fixture_article_0004_add_columns.php'))->toContain('add column if not exists "fixture_summary" text');
});

it('fails when a field of a type that has a table is removed, and writes nothing', function (): void {
    $root = gateRepository();
    $blueprint = $root.'/workbench/schema/fixture_article.yaml';
    $contents = (string) file_get_contents($blueprint);
    SchemaFixtures::write($blueprint, substr($contents, 0, (int) strpos($contents, '  - handle: fixture_reading_minutes')).substr($contents, (int) strpos($contents, '  - handle: fixture_featured')));
    git($root, 'add', '--all');

    [$status, $output] = runGate($root);

    expect($status)->not->toBe(0)
        ->and($output)->toContain('[generate_field_removed]')
        ->and(git($root, 'status', '--porcelain'))->toBe("M  workbench/schema/fixture_article.yaml\n");
});

it('fails after a manual edit to a kernel codec, and passes again after git checkout of it', function (): void {
    $root = gateRepository();
    appendTo($root.'/'.PROTOCOL_PATH.'/ReceiptCodecV1.php', "// edited by hand\n");

    [$edited, $output] = runGate($root);
    git($root, 'checkout', '--', PROTOCOL_PATH.'/ReceiptCodecV1.php');
    [$restored] = runGate($root);

    expect($edited)->not->toBe(0)
        ->and($output)->toContain('+// edited by hand')
        ->and($output)->toContain('The generated code above is not the committed code.')
        ->and($restored)->toBe(0);
});

it('fails after a kernel schema changes without regenerating its codec, and passes once the codec is staged with it', function (): void {
    $root = gateRepository();
    $schema = $root.'/'.ProtocolSchemas::SCHEMA_DIRECTORY.'/problem.v1.json';
    SchemaFixtures::write($schema, str_replace('"minimum": 100,', '"minimum": 200,', (string) file_get_contents($schema)));

    [$changed, $output] = runGate($root);
    git($root, 'add', '--all');
    [$staged, $stagedOutput] = runGate($root);

    expect($changed)->not->toBe(0)
        ->and($output)->toContain('JsonValues::integer($value, $at, min: 200, max: 599)')
        ->and($staged)->toBe(0, $stagedOutput);
});

it('fails on an untracked file in the kernel codecs\' directory', function (): void {
    $root = gateRepository();
    SchemaFixtures::write($root.'/'.PROTOCOL_PATH.'/HandWrittenCodec.php', "<?php\n");

    [$status, $output] = runGate($root);

    expect($status)->not->toBe(0)
        ->and($output)->toContain('Untracked generated file: '.PROTOCOL_PATH.'/HandWrittenCodec.php');
});

it('fails after a page schema of the panel changes without regenerating its codec and TypeScript, and passes once they are staged', function (): void {
    $root = gateRepository();
    $schema = $root.'/'.PanelPageSchemas::SCHEMA_DIRECTORY.'/home.v1.json';
    SchemaFixtures::write($schema, str_replace('"The address the logout posts to."', '"Where the logout posts."', (string) file_get_contents($schema)));
    git($root, 'add', '--all');

    [$changed, $output] = runGate($root);
    git($root, 'add', '--all');
    [$staged, $stagedOutput] = runGate($root);

    expect($changed)->not->toBe(0)
        ->and($output)->toContain(PanelPageSchemas::TYPESCRIPT_DIRECTORY.'/pages/HomePageV1.ts', 'Where the logout posts.')
        ->and($staged)->toBe(0, $stagedOutput);
});

it('fails after a manual edit to the panel\'s generated TypeScript', function (): void {
    $root = gateRepository();
    appendTo($root.'/'.PanelPageSchemas::TYPESCRIPT_DIRECTORY.'/pages/LoginPageV1.ts', "// edited by hand\n");

    [$status, $output] = runGate($root);

    expect($status)->not->toBe(0)
        ->and($output)->toContain('The generated code above is not the committed code.', 'edited by hand');
});

it('fails on an untracked file in a generated directory', function (): void {
    $root = gateRepository();
    SchemaFixtures::write($root.'/workbench/app/Cms/Generated/Extra.php', "<?php\n");

    [$status, $output] = runGate($root);

    expect($status)->not->toBe(0)
        ->and($output)->toContain('Untracked generated file: workbench/app/Cms/Generated/Extra.php')
        ->and(is_file($root.'/workbench/app/Cms/Generated/Extra.php'))->toBeTrue();
});

it('fails when a file cms:generate writes is not committed', function (): void {
    $root = gateRepository();
    git($root, 'rm', '--quiet', '--cached', 'workbench/resources/js/cms/generated/index.ts');
    git($root, 'commit', '--quiet', '--message=untrack');

    [$status, $output] = runGate($root);

    expect($status)->not->toBe(0)
        ->and($output)->toContain('Untracked generated file: workbench/resources/js/cms/generated/index.ts');
});

/**
 * Twelve types with long handles, every core field type, an extension field of another owner, and
 * a type of that owner and one of the app with the same handle, so the union breaks over lines,
 * every mapping is written and the tools accept two types that share a handle.
 */
function largerSchema(SchemaRoot $app): CompiledSchema
{
    $acme = SchemaFixtures::root('acme', 'vendor/acme/schema', $app->base);
    $types = [];

    foreach (range(1, 12) as $number) {
        $fields = ['title' => 'text', 'body_'.$number => 'rich_text'];

        foreach (new FieldTypeRegistry(new CoreFieldTypes)->names() as $type) {
            $fields['a_'.$type] = $type;
        }

        $types[] = SchemaFixtures::type($app, sprintf('fairly_long_type_handle_number_%d', $number), $fields);
    }

    $product = SchemaFixtures::type($acme, 'product', ['title' => 'text']);
    $appProduct = SchemaFixtures::type($app, 'product', ['sku' => 'text']);

    return SchemaFixtures::compiled(new Blueprints([...$types, $product, $appProduct], [
        SchemaFixtures::extension($app, 'shop/product.yaml', $product->typeId, ['tax_code' => 'text']),
    ]));
}

it('generates code that Pint, Rector, PHPStan, tsc, ESLint and Prettier accept unchanged', function (bool $empty, string $union): void {
    $directory = SchemaFixtures::scratch();
    $app = SchemaFixtures::root(base: $directory);
    $target = new GenerationTarget($directory, [$app], 'app/Generated', 'Cbox\Cms\Probe\Generated', 'js/generated');
    $schema = $empty ? new CompiledSchema([]) : largerSchema($app);
    $files = new GeneratorRunner([new PhpTypeHandleEnum, new TypeScriptTypeHandles])->run($schema, $target)->files;

    [$php, $typeScript] = [$files[0]->contents, $files[1]->contents];
    $phpFile = $directory.'/TypeHandle.php';
    file_put_contents($phpFile, $php);

    $pint = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/pint.php', '--test', '--config='.Phpstan::root().'/pint.json', $phpFile], Phpstan::root());
    $pint->run();
    $rector = new Process([Phpstan::root().'/vendor/bin/rector', 'process', '--dry-run', '--no-progress-bar', $phpFile], Phpstan::root());
    $rector->run();
    $analysis = Phpstan::analyse($phpFile);

    [$lint, $typecheck, $format] = Node::withProbe('ts', $typeScript, static fn (string $path): array => [
        Node::tool('eslint', ['--max-warnings=0', $path]),
        Node::run(['npm', 'run', '--silent', 'typecheck']),
        Node::tool('prettier', ['--check', $path]),
    ]);

    expect($typeScript)->toContain($union)
        ->and($pint->getExitCode())->toBe(0, $pint->getOutput())
        ->and($rector->getExitCode())->toBe(0, $rector->getOutput())
        ->and($analysis->identifiers)->toBe([])
        ->and($analysis->exitCode)->toBe(0)
        ->and($lint->getExitCode())->toBe(0, $lint->getOutput())
        ->and($typecheck->getExitCode())->toBe(0, $typecheck->getOutput())
        ->and($format->getExitCode())->toBe(0, $format->getOutput());
})->with([
    'a larger schema with an extension' => [false, "export type TypeHandle =\n  | 'acme:product'\n  | 'app:fairly_long_type_handle_number_1'\n"],
    'no types' => [true, "export type TypeHandle = never;\n"],
]);

it('gives tsc the extension fields of a type at ext.<namespace>.<handle> and the owner\'s fields at their handles', function (): void {
    $directory = SchemaFixtures::scratch();
    $app = SchemaFixtures::root(base: $directory);
    $target = new GenerationTarget($directory, [$app], 'app/Generated', 'Cbox\Cms\Probe\Generated', 'js/generated');
    $typeScript = new TypeScriptTypeHandles()->generate(largerSchema($app), $target)[0]->contents;

    $usage = <<<'TS'

        export const taxCode: TypeFields['acme:product']['ext']['app']['tax_code'] = 'text';
        export const title: TypeFields['acme:product']['title'] = 'text';
        export const sku: TypeFields['app:product']['sku'] = 'text';
        export const product: TypeHandle = 'app:product';

        TS;
    $misuse = <<<'TS'

        export const taxCode: TypeFields['acme:product']['ext__app__tax_code'] = 'text';

        TS;

    $accepted = Node::withProbe('ts', $typeScript.$usage, static fn (): Process => Node::run(['npm', 'run', '--silent', 'typecheck']));
    $refused = Node::withProbe('ts', $typeScript.$misuse, static fn (): Process => Node::run(['npm', 'run', '--silent', 'typecheck']));

    expect($accepted->getExitCode())->toBe(0, $accepted->getOutput())
        ->and($refused->getExitCode())->not->toBe(0)
        ->and($refused->getOutput())->toContain("Property 'ext__app__tax_code' does not exist");
});
