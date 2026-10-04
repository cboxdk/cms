<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Addons;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Core\PanelThemes\Actions\CompilePanelThemes;
use Cbox\Cms\Core\Registry\Actions\BuildRegistry;
use Cbox\Cms\Core\Registry\Boundary\PanelBundles;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledBundle;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\DeclaredAddons;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Cms\Core\Tests\PanelThemes\Fakes\FakeThemeSources;
use Cbox\Cms\Core\Tests\PanelThemes\Fakes\FakeThemeStylesheets;
use Cbox\Cms\Core\Tests\Pipeline\Tally\AddTally;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeContractSchemas;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeOpenApiDocuments;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Panel\Boundary\InstalledBundles;
use Cbox\Cms\Panel\Domain\BundleHash;
use Cbox\Cms\Panel\Domain\Dto\BundleDirectories;
use Cbox\Cms\Panel\Domain\Dto\ServedBundles;
use Cbox\Cms\Panel\PanelServiceProvider;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Illuminate\Contracts\Container\Container;
use LogicException;
use RuntimeException;

/**
 * An addon's panel bundle on disk, as the addon's build plugin writes it (PRD 13.4): the addon
 * tally of ContributionWorld with a bundle of ENTRY, a module that registers the addon through
 * the panel's shared SDK, and STYLE, a stylesheet in the addon's cascade layer, in a scratch
 * directory with a panel-manifest.json that lists both with their SHA-384 and the ids of the
 * manifest's contributions that run code; and the registry cms:build compiles from it, which read
 * the bundle for real (PanelBundles). bind() puts the registry and the bundle's directory in the
 * container, as an installation's cms:build and the addon's provider do, so the panel serves the
 * bundle's files below /cms/addons/tally/<hash>/.
 */
final class AddonBundleWorld
{
    public const string NAMESPACE = 'tally';

    public const string ENTRY = 'assets/addon-1a2b3c.js';

    public const string STYLE = 'assets/addon-4d5e6f.css';

    public const string ENTRY_SOURCE = <<<'JS'
        import { definePanelAddon } from '@cboxdk/cms-panel/extend';

        export const loaded = 'tally';
        export default definePanelAddon({});

        JS;

    public const string STYLE_SOURCE = <<<'CSS'
        @layer cms.addon.tally {
          [data-cms-addon="tally"] .tally-badge { color: rgb(0, 128, 0); }
        }

        CSS;

    public CompiledRegistry $registry;

    private function __construct(public string $directory)
    {
        $this->registry = $this->compile($directory);
    }

    /**
     * Writes the bundle into a scratch directory and compiles the registry from it.
     */
    public static function write(): self
    {
        $directory = sys_get_temp_dir().'/cms-panel-addon-bundle-'.bin2hex(random_bytes(6));

        if (! mkdir($directory.'/assets', 0755, true)) {
            throw new RuntimeException("Cannot make {$directory}.");
        }

        file_put_contents($directory.'/'.self::ENTRY, self::ENTRY_SOURCE);
        file_put_contents($directory.'/'.self::STYLE, self::STYLE_SOURCE);
        file_put_contents($directory.'/'.PanelBundles::MANIFEST, json_encode([
            'contributions' => self::codeIds(),
            'entry' => self::ENTRY,
            'externals' => ['@cboxdk/cms-panel/extend'],
            'files' => [
                ['integrity' => self::integrity(self::ENTRY_SOURCE), 'kind' => 'script', 'path' => self::ENTRY],
                ['integrity' => self::integrity(self::STYLE_SOURCE), 'kind' => 'style', 'path' => self::STYLE],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return new self($directory);
    }

    /**
     * Puts the registry, the bundle's directory and the bundles the panel serves in the container.
     */
    public function bind(Container $app): void
    {
        $app->instance(CompiledRegistry::class, $this->registry);
        $app->instance(RegistryCache::class, ContributionWorld::cache($this->registry));
        $app->instance(BundleDirectories::class, new BundleDirectories([self::NAMESPACE => $this->directory]));
        $app->forgetInstance(ServedBundles::class);
    }

    /**
     * The bundles the panel serves from this world.
     */
    public function served(): ServedBundles
    {
        return InstalledBundles::read($this->registry, new BundleDirectories([self::NAMESPACE => $this->directory]));
    }

    /**
     * The hash of the bundle as cms:build compiled it.
     */
    public function hash(): string
    {
        $bundle = $this->registry->addon(new AddonNamespace(self::NAMESPACE))?->panel?->bundle;

        return $bundle instanceof CompiledBundle ? BundleHash::of($bundle) : throw new LogicException('The registry has no bundle for tally.');
    }

    /**
     * The address of a file of the bundle below the workbench's panel.
     */
    public function url(string $file): string
    {
        return '/cms/addons/'.self::NAMESPACE.'/'.$this->hash().'/'.$file;
    }

    /**
     * The scope the import map gives the addon.
     */
    public function prefix(): string
    {
        return '/cms/addons/'.self::NAMESPACE.'/'.$this->hash().'/';
    }

    /**
     * Changes the bytes of a file of the bundle after cms:build.
     */
    public function tamper(string $file): void
    {
        file_put_contents($this->directory.'/'.$file, "/* changed after cms:build */\n", FILE_APPEND);
    }

    public function remove(): void
    {
        foreach ([self::ENTRY, self::STYLE, PanelBundles::MANIFEST] as $file) {
            @unlink($this->directory.'/'.$file);
        }

        @rmdir($this->directory.'/assets');
        @rmdir($this->directory);
    }

    /**
     * The SHA-384 of a file's text, as the manifest and the import map carry it.
     */
    public static function integrity(string $bytes): string
    {
        return 'sha384-'.base64_encode(hash('sha384', $bytes, true));
    }

    /**
     * The ids of the addon's contributions that run code, sorted.
     *
     * @return list<string>
     */
    public static function codeIds(): array
    {
        $ids = array_values(array_map(
            static fn (PanelContribution $contribution): string => $contribution->id()->value,
            array_filter(ContributionWorld::contributions(), static fn (PanelContribution $contribution): bool => $contribution->runsCode()),
        ));
        sort($ids, SORT_STRING);

        return $ids;
    }

    private function compile(string $directory): CompiledRegistry
    {
        $manifest = new AddonManifest(
            ContributionWorld::ADDON,
            new AddonNamespace(self::NAMESPACE),
            new CoreApiVersion(CoreApiVersion::CURRENT_MAJOR, CoreApiVersion::CURRENT_MINOR),
            __DIR__.'/../Contributions/Fixtures/Tally',
            new AddonCapabilities(ClassificationAccess::Internal, [AddTally::class]),
            panel: new PanelContributions(PanelApiVersion::current(), $directory, ContributionWorld::POINTS, ContributionWorld::contributions()),
        );
        $fixtures = __DIR__.'/../Contributions/Fixtures';

        return new BuildRegistry(new AttributeScanner, new RegistryCompiler, new FakeRegistryCache, new FakeOpenApiDocuments('tally.add@1', 'tally.board@1'), new FakeContractSchemas(ContributionWorld::shapes()), new CompilePanelThemes(new FakeThemeSources), new FakeThemeStylesheets)->build(
            new ScanRoots(
                new ScanRoot(ContributionWorld::HOST, $fixtures.'/Desk'),
                new ScanRoot(ContributionWorld::ADDON, $fixtures.'/Tally'),
                new ScanRoot(ContributionWorld::ADDON, ContributionWorld::TALLY_COMMAND),
                new ScanRoot(PanelServiceProvider::PACKAGE, dirname(__DIR__, 2).'/src'),
            ),
            new DeclaredAddons([$manifest], [], [ContributionWorld::ADDON => PanelBundles::read($directory)], []),
            new BuildSettings(null, []),
        );
    }
}
