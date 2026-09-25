<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests;

use Cbox\Cms\Generators\GeneratorsServiceProvider;

it('is loaded through package discovery', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(GeneratorsServiceProvider::class)
        ->and(app()->getProviders(GeneratorsServiceProvider::class))->toHaveCount(1);
});
