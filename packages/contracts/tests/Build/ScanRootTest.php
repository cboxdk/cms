<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Build;

use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use InvalidArgumentException;
use ReflectionMethod;

it('holds a package name and an absolute directory', function (): void {
    $root = new ScanRoot('acme/cms-blog', '/srv/app/vendor/acme/cms-blog/src');

    expect($root->package)->toBe('acme/cms-blog')
        ->and($root->directory)->toBe('/srv/app/vendor/acme/cms-blog/src')
        ->and(new ScanRoot('acme/blog', 'C:\\app\\src')->directory)->toBe('C:\\app\\src');
});

it('refuses a package name that Composer would refuse', function (string $package): void {
    expect(fn (): ScanRoot => new ScanRoot($package, '/src'))
        ->toThrow(InvalidArgumentException::class, sprintf('Scan root package "%s" is not a Composer package name', $package));
})->with(['blog', 'Acme/Blog', 'acme/', '/blog', 'acme/blog/extra', "acme/blog\n", '']);

it('refuses a relative directory', function (string $directory): void {
    expect(fn (): ScanRoot => new ScanRoot('acme/blog', $directory))
        ->toThrow(InvalidArgumentException::class, 'is not an absolute path. Use __DIR__ in the service provider.');
})->with(['src', './src', '', 'C:src']);

it('declares scan roots as a list', function (): void {
    expect((string) new ReflectionMethod(DeclaresScanRoots::class, 'scanRoots')->getReturnType())->toBe('array')
        ->and((string) new ReflectionMethod(DeclaresScanRoots::class, 'scanRoots')->getDocComment())->toContain('@return list<ScanRoot>');
});
