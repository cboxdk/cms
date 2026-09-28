<?php

declare(strict_types=1);

namespace Cbox\Cms\Http;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the http package in a Laravel application. Loaded through package discovery.
 *
 * Declares the package's classes as a scan root for cms:build (PRD 13.2).
 */
#[Internal]
final class HttpServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public const string PACKAGE = 'cboxdk/cms';

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }
}
