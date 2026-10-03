<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Protocol;

use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ComposerScripts;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Protocol\Boundary\GenerateProtocolOptions;
use Cbox\Cms\Tooling\Protocol\Domain\PanelPageSchemas;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/*
 * `composer generate:protocol` (tools/bin/generate-protocol.php, GUARDRAILS 2.2): it writes the
 * codecs of the kernel's JSON Schemas, and the codecs and TypeScript of the panel's page schemas,
 * below a root, the same bytes as the committed codecs,
 * removes every other file in their directory, changes nothing on a second run, and writes nothing
 * when a schema is missing or invalid, with the catalog's exit code.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

const PROTOCOL_CODECS = [
    'ActivateActorCodecV1.php',
    'ActorListCodecV1.php',
    'AssignGrantCodecV1.php',
    'CreateEntryCodecV1.php',
    'CreatePlacementCodecV1.php',
    'CreateRoleCodecV1.php',
    'DeactivateActorCodecV1.php',
    'DeliveryCodecV1.php',
    'DeliveryExplanationCodecV1.php',
    'DeliveryFragmentCodecV1.php',
    'EnvelopeCodecV1.php',
    'ExplainedPathCodecV1.php',
    'GrantBootstrapRoleCodecV1.php',
    'GrantListCodecV1.php',
    'KernelCommandCodecs.php',
    'KernelQueryCodecs.php',
    'ListActorsCodecV1.php',
    'ListGrantsCodecV1.php',
    'ListNodesCodecV1.php',
    'ListRolesCodecV1.php',
    'NodeListCodecV1.php',
    'PathExplanationCodecV1.php',
    'ProblemCodecV1.php',
    'PublishEntryCodecV1.php',
    'ReceiptCodecV1.php',
    'RegisterActorCodecV1.php',
    'RegisterSiteCodecV1.php',
    'ReleaseVariantCodecV1.php',
    'ResolvePathCodecV1.php',
    'ResolvedPathCodecV1.php',
    'ReviseEntryCodecV1.php',
    'RevokeGrantCodecV1.php',
    'RoleListCodecV1.php',
    'SetPlacementWindowCodecV1.php',
    'SetRolePermissionsCodecV1.php',
    'UnpublishEntryCodecV1.php',
];

/** What generate:protocol writes for the panel's pages, below the root, sorted. */
const PANEL_PAGE_FILES = [
    'js/panel/src/generated/pages/ForgotPasswordPageV1.ts',
    'js/panel/src/generated/pages/HomePageV1.ts',
    'js/panel/src/generated/pages/LoginPageV1.ts',
    'js/panel/src/generated/pages/NotFoundPageV1.ts',
    'js/panel/src/generated/pages/ResetPasswordPageV1.ts',
    'js/panel/src/generated/validation.ts',
    'packages/panel/src/Boundary/Generated/ForgotPasswordPageCodecV1.php',
    'packages/panel/src/Boundary/Generated/HomePageCodecV1.php',
    'packages/panel/src/Boundary/Generated/LoginPageCodecV1.php',
    'packages/panel/src/Boundary/Generated/NotFoundPageCodecV1.php',
    'packages/panel/src/Boundary/Generated/ResetPasswordPageCodecV1.php',
];

/**
 * Runs tools/bin/generate-protocol.php of this checkout with the arguments.
 *
 * @return array{int, string, string}
 */
function runGenerateProtocol(string ...$arguments): array
{
    $process = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/generate-protocol.php', ...array_values($arguments)], Phpstan::root(), null, null, 120);
    $process->run();

    return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
}

function protocolRead(string $path): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Cannot read {$path}.");
    }

    return $contents;
}

/**
 * A tree with a copy of this checkout's kernel schemas, the contracts', the commands' and the
 * queries', and of the schemas of the panel's pages.
 */
function protocolTree(): string
{
    $root = ScratchDirectory::make();

    foreach ([...ProtocolSchemas::all(), ...PanelPageSchemas::all()] as $binding) {
        ScratchDirectory::write($root.'/'.$binding->path(), protocolRead(Phpstan::root().'/'.$binding->path()));
    }

    return $root;
}

it('writes the committed codecs into a tree, removes other files there, and a second run changes nothing', function (): void {
    $root = protocolTree();
    $directory = ProtocolSchemas::PHP_DIRECTORY;
    ScratchDirectory::write($root.'/'.$directory.'/HandWrittenCodec.php', "<?php\n");
    ScratchDirectory::write($root.'/'.PanelPageSchemas::TYPESCRIPT_DIRECTORY.'/pages/HandWritten.ts', "export {};\n");
    $written = [...PANEL_PAGE_FILES, ...array_map(static fn (string $file): string => $directory.'/'.$file, PROTOCOL_CODECS)];
    sort($written, SORT_STRING);
    $removed = [PanelPageSchemas::TYPESCRIPT_DIRECTORY.'/pages/HandWritten.ts', $directory.'/HandWrittenCodec.php'];

    [$exit, $output, $errors] = runGenerateProtocol('--root='.$root);

    expect([$exit, $errors])->toBe([0, ''])
        ->and($output)->toBe(implode('', array_map(static fn (string $path): string => "generate:protocol: wrote {$path}\n", $written)).implode('', array_map(static fn (string $path): string => "generate:protocol: removed {$path}\n", $removed)))
        ->and(is_file($root.'/'.$directory.'/HandWrittenCodec.php'))->toBeFalse()
        ->and(is_file($root.'/'.$removed[0]))->toBeFalse();

    foreach ($written as $path) {
        expect(protocolRead($root.'/'.$path))->toBe(protocolRead(Phpstan::root().'/'.$path));
    }

    [$again, $currentOutput] = runGenerateProtocol('--root='.$root);

    expect([$again, $currentOutput])->toBe([0, "generate:protocol: the codecs are current.\n"]);
});

it('writes nothing and exits 66 for a missing schema and 65 for an invalid one', function (): void {
    $missing = protocolTree();
    unlink($missing.'/'.ProtocolSchemas::SCHEMA_DIRECTORY.'/problem.v1.json');
    $invalid = protocolTree();
    $receipt = $invalid.'/'.ProtocolSchemas::SCHEMA_DIRECTORY.'/receipt.v1.json';
    ScratchDirectory::write($receipt, str_replace('"additionalProperties": false,', '', protocolRead($receipt)));

    [$missingExit, $missingOutput, $missingErrors] = runGenerateProtocol('--root='.$missing);
    [$invalidExit, , $invalidErrors] = runGenerateProtocol('--root='.$invalid);

    expect([$missingExit, $missingOutput])->toBe([66, ''])
        ->and($missingErrors)->toContain('[generate_schema_missing] The schema packages/contracts/resources/schemas/problem.v1.json does not exist or cannot be read.')
        ->and(is_dir($missing.'/'.ProtocolSchemas::PHP_DIRECTORY))->toBeFalse()
        ->and($invalidExit)->toBe(65)
        ->and($invalidErrors)->toContain('[generate_schema_invalid] receipt.v1.json #: needs "additionalProperties": false')
        ->and(is_dir($invalid.'/'.ProtocolSchemas::PHP_DIRECTORY))->toBeFalse();
});

it('writes nothing when a page schema of the panel is missing or invalid', function (): void {
    $missing = protocolTree();
    unlink($missing.'/'.PanelPageSchemas::SCHEMA_DIRECTORY.'/home.v1.json');
    $invalid = protocolTree();
    $login = $invalid.'/'.PanelPageSchemas::SCHEMA_DIRECTORY.'/login.v1.json';
    ScratchDirectory::write($login, str_replace('"login_rejected",', '', protocolRead($login)));

    [$missingExit, , $missingErrors] = runGenerateProtocol('--root='.$missing);
    [$invalidExit, , $invalidErrors] = runGenerateProtocol('--root='.$invalid);

    expect($missingExit)->toBe(66)
        ->and($missingErrors)->toContain('[generate_schema_missing] The schema packages/panel/resources/schemas/pages/home.v1.json does not exist or cannot be read.')
        ->and($invalidExit)->toBe(65)
        ->and($invalidErrors)->toContain('[generate_schema_invalid] login.v1.json #/$defs/refusals/properties/email')
        ->and($invalidErrors)->toContain('LoginRefusal has the values ["validation_required","login_rejected","login_rate_limited"]');

    foreach ([$missing, $invalid] as $root) {
        expect(is_dir($root.'/'.PanelPageSchemas::PHP_DIRECTORY))->toBeFalse()
            ->and(is_dir($root.'/'.PanelPageSchemas::TYPESCRIPT_DIRECTORY))->toBeFalse()
            ->and(is_dir($root.'/'.ProtocolSchemas::PHP_DIRECTORY))->toBeFalse();
    }
});

it('exits 2 on a usage error, and parses --root once', function (): void {
    [$usage, , $usageErrors] = runGenerateProtocol('--fix');

    expect([$usage, $usageErrors])->toBe([2, "Unknown or repeated argument [--fix].\n".GenerateProtocolOptions::USAGE."\n"])
        ->and(GenerateProtocolOptions::parse([])->root)->toBeNull()
        ->and(GenerateProtocolOptions::parse(['--root=/tmp/tree'])->root)->toBe('/tmp/tree')
        ->and(fn (): GenerateProtocolOptions => GenerateProtocolOptions::parse(['--root=']))->toThrow(InvalidArgumentException::class, 'Unknown or repeated argument [--root=].')
        ->and(fn (): GenerateProtocolOptions => GenerateProtocolOptions::parse(['--root=a', '--root=b']))->toThrow(InvalidArgumentException::class, 'Unknown or repeated argument [--root=b].');
});

it('runs the script as composer generate:protocol, with a description', function (): void {
    expect(ComposerScripts::steps('generate:protocol'))->toBe(['@php tools/bin/generate-protocol.php'])
        ->and(ComposerScripts::description('generate:protocol'))->toContain(ProtocolSchemas::PHP_DIRECTORY, PanelPageSchemas::PHP_DIRECTORY, PanelPageSchemas::TYPESCRIPT_DIRECTORY, '--root=<dir>')
        ->and(ComposerScripts::description('check:generated'))->toContain('kernel\'s codecs');
});
