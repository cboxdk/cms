<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Codec;

use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecordDtos;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/*
 * The comprehensive example's record DTOs and codec (PRD 8.9, 11.12, GUARDRAILS 2.2): what
 * PhpRecordDtos generates from the example is exactly the committed golden files below
 * Fixtures/Comprehensive/Generated, file for file and byte for byte. The golden files are in
 * PHPStan's, Pint's and Rector's paths, so gates 1 to 3 hold them to level 10 and the formatters.
 * To change them on purpose, change the generator and write its output over them.
 */

/**
 * Every committed golden file in the directories PhpRecordDtos writes, Generated/Boundary and
 * Generated/Domain, relative to the example's directory. The other golden files come from the other
 * PHP generators; PhpRecordsTest holds the whole directory to what they all write together.
 *
 * @return list<string>
 */
function goldenRecordFiles(): array
{
    $files = [];

    foreach (['Generated/Boundary', 'Generated/Domain'] as $directory) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ComprehensiveExample::DIRECTORY.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $files[] = substr($file->getPathname(), strlen(ComprehensiveExample::DIRECTORY) + 1);
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

it('generates exactly the committed golden DTOs and codec of the comprehensive example', function (): void {
    $target = new GenerationTarget(
        ComprehensiveExample::DIRECTORY,
        ComprehensiveExample::roots(),
        'Generated',
        'Cbox\Cms\Generators\Tests\Descriptor\Fixtures\Comprehensive\Generated',
        'generated',
    );
    $files = new PhpRecordDtos()->generate(ComprehensiveExample::compile(), $target);
    $paths = array_map(static fn (GeneratedFile $file): string => $file->path, $files);
    sort($paths, SORT_STRING);

    expect($paths)->toBe([
        'Generated/Boundary/ShopProductCodecV1.php',
        'Generated/Domain/Dto/ShopProductV1.php',
        'Generated/Domain/Dto/ShopProductV1Dimensions.php',
        'Generated/Domain/Dto/ShopProductV1Ext.php',
        'Generated/Domain/Dto/ShopProductV1ExtApp.php',
        'Generated/Domain/Dto/ShopProductV1Supplier.php',
    ])->and(goldenRecordFiles())->toBe($paths);

    foreach ($files as $file) {
        expect(file_get_contents(ComprehensiveExample::DIRECTORY.'/'.$file->path))->toBe($file->contents, $file->path.' differs from what PhpRecordDtos generates.');
    }
});
