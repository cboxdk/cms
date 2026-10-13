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
        $directory.'/protocol/DeliveryExplanationV1.ts',
        $directory.'/protocol/DeliveryV1.ts',
        $directory.'/protocol/DryRunSummaryV1.ts',
        $directory.'/protocol/EnvelopeV1.ts',
        $directory.'/protocol/ExplainedPathV1.ts',
        $directory.'/protocol/PathExplanationV1.ts',
        $directory.'/protocol/ProblemV1.ts',
        $directory.'/protocol/ReceiptV1.ts',
        $directory.'/protocol/TypeEditorV1.ts',
        $directory.'/protocol/DeliveryFragmentV1.ts',
        $directory.'/protocol/PanelBundleV1.ts',
        $directory.'/protocol/PanelBundleSignatureV1.ts',
        $directory.'/protocol/GrantBootstrapRoleV1.ts',
        $directory.'/protocol/ActivateActorV1.ts',
        $directory.'/protocol/DeactivateActorV1.ts',
        $directory.'/protocol/RegisterActorV1.ts',
        $directory.'/protocol/CreateEntryV1.ts',
        $directory.'/protocol/CreateEntryV2.ts',
        $directory.'/protocol/PublishEntryV1.ts',
        $directory.'/protocol/ReviseEntryV1.ts',
        $directory.'/protocol/ReviseEntryV2.ts',
        $directory.'/protocol/UnpublishEntryV1.ts',
        $directory.'/protocol/AssignGrantV1.ts',
        $directory.'/protocol/RevokeGrantV1.ts',
        $directory.'/protocol/CreateMountV1.ts',
        $directory.'/protocol/SetMountOverrideV1.ts',
        $directory.'/protocol/ArchiveNodeV1.ts',
        $directory.'/protocol/CreateNodeV1.ts',
        $directory.'/protocol/MoveNodeV1.ts',
        $directory.'/protocol/SetNodeLabelV1.ts',
        $directory.'/protocol/SetNodeRouteV1.ts',
        $directory.'/protocol/ChangeNodeRouteV2.ts',
        $directory.'/protocol/CreatePlacementV1.ts',
        $directory.'/protocol/SetPlacementSlugV1.ts',
        $directory.'/protocol/SetPlacementWindowV1.ts',
        $directory.'/protocol/CreateRoleV1.ts',
        $directory.'/protocol/SetRolePermissionsV1.ts',
        $directory.'/protocol/AddSiteLocaleV1.ts',
        $directory.'/protocol/RegisterSiteV1.ts',
        $directory.'/protocol/RemoveSiteLocaleV1.ts',
        $directory.'/protocol/ReleaseVariantV1.ts',
        $directory.'/protocol/ReleaseVariantV2.ts',
        $directory.'/protocol/ListActionsV1.ts',
        $directory.'/protocol/ActionListV1.ts',
        $directory.'/protocol/ListActorsV1.ts',
        $directory.'/protocol/ActorListV1.ts',
        $directory.'/protocol/WhoAmIV1.ts',
        $directory.'/protocol/ActorMeV1.ts',
        $directory.'/protocol/GetEntryV1.ts',
        $directory.'/protocol/EntryDetailV1.ts',
        $directory.'/protocol/ListEntriesV1.ts',
        $directory.'/protocol/EntryListV1.ts',
        $directory.'/protocol/ListGrantsV1.ts',
        $directory.'/protocol/GrantListV1.ts',
        $directory.'/protocol/ListNodesV1.ts',
        $directory.'/protocol/NodeListV1.ts',
        $directory.'/protocol/ResolvePathV1.ts',
        $directory.'/protocol/ResolvedPathV1.ts',
        $directory.'/protocol/ListRolesV1.ts',
        $directory.'/protocol/RoleListV1.ts',
        $directory.'/protocol/DescribeTypeV1.ts',
        $directory.'/protocol/TypeDescriptionV1.ts',
        $directory.'/protocol/ListTypesV1.ts',
        $directory.'/protocol/TypeListV1.ts',
    ])
        ->and($files[0]->contents)->toBe(new TypeScriptRuntime()->source())
        ->and($files[1]->contents)->toContain("export function validateAppArticleV1(value: unknown): Validation<AppArticleV1> {\n")
        ->and($files[1]->contents)->toContain("import { validate, type ObjectRule, type Validation } from '../validation';\n")
        ->and($files[10]->contents)->toContain("export function validateReceiptV1(value: unknown): Validation<ReceiptV1> {\n")
        ->and($files[19]->contents)->toContain("export function validateCreateEntryV1(value: unknown): Validation<CreateEntryV1> {\n")
        ->and($files[19]->contents)->toContain("import { validate, type ObjectRule, type FieldValues, type Validation } from '../validation';\n")
        ->and($files[19]->contents)->toContain("  fields: FieldValues;\n")
        ->and($files[4]->contents)->toContain("import { validate, type ObjectRule, type JsonObject, type Validation } from '../validation';\n")
        ->and($files[4]->contents)->toContain("  data: JsonObject;\n")
        ->and(new TypeScriptContracts(new TypeScriptRuntime()->source(...), static fn (): array => [])->directory($target))->toBe($directory);
});

it('reads the kernel\'s contracts in the order of their schemas, and names every schema it cannot read', function (): void {
    $contracts = new KernelContracts()->read();
    $missing = SchemaFixtures::scratch();

    expect(array_map(static fn (CodecContract $contract): string => $contract->codecClass, $contracts))->toBe([
        'DeliveryExplanationCodecV1',
        'DeliveryCodecV1',
        'DryRunSummaryCodecV1',
        'EnvelopeCodecV1',
        'ExplainedPathCodecV1',
        'PathExplanationCodecV1',
        'ProblemCodecV1',
        'ReceiptCodecV1',
        'TypeEditorCodecV1',
        'DeliveryFragmentCodecV1',
        'PanelBundleCodecV1',
        'PanelBundleSignatureCodecV1',
        'GrantBootstrapRoleCodecV1',
        'ActivateActorCodecV1',
        'DeactivateActorCodecV1',
        'RegisterActorCodecV1',
        'CreateEntryCodecV1',
        'CreateEntryCodecV2',
        'PublishEntryCodecV1',
        'ReviseEntryCodecV1',
        'ReviseEntryCodecV2',
        'UnpublishEntryCodecV1',
        'AssignGrantCodecV1',
        'RevokeGrantCodecV1',
        'CreateMountCodecV1',
        'SetMountOverrideCodecV1',
        'ArchiveNodeCodecV1',
        'CreateNodeCodecV1',
        'MoveNodeCodecV1',
        'SetNodeLabelCodecV1',
        'SetNodeRouteCodecV1',
        'ChangeNodeRouteCodecV2',
        'CreatePlacementCodecV1',
        'SetPlacementSlugCodecV1',
        'SetPlacementWindowCodecV1',
        'CreateRoleCodecV1',
        'SetRolePermissionsCodecV1',
        'AddSiteLocaleCodecV1',
        'RegisterSiteCodecV1',
        'RemoveSiteLocaleCodecV1',
        'ReleaseVariantCodecV1',
        'ReleaseVariantCodecV2',
        'ListActionsCodecV1',
        'ActionListCodecV1',
        'ListActorsCodecV1',
        'ActorListCodecV1',
        'WhoAmICodecV1',
        'ActorMeCodecV1',
        'GetEntryCodecV1',
        'EntryDetailCodecV1',
        'ListEntriesCodecV1',
        'EntryListCodecV1',
        'ListGrantsCodecV1',
        'GrantListCodecV1',
        'ListNodesCodecV1',
        'NodeListCodecV1',
        'ResolvePathCodecV1',
        'ResolvedPathCodecV1',
        'ListRolesCodecV1',
        'RoleListCodecV1',
        'DescribeTypeCodecV1',
        'TypeDescriptionCodecV1',
        'ListTypesCodecV1',
        'TypeListCodecV1',
    ]);

    try {
        new KernelContracts($missing)->read();
        $problems = [];
    } catch (GenerationFailed $failed) {
        $problems = $failed->problems;
    }

    expect(count($problems))->toBe(64)
        ->and($problems[0]->code)->toBe(GenerateErrorCode::SchemaMissing)
        ->and($problems[3]->message)->toContain('packages/contracts/resources/schemas/envelope.v1.json')
        ->and($problems[7]->message)->toContain('receipt.v1.json')
        ->and($problems[8]->message)->toContain('type-editor.v1.json')
        ->and($problems[13]->message)->toContain('packages/core/resources/schemas/commands/entry.create.v1.json')
        ->and($problems[21]->message)->toContain('packages/core/resources/schemas/commands/mount.create.v1.json')
        ->and($problems[39]->message)->toContain('packages/core/resources/schemas/delivery-fragment.v1.json')
        ->and($problems[40]->message)->toContain('packages/core/resources/schemas/panel-bundle-signature.v1.json')
        ->and($problems[41]->message)->toContain('packages/core/resources/schemas/panel-bundle.v1.json')
        ->and($problems[42]->message)->toContain('packages/core/resources/schemas/queries/action.list.result.v1.json')
        ->and($problems[44]->message)->toContain('packages/core/resources/schemas/queries/actor.list.result.v1.json')
        ->and($problems[56]->message)->toContain('packages/core/resources/schemas/queries/path.resolve.result.v1.json')
        ->and($problems[57]->message)->toContain('packages/core/resources/schemas/queries/path.resolve.v1.json')
        ->and($problems[59]->message)->toContain('packages/core/resources/schemas/queries/role.list.v1.json')
        ->and($problems[63]->message)->toContain('packages/core/resources/schemas/queries/type.list.v1.json');
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
