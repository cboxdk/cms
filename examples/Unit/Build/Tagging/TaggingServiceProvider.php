<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Tagging;

use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the package acme/cms-tagging, which hooks into the notes package.
 */
final class TaggingServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public function scanRoots(): array
    {
        return [new ScanRoot('acme/cms-tagging', __DIR__)];
    }
}
