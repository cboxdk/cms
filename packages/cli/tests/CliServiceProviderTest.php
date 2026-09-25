<?php

declare(strict_types=1);

use Cbox\Cms\Cli\CliServiceProvider;

it('is loaded through package discovery', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(CliServiceProvider::class)
        ->and(app()->getProviders(CliServiceProvider::class))->toHaveCount(1);
});
