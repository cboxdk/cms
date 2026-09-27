<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Schema\Boundary\LocalFile;
use Cbox\Cms\Generators\Tests\Schema\Fakes\RecordingUrlWrapper;
use Cbox\Cms\Generators\Tests\SchemaFixtures;

/*
 * LocalFile reads local files and never a URL (GUARDRAILS 3). RecordingUrlWrapper stands in for
 * http:// and ftp://: it records every call PHP makes to it, so a test sees whether LocalFile
 * handed the path to a wrapper at all, with allow_url_fopen on or off.
 */

beforeEach(function (): void {
    RecordingUrlWrapper::register();
});

afterEach(function (): void {
    RecordingUrlWrapper::unregister();
    SchemaFixtures::cleanUp();
});

it('never hands a URL to its stream wrapper', function (string $path): void {
    expect(LocalFile::contents($path))->toBeNull()
        ->and(RecordingUrlWrapper::$calls)->toBe([]);
})->with([
    'a URL wrapper' => RecordingUrlWrapper::SCHEME.'://metadata.internal/schema.yaml',
    'a URL wrapper in upper case' => strtoupper(RecordingUrlWrapper::SCHEME).'://metadata.internal/schema.yaml',
    'a URL behind a filter wrapper' => 'compress.zlib://'.RecordingUrlWrapper::SCHEME.'://metadata.internal/schema.yaml',
    'file://' => 'file:///etc/hosts',
    'data:' => 'data:text/plain,remote',
]);

it('shows that the wrapper would serve the URL to the file functions', function (): void {
    expect(is_file(RecordingUrlWrapper::SCHEME.'://metadata.internal/schema.yaml'))->toBeTrue()
        ->and(RecordingUrlWrapper::$calls)->toBe(['url_stat '.RecordingUrlWrapper::SCHEME.'://metadata.internal/schema.yaml']);
});

it('reads a local file by its path, and an empty one as an empty string', function (): void {
    $base = SchemaFixtures::scratch();
    SchemaFixtures::write($base.'/schema/page.yaml', "blueprint: 1\n");
    SchemaFixtures::write($base.'/schema/empty.yaml', '');

    expect(LocalFile::contents($base.'/schema/page.yaml'))->toBe("blueprint: 1\n")
        ->and(LocalFile::contents($base.'/schema/empty.yaml'))->toBe('')
        ->and(LocalFile::contents($base.'/schema/missing.yaml'))->toBeNull()
        ->and(LocalFile::contents($base.'/schema'))->toBeNull()
        ->and(RecordingUrlWrapper::$calls)->toBe([]);
});
