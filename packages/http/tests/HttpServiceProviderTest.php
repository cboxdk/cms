<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests;

use Cbox\Cms\Http\HttpServiceProvider;

it('is loaded through package discovery', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(HttpServiceProvider::class)
        ->and(app()->getProviders(HttpServiceProvider::class))->toHaveCount(1);
});
