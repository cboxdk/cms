<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests;

use Cbox\Cms\Generators\Generation\Boundary\GeneratorConfig;
use Cbox\Cms\Generators\GeneratorsServiceProvider;
use Illuminate\Config\Repository;

it('is loaded through package discovery', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(GeneratorsServiceProvider::class)
        ->and(app()->getProviders(GeneratorsServiceProvider::class))->toHaveCount(1);
});

it('merges config/generators.php under cbox-cms.generators, next to the core\'s configuration', function (): void {
    $defaults = new Repository(['generators' => require __DIR__.'/../config/generators.php']);

    expect(GeneratorConfig::KEY)->toBe('cbox-cms.generators')
        ->and(array_keys(config()->array('cbox-cms.generators')))->toBe(array_keys($defaults->array('generators')))
        ->and(config('cbox-cms.generators.php_namespace'))->toBeString()
        ->and(config('cbox-cms.contracts'))->toBeArray()
        ->and(config()->has('cms'))->toBeFalse();
});
