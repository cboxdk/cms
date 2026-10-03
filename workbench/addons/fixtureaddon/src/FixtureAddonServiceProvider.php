<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\AllowedHook;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Addons\SchemaContributions;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the workbench's fixture addon, cboxdk/cms-fixture-addon (PRD 13.1, 13.2,
 * MILESTONES M1 point 7). Its scan root holds the addon's two hooks, and its manifest says what the
 * addon does through the kernel: it is named fixtureaddon, needs the core API 1.0, reads public
 * fields only, may transform entry.create and validate variant.release, and
 * extends app:fixture_article with the blueprint in its schema directory. In the panel it ships one
 * theme, brand, a magenta accent (PRD 13.4), which has no effect until the installation selects it
 * in cbox-cms.panel.themes; the workbench does not.
 *
 * It registers nothing at run time: cms:build compiles the hooks from the scan root and the
 * manifest, and cms:generate reads the blueprint from the schema root the application names for the
 * owner fixtureaddon.
 */
final class FixtureAddonServiceProvider extends ServiceProvider implements DeclaresAddon, DeclaresScanRoots
{
    /** The addon's Composer package, which its scan root and manifest name. */
    public const string PACKAGE = 'cboxdk/cms-fixture-addon';

    /** The addon's namespace, of its extension fields. */
    public const string NAMESPACE = 'fixtureaddon';

    /** The name of the addon's panel theme, which the installation selects as fixtureaddon:brand. */
    public const string THEME = 'brand';

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }

    public function addonManifest(): AddonManifest
    {
        return new AddonManifest(
            package: self::PACKAGE,
            namespace: new AddonNamespace(self::NAMESPACE),
            coreApi: new CoreApiVersion(1, 0),
            docs: __DIR__.'/../docs',
            capabilities: new AddonCapabilities(reads: ClassificationAccess::Public, uiTheme: true),
            hooks: [
                new AllowedHook(CreateEntry::class, Phase::Transform),
                new AllowedHook(ReleaseVariant::class, Phase::Validate),
            ],
            schema: new SchemaContributions(
                extends: [new TypeName(FixtureArticle::TYPE)],
                directory: __DIR__.'/../schema',
            ),
            panel: new PanelContributions(
                sdk: new PanelApiVersion(1, 0),
                themes: [self::THEME => __DIR__.'/../resources/panel/theme.json'],
            ),
        );
    }
}
