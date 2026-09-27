<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests;

use Cbox\Cms\Core\Bindings\Boundary\ContractBindings;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Core\Partitions\Boundary\PartitionConfig;
use Illuminate\Config\Repository;

it('is loaded through package discovery', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(CoreServiceProvider::class)
        ->and(app()->getProviders(CoreServiceProvider::class))->toHaveCount(1);
});

it('loads the core migrations, which create the receipt tables', function (): void {
    $paths = array_map(realpath(...), app('migrator')->paths());
    $core = realpath(__DIR__.'/../database/migrations');

    expect($core)->toBeString()
        ->and($paths)->toContain($core)
        ->and(glob($core.'/*_create_receipts_tables.php'))->toHaveCount(1);
});

it('merges config/cbox-cms.php under cbox-cms, the only root of the configuration, and the readers use it', function (): void {
    $defaults = new Repository(['cbox-cms' => require __DIR__.'/../config/cbox-cms.php']);

    expect(array_map(basename(...), glob(__DIR__.'/../config/*.php') ?: []))->toBe(['cbox-cms.php'])
        ->and(array_keys($defaults->array('cbox-cms')))->toBe(['contracts', 'database', 'doctor'])
        ->and(config('cbox-cms.contracts'))->toBe($defaults->get('cbox-cms.contracts'))
        ->and(config('cbox-cms.database.partitions.runway_days'))->toBe($defaults->get('cbox-cms.database.partitions.runway_days'))
        ->and(config()->has('cms'))->toBeFalse()
        ->and([ContractBindings::CONFIG_KEY, DoctorConfig::CONFIG_KEY, PartitionConfig::CONFIG_KEY])
        ->toBe(['cbox-cms.contracts', 'cbox-cms.doctor', 'cbox-cms.database']);
});
