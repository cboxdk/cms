<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Boundary;

use Cbox\Cms\Mcp\Boundary\KernelSchemas;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use ReflectionMethod;
use UnexpectedValueException;

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/*
 * The kernel's envelope schema, read from the contracts module of the same package, byte for byte.
 */

it('reads envelope.v1.json of the contracts module', function (): void {
    $path = Codebase::root().'/packages/'.KernelSchemas::DIRECTORY.'/'.KernelSchemas::ENVELOPE;

    expect(KernelSchemas::envelope()->json)->toBe(file_get_contents($path));
});

/**
 * What the private reader of KernelSchemas makes of the file at $path: its text, or the refusal.
 */
function kernelSchemaRead(string $path): string|UnexpectedValueException
{
    try {
        $read = new ReflectionMethod(KernelSchemas::class, 'read')->invoke(null, $path);

        return is_string($read) ? $read : '';
    } catch (UnexpectedValueException $refused) {
        return $refused;
    }
}

it('refuses a schema file that is missing, empty or named through a stream wrapper, with the exception code 0', function (string $kind): void {
    $directory = ScratchDirectory::make();
    $path = match ($kind) {
        'missing' => $directory.'/nosuch.json',
        'empty' => ScratchDirectory::write($directory.'/empty.json'),
        default => 'php://memory',
    };

    $refused = kernelSchemaRead($path);

    expect($refused)->toBeInstanceOf(UnexpectedValueException::class)
        ->and($refused instanceof UnexpectedValueException ? [$refused->getMessage(), $refused->getCode()] : null)
        ->toBe([sprintf('The kernel schema %s of cboxdk/cms does not exist or cannot be read. Install cboxdk/cms again.', $path), 0]);
})->with(['missing', 'empty', 'a stream wrapper']);

it('reads a schema file of one byte', function (): void {
    expect(kernelSchemaRead(ScratchDirectory::write(ScratchDirectory::make().'/one.json', '{')))->toBe('{');
});
