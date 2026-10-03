<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Actions;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Actions\CompilePanelThemes;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeStylesheets;
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
 * describes its REST surface in an OpenAPI document (GUARDRAILS 2.1), compiles the panel's theme
 * from the themes the installation selects (CompilePanelThemes), and writes the cache, then the
 * document, then the theme's stylesheet. Nothing is written unless the whole registry compiles,
 * its REST surface can be described and its theme composes; the problems of all three are listed
 * together. The registry it returns carries the build's warnings, the theme's after its own, which
 * the cache does not hold.
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
        private CompilePanelThemes $themes,
        private ThemeStylesheets $stylesheets,
    ) {}

    /**
     * @throws RegistryBuildFailed
     * @throws RegistryCacheUnwritable
     */
    public function build(ScanRoots $roots, DeclaredAddons $addons = new DeclaredAddons, BuildSettings $settings = new BuildSettings): CompiledRegistry
    {
        $theme = $this->themes->compile($settings->themes, array_values(array_filter(
            $addons->manifests,
            static fn (AddonManifest $manifest): bool => $settings->allows($manifest->package),
        )));

        try {
            $registry = $this->compiler->compile($this->scanner->scan($roots), $addons, $settings, $this->schemas->shapes());
            $document = $this->documents->describe($registry);
        } catch (RegistryBuildFailed $failed) {
            throw RegistryBuildFailed::with([...$failed->problems, ...$theme->problems]);
        }

        if ($theme->problems !== []) {
            throw RegistryBuildFailed::with($theme->problems);
        }

        $this->cache->write($registry);
        $this->documents->write($document);
        $this->stylesheets->write($theme->stylesheet);

        return $registry->withWarnings($theme->warnings);
    }

    /**
     * The directory the cache is written to.
     */
    public function location(): string
    {
        return $this->cache->location();
    }
}
