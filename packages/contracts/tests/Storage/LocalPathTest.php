<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Storage;

use Cbox\Cms\Contracts\Storage\LocalPath;

/*
 * Which paths PHP hands to a stream wrapper instead of the filesystem, so the local readers and
 * writers can refuse them before any file function sees them (GUARDRAILS 3).
 */

it('names a stream wrapper for a URL, a filter wrapper, file:// and data:', function (string $path): void {
    expect(LocalPath::namesStreamWrapper($path))->toBeTrue();
})->with([
    'ftp://' => 'ftp://files.example.internal/cache',
    'http:// in upper case' => 'HTTP://metadata.internal/latest',
    'a filter wrapper' => 'compress.zlib://ftp://files.example.internal/cache',
    'php://' => 'php://filter/resource=/etc/hosts',
    'file://' => 'file:///tmp/cache',
    'a scheme with + and -' => 'svn+ssh-x://host/repo',
    'data:' => 'data:text/plain,remote',
    'data: in upper case' => 'DATA:text/plain,remote',
]);

it('names no stream wrapper for a local path', function (string $path): void {
    expect(LocalPath::namesStreamWrapper($path))->toBeFalse();
})->with([
    'an absolute path' => '/var/www/bootstrap/cache/cms',
    'a Windows path' => 'C:\\www\\bootstrap',
    'a Windows path with forward slashes' => 'C:/www/bootstrap',
    'a relative path' => 'schema/page.yaml',
    'a scheme below the start' => '/tmp/ftp://host',
    'a colon without slashes' => 'ftp:files',
    'an empty path' => '',
]);
