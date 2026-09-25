<?php

declare(strict_types=1);

use Cbox\Cms\Http\HttpServiceProvider;

it('is loaded through package discovery', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(HttpServiceProvider::class)
        ->and(app()->getProviders(HttpServiceProvider::class))->toHaveCount(1);
});
