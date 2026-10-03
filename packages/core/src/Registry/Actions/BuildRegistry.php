<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\ContractSchemas;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\DeclaredAddons;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\OpenApiDocuments;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;

/**
 * cms:build (PRD 13.2): scans the scan roots, compiles the registry with the addon manifests
 * (PRD 13.1), the installation's settings (the allowlist of addons and the panel's overrides,
 * PRD 13.8, 13.4) and the contracts' JSON Schemas the panel's contributions are checked against,
 * describes its REST surface in an OpenAPI document (GUARDRAILS 2.1), and writes the cache and
 * then the document. Nothing is written unless the whole registry compiles and its REST surface
 * can be described. The registry it returns carries the build's warnings, which the cache does
 * not hold.
 */
#[Experimental]
final readonly class BuildRegistry
{
    public function __construct(
        private DeclarationScanner $scanner,
        private RegistryCompiler $compiler,
        private RegistryCache $cache,
        private OpenApiDocuments $documents,
        private ContractSchemas $schemas,
    ) {}

    /**
     * @throws RegistryBuildFailed
     * @throws RegistryCacheUnwritable
     */
    public function build(ScanRoots $roots, DeclaredAddons $addons = new DeclaredAddons, BuildSettings $settings = new BuildSettings): CompiledRegistry
    {
        $registry = $this->compiler->compile($this->scanner->scan($roots), $addons, $settings, $this->schemas->shapes());
        $document = $this->documents->describe($registry);

        $this->cache->write($registry);
        $this->documents->write($document);

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
