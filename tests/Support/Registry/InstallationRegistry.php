<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Registry;

use Cbox\Cms\Core\Registry\Boundary\ProviderAddonManifests;
use Cbox\Cms\Core\Registry\Boundary\ProviderScanRoots;
use Cbox\Cms\Core\Registry\Domain\ContractSchemas;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Illuminate\Contracts\Foundation\Application;

/**
 * The installation's registry compiled as cms:build compiles it, for a test that reads the
 * installation as it is, whatever the cache on disk holds: from the scan roots and addon
 * manifests of the application's service providers, with the installation's build settings and
 * the JSON Schemas of its contracts, which the build checks the panel's contributions against,
 * the data queries of the core's own pickers among them (ContractSchemas).
 */
final readonly class InstallationRegistry
{
    public static function compile(Application $app): CompiledRegistry
    {
        return $app->make(RegistryCompiler::class)->compile(
            $app->make(DeclarationScanner::class)->scan(ProviderScanRoots::of($app)),
            ProviderAddonManifests::of($app),
            $app->make(BuildSettings::class),
            $app->make(ContractSchemas::class)->shapes(),
        );
    }
}
