<?php

declare(strict_types=1);

use Cbox\Cms\Core\CoreServiceProvider;

it('is loaded through package discovery', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(CoreServiceProvider::class)
        ->and(app()->getProviders(CoreServiceProvider::class))->toHaveCount(1);
});
