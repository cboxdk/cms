<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\TypeScript;

use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Generation\Boundary\TypeScriptRuntime;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptContracts;
use Cbox\Cms\Generators\Protocol\Boundary\KernelContracts;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Tests\Support\Node;
use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/*
 * The TypeScript of the contracts (PRD 11.12, GUARDRAILS 2.2): the runtime module, a module per
 * record and a module per kernel JSON Schema. The comprehensive example's TypeScript is exactly
 * the committed golden files below Fixtures/Comprehensive/typescript/generated, which tsconfig.json
 * includes, so gate 4 holds them to tsc and ESLint, and which Prettier accepts as they are.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/** The committed golden TypeScript of the comprehensive example, below its directory. */
const GOLDEN_TYPESCRIPT = 'typescript/generated';

/**
 * The generator with the runtime module of this generators module and the given kernel contracts.
 *
 * @param  list<CodecContract>  $protocol
 */
function typeScriptContracts(array $protocol = []): TypeScriptContracts
{
    return new TypeScriptContracts(new TypeScriptRuntime()->source(...), static fn (): array => $protocol);
}

/**
 * The code of the first problem of a generation failure.
 *
 * @param  callable(): mixed  $run
 */
function failedWith(callable $run): GenerateErrorCode
{
    try {
        $run();
    } catch (GenerationFailed $failed) {
        return $failed->problems[0]->code;
    }

    throw new LogicException('The generation did not fail.');
}

it('generates exactly the committed golden TypeScript of the comprehensive example, which Prettier accepts unchanged', function (): void {
    $files = typeScriptContracts()->generate(ComprehensiveExample::compile(), ComprehensiveExample::target());
    $golden = [];
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ComprehensiveExample::DIRECTORY.'/'.GOLDEN_TYPESCRIPT, FilesystemIterator::SKIP_DOTS));

    foreach ($entries as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile()) {
            $golden[substr($entry->getPathname(), strlen(ComprehensiveExample::DIRECTORY) + 1)] = (string) file_get_contents($entry->getPathname());
        }
    }

    ksort($golden, SORT_STRING);
    $generated = [];

    foreach ($files as $file) {
        $generated[$file->path] = $file->contents;
    }

    ksort($generated, SORT_STRING);
    $format = Node::tool('prettier', ['--check', '--ignore-path=.cache/no-prettier-ignore', 'packages/generators/tests/Descriptor/Fixtures/Comprehensive/'.GOLDEN_TYPESCRIPT]);

    expect(array_keys($generated))->toBe([GOLDEN_TYPESCRIPT.'/records/ShopProductV1.ts', GOLDEN_TYPESCRIPT.'/validation.ts'])
        ->and($golden)->toBe($generated)
        ->and($format->getExitCode())->toBe(0, $format->getOutput().$format->getErrorOutput());
});

it('writes the runtime module unchanged, a module per record and a module per kernel schema', function (): void {
    $target = SchemaFixtures::target();
    $schema = SchemaFixtures::schema(['article' => ['title' => 'text'], 'measurement' => ['reading' => 'decimal']]);
    $files = typeScriptContracts(new KernelContracts()->read())->generate($schema, $target);
    $paths = array_map(static fn (GeneratedFile $file): string => $file->path, $files);
    $directory = $target->typeScriptDirectory;

    expect($paths)->toBe([
        $directory.'/validation.ts',
        $directory.'/records/AppArticleV1.ts',
        $directory.'/records/AppMeasurementV1.ts',
        $directory.'/protocol/EnvelopeV1.ts',
        $directory.'/protocol/ProblemV1.ts',
        $directory.'/protocol/ReceiptV1.ts',
    ])
        ->and($files[0]->contents)->toBe(new TypeScriptRuntime()->source())
        ->and($files[1]->contents)->toContain("export function validateAppArticleV1(value: unknown): Validation<AppArticleV1> {\n")
        ->and($files[1]->contents)->toContain("import { validate, type ObjectRule, type Validation } from '../validation';\n")
        ->and($files[5]->contents)->toContain("export function validateReceiptV1(value: unknown): Validation<ReceiptV1> {\n")
        ->and(new TypeScriptContracts(new TypeScriptRuntime()->source(...), static fn (): array => [])->directory($target))->toBe($directory);
});

it('reads the kernel\'s contracts in the order of their schemas, and names every schema it cannot read', function (): void {
    $contracts = new KernelContracts()->read();
    $missing = SchemaFixtures::scratch();

    expect(array_map(static fn (CodecContract $contract): string => $contract->codecClass, $contracts))->toBe(['EnvelopeCodecV1', 'ProblemCodecV1', 'ReceiptCodecV1']);

    try {
        new KernelContracts($missing)->read();
        $problems = [];
    } catch (GenerationFailed $failed) {
        $problems = $failed->problems;
    }

    expect(count($problems))->toBe(3)
        ->and($problems[0]->code)->toBe(GenerateErrorCode::SchemaMissing)
        ->and($problems[0]->message)->toContain('packages/contracts/resources/schemas/envelope.v1.json')
        ->and($problems[2]->message)->toContain('receipt.v1.json');
});

it('refuses a kernel schema that is not valid with generate_schema_invalid', function (): void {
    $root = SchemaFixtures::scratch();

    foreach (['envelope.v1.json', 'problem.v1.json', 'receipt.v1.json'] as $schema) {
        SchemaFixtures::write($root.'/packages/contracts/resources/schemas/'.$schema, '{"$schema": "https://json-schema.org/draft/2020-12/schema", "type": "array"}');
    }

    expect(failedWith(static fn (): array => new KernelContracts($root)->read()))->toBe(GenerateErrorCode::SchemaInvalid);
});

it('refuses to generate without its runtime module, with generate_invalid_output', function (): void {
    $runtime = new TypeScriptRuntime(SchemaFixtures::scratch().'/validation.ts');

    expect(failedWith(static fn (): string => $runtime->source()))->toBe(GenerateErrorCode::InvalidOutput)
        ->and(failedWith(static fn (): array => new TypeScriptContracts($runtime->source(...), static fn (): array => [])->generate(SchemaFixtures::schema([]), SchemaFixtures::target())))->toBe(GenerateErrorCode::InvalidOutput);
});
