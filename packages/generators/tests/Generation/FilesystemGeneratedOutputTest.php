<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Generation;

use Cbox\Cms\Generators\Generation\Adapter\FilesystemGeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Tests\Support\RecordingStreamWrapper;
use PHPUnit\Framework\Assert;

/*
 * Writing the generated code: only files whose contents differ are written, and stale files in
 * the owned directories are removed.
 */

afterEach(function (): void {
    RecordingStreamWrapper::unregister();
    SchemaFixtures::cleanUp();
});

function outputResult(): GenerationResult
{
    return new GenerationResult(
        [
            new GeneratedFile('app/Cms/Generated/TypeHandle.php', "<?php\n"),
            new GeneratedFile('resources/js/cms/generated/index.ts', "export {};\n"),
        ],
        ['app/Cms/Generated', 'resources/js/cms/generated'],
    );
}

it('is the output in the container', function (): void {
    expect(app(GeneratedOutput::class))->toBeInstanceOf(FilesystemGeneratedOutput::class);
});

it('writes every file and creates the directories', function (): void {
    $root = SchemaFixtures::scratch();

    $report = new FilesystemGeneratedOutput()->write($root, outputResult());

    expect($report->written)->toBe(['app/Cms/Generated/TypeHandle.php', 'resources/js/cms/generated/index.ts'])
        ->and($report->unchanged)->toBe([])
        ->and($report->removed)->toBe([])
        ->and($report->changed())->toBeTrue()
        ->and(file_get_contents($root.'/app/Cms/Generated/TypeHandle.php'))->toBe("<?php\n")
        ->and(SchemaFixtures::files($root))->toBe(['app/Cms/Generated/TypeHandle.php', 'resources/js/cms/generated/index.ts']);
});

it('leaves a file with the right contents untouched, so a second run changes nothing', function (): void {
    $root = SchemaFixtures::scratch();
    $output = new FilesystemGeneratedOutput;
    $output->write($root, outputResult());
    touch($root.'/app/Cms/Generated/TypeHandle.php', 1_000_000_000);
    clearstatcache();

    $report = $output->write($root, outputResult());
    clearstatcache();

    expect($report->written)->toBe([])
        ->and($report->unchanged)->toBe(['app/Cms/Generated/TypeHandle.php', 'resources/js/cms/generated/index.ts'])
        ->and($report->changed())->toBeFalse()
        ->and(filemtime($root.'/app/Cms/Generated/TypeHandle.php'))->toBe(1_000_000_000);
});

it('rewrites a file that was edited by hand', function (): void {
    $root = SchemaFixtures::scratch();
    $output = new FilesystemGeneratedOutput;
    $output->write($root, outputResult());
    file_put_contents($root.'/app/Cms/Generated/TypeHandle.php', "<?php // edited\n");

    $report = $output->write($root, outputResult());

    expect($report->written)->toBe(['app/Cms/Generated/TypeHandle.php'])
        ->and(file_get_contents($root.'/app/Cms/Generated/TypeHandle.php'))->toBe("<?php\n");
});

it('removes stale files in the owned directories, and nothing outside them', function (): void {
    $root = SchemaFixtures::scratch();
    SchemaFixtures::write($root.'/app/Cms/Generated/OldType.php', "<?php\n");
    SchemaFixtures::write($root.'/app/Cms/Generated/Records/Old.php', "<?php\n");
    SchemaFixtures::write($root.'/app/Cms/Manual.php', "<?php\n");
    SchemaFixtures::write($root.'/resources/js/cms/app.ts', "export {};\n");

    $report = new FilesystemGeneratedOutput()->write($root, outputResult());

    expect($report->removed)->toBe(['app/Cms/Generated/OldType.php', 'app/Cms/Generated/Records/Old.php'])
        ->and(SchemaFixtures::files($root))->toBe([
            'app/Cms/Generated/TypeHandle.php',
            'app/Cms/Manual.php',
            'resources/js/cms/app.ts',
            'resources/js/cms/generated/index.ts',
        ]);
});

it('stops with generate_output_unwritable when a file cannot be written', function (): void {
    $root = SchemaFixtures::scratch();
    mkdir($root.'/app/Cms/Generated', 0o775, true);
    chmod($root.'/app/Cms/Generated', 0o555);

    try {
        new FilesystemGeneratedOutput()->write($root, outputResult());
        Assert::fail('The output wrote to a read-only directory.');
    } catch (GenerationFailed $failed) {
        expect($failed->codes())->toBe([GenerateErrorCode::OutputUnwritable])
            ->and($failed->getMessage())->toContain('app/Cms/Generated/TypeHandle.php could not be written');
    } finally {
        chmod($root.'/app/Cms/Generated', 0o775);
    }

    expect(SchemaFixtures::files($root))->toBe([]);
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores file permissions');

it('refuses a root that names a stream wrapper before it touches it, so it never writes to ftp:// (GUARDRAILS 3)', function (string $root): void {
    RecordingStreamWrapper::register();

    $failure = null;

    try {
        new FilesystemGeneratedOutput()->write($root, outputResult());
    } catch (GenerationFailed $failed) {
        $failure = $failed;
    }

    expect(RecordingStreamWrapper::$calls)->toBe([])
        ->and($failure?->codes())->toBe([GenerateErrorCode::OutputUnwritable])
        ->and($failure?->getMessage())->toContain('The root '.$root.' names a stream wrapper');
})->with([
    'a URL wrapper' => RecordingStreamWrapper::url('/app'),
    'a URL behind a filter wrapper' => 'compress.zlib://'.RecordingStreamWrapper::url('/app'),
]);
