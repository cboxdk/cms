<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Protocol;

use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ComposerScripts;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Protocol\Boundary\GenerateProtocolOptions;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/*
 * `composer generate:protocol` (tools/bin/generate-protocol.php, GUARDRAILS 2.2): it writes the
 * codecs of the kernel's JSON Schemas below a root, the same bytes as the committed codecs,
 * removes every other file in their directory, changes nothing on a second run, and writes nothing
 * when a schema is missing or invalid, with the catalog's exit code.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

const PROTOCOL_CODECS = ['EnvelopeCodecV1.php', 'ProblemCodecV1.php', 'ReceiptCodecV1.php'];

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
 * A tree with a copy of this checkout's kernel schemas.
 */
function protocolTree(): string
{
    $root = ScratchDirectory::make();

    foreach (['envelope.v1.json', 'problem.v1.json', 'receipt.v1.json'] as $schema) {
        ScratchDirectory::write($root.'/'.ProtocolSchemas::SCHEMA_DIRECTORY.'/'.$schema, protocolRead(Phpstan::root().'/'.ProtocolSchemas::SCHEMA_DIRECTORY.'/'.$schema));
    }

    return $root;
}

it('writes the committed codecs into a tree, removes other files there, and a second run changes nothing', function (): void {
    $root = protocolTree();
    $directory = ProtocolSchemas::PHP_DIRECTORY;
    ScratchDirectory::write($root.'/'.$directory.'/HandWrittenCodec.php', "<?php\n");

    [$exit, $output, $errors] = runGenerateProtocol('--root='.$root);

    expect([$exit, $errors])->toBe([0, ''])
        ->and($output)->toBe(implode('', array_map(static fn (string $file): string => "generate:protocol: wrote {$directory}/{$file}\n", PROTOCOL_CODECS))."generate:protocol: removed {$directory}/HandWrittenCodec.php\n")
        ->and(is_file($root.'/'.$directory.'/HandWrittenCodec.php'))->toBeFalse();

    foreach (PROTOCOL_CODECS as $file) {
        expect(protocolRead($root.'/'.$directory.'/'.$file))->toBe(protocolRead(Phpstan::root().'/'.$directory.'/'.$file));
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
        ->and(ComposerScripts::description('generate:protocol'))->toContain(ProtocolSchemas::PHP_DIRECTORY, '--root=<dir>')
        ->and(ComposerScripts::description('check:generated'))->toContain('kernel\'s codecs');
});
