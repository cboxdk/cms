<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Editor;

use Cbox\Cms\Generators\Editor\Domain\RelativePath;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

it('is the path from a directory to a file', function (string $directory, string $file, string $path): void {
    expect(RelativePath::between($directory, $file))->toBe($path);
})->with([
    'a sibling tree' => ['/srv/app/schema', '/srv/app/vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json', '../vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json'],
    'deeper' => ['/srv/app/workbench/schema/blog', '/srv/app/vendor/x/blueprint.v1.json', '../../../vendor/x/blueprint.v1.json'],
    'below the directory' => ['/srv/app', '/srv/app/vendor/x/blueprint.v1.json', 'vendor/x/blueprint.v1.json'],
    'in the directory' => ['/srv/app/schema', '/srv/app/schema/blueprint.v1.json', 'blueprint.v1.json'],
    'from the root' => ['/', '/blueprint.v1.json', 'blueprint.v1.json'],
    'nothing in common' => ['/home/me/app/schema', '/opt/vendor/blueprint.v1.json', '../../../../opt/vendor/blueprint.v1.json'],
    'a prefix of a name is not a common directory' => ['/srv/app/schema', '/srv/application/blueprint.v1.json', '../../application/blueprint.v1.json'],
    'a directory with the file\'s name' => ['/srv/app/blueprint.v1.json', '/srv/app/blueprint.v1.json/blueprint.v1.json', 'blueprint.v1.json'],
    'a trailing slash and a double slash' => ['/srv/app/schema/', '/srv//app/vendor/blueprint.v1.json', '../vendor/blueprint.v1.json'],
    'Windows paths' => ['C:\app\schema', 'C:\app\vendor\blueprint.v1.json', '../vendor/blueprint.v1.json'],
]);

it('refuses paths that are not absolute and canonical, or on different drives', function (string $directory, string $file): void {
    expect(static fn (): string => RelativePath::between($directory, $file))
        ->toThrow(GenerationFailed::class, '[generate_invalid_config] ');
})->with([
    'a relative directory' => ['schema', '/srv/app/blueprint.v1.json'],
    'a relative file' => ['/srv/app/schema', 'vendor/blueprint.v1.json'],
    'a ".." segment' => ['/srv/app/schema/..', '/srv/app/blueprint.v1.json'],
    'a "." segment' => ['/srv/app/schema', '/srv/./app/blueprint.v1.json'],
    'different drives' => ['C:\app\schema', 'D:\vendor\blueprint.v1.json'],
    'a drive and a POSIX root' => ['C:\app\schema', '/vendor/blueprint.v1.json'],
]);
