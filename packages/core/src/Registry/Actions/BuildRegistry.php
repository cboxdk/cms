<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\DeclaredAddons;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;

/**
 * cms:build (PRD 13.2): scans the scan roots, compiles the registry with the addon manifests
 * (PRD 13.1) and writes the cache. Nothing is written unless the whole registry compiles.
 */
#[Experimental]
final readonly class BuildRegistry
{
    public function __construct(
        private DeclarationScanner $scanner,
        private RegistryCompiler $compiler,
        private RegistryCache $cache,
    ) {}

    /**
     * @throws RegistryBuildFailed
     * @throws RegistryCacheUnwritable
     */
    public function build(ScanRoots $roots, DeclaredAddons $addons = new DeclaredAddons): CompiledRegistry
    {
        $registry = $this->compiler->compile($this->scanner->scan($roots), $addons);

        $this->cache->write($registry);

        return $registry;
    }

    /**
     * The directory the cache is written to.
     */
    public function location(): string
    {
        return $this->cache->location();
    }
}
