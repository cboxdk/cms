<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the package acme/cms-search. Its scan root is the directory it lies in,
 * so cms:build registers the subscribers next to it.
 */
final class SearchServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public function scanRoots(): array
    {
        return [new ScanRoot('acme/cms-search', __DIR__)];
    }
}
