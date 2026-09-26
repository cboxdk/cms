<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests;

use Cbox\Cms\Core\CoreServiceProvider;

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
