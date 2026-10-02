<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance;

use Cbox\Cms\Core\Maintenance\Boundary\BootstrapConfig;
use Illuminate\Config\Repository;
use InvalidArgumentException;

/*
 * cbox-cms.access read into the bootstrap's settings (PRD 5.10): the handle of the bootstrap role,
 * which must be a role handle, and whether the environment is production.
 */

it('reads the handle and whether the environment is production', function (): void {
    $config = new Repository(['cbox-cms' => ['access' => ['bootstrap_role' => 'site_admin']]]);

    $local = BootstrapConfig::read($config, 'local');
    $production = BootstrapConfig::read($config, 'production');

    expect($local->role->value)->toBe('site_admin')
        ->and($local->production)->toBeFalse()
        ->and($production->production)->toBeTrue();
});

it('refuses a handle that is not a role handle, naming the key', function (mixed $handle): void {
    BootstrapConfig::read(new Repository(['cbox-cms' => ['access' => ['bootstrap_role' => $handle]]]), 'local');
})->with([
    'missing' => [null],
    'upper case' => ['Administrator'],
    'a number' => [7],
])->throws(InvalidArgumentException::class, 'The setting cbox-cms.access.bootstrap_role must be a role handle.');
