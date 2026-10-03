<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Points;

use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/*
 * The props pipeline of the panel's points (GUARDRAILS 2.2, PRD 13.4), on the fixture points of
 * PanelPointFixtures: what composer generate:protocol's code writes for them, the PHP codecs, the
 * TypeScript with its validators and sample props, and the compatibility lock, is exactly the
 * committed golden files below Fixtures, file for file and byte for byte. The golden PHP is in
 * PHPStan's, Pint's and Rector's paths and the golden TypeScript in tsconfig.json's, so gates 1 to 4
 * hold them to the formatters and the type checkers. To change them on purpose, change the
 * generator and write its output over them.
 */

/**
 * The committed files in the directories the fixture's generation owns, and its lock, relative to
 * the root of cboxdk/cms.
 *
 * @return list<string>
 */
function goldenPointFiles(): array
{
    $files = [PanelPointFixtures::LOCK];

    foreach ([PanelPointFixtures::PHP_DIRECTORY, PanelPointFixtures::TYPESCRIPT_DIRECTORY] as $directory) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PanelPointFixtures::root().'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $files[] = substr($file->getPathname(), strlen(PanelPointFixtures::root()) + 1);
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

it('generates exactly the committed golden codecs, TypeScript, sample props and lock of the fixture points', function (): void {
    $generated = PanelPointFixtures::generated();
    $paths = array_map(static fn (GeneratedFile $file): string => $file->path, $generated->files);
    sort($paths, SORT_STRING);

    expect($paths)->toBe(goldenPointFiles())
        ->and($paths)->toContain(
            PanelPointFixtures::PHP_DIRECTORY.'/NoteCardCodecV2.php',
            PanelPointFixtures::TYPESCRIPT_DIRECTORY.'/points/NoteCardV2.ts',
            PanelPointFixtures::TYPESCRIPT_DIRECTORY.'/validation.ts',
        );

    foreach ($generated->files as $file) {
        expect($file->contents)->toBe((string) file_get_contents(PanelPointFixtures::root().'/'.$file->path), $file->path);
    }
});

it('gives each point a sample that has every member, with the first example of a patterned value', function (): void {
    $module = (string) file_get_contents(PanelPointFixtures::root().'/'.PanelPointFixtures::TYPESCRIPT_DIRECTORY.'/points/NoteCardV2.ts');

    expect($module)->toContain(
        'export function sampleNoteCardV2(): NoteCardV2 {',
        "author: { actor_class: 'staff', name: 'sample' },",
        "edited_at: '2026-01-01T00:00:00.000000Z',",
        "owner: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01',",
        "tags: ['sample'],",
        'compact: true,',
    );
});
