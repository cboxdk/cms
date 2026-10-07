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
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\FlowStep;
use Cbox\Cms\Contracts\PanelPoints\FormCheck;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the workbench's fixture addon, cboxdk/cms-fixture-addon (PRD 13.1, 13.2,
 * MILESTONES M1 point 7). Its scan root holds the addon's three hooks, and its manifest says what
 * the addon does through the kernel: it is named fixtureaddon, needs the core API 1.0, reads public
 * fields only, may transform and validate entry.create and validate variant.release, and
 * extends app:fixture_article with the blueprint in its schema directory. In the panel it ships one
 * theme, brand, a magenta accent (PRD 13.4), which has no effect until the installation selects it
 * in cbox-cms.panel.themes; the workbench does not, and its contributions to the generic command
 * form of entry.create (section 8 of the panel extension architecture): the checks SLUG_HINT, a
 * warning where the title derives no slug, SLUG_OVERRIDE, which asks the viewer to acknowledge a
 * slug set by hand, and SLUG_SHAPE, which blocks a slug that is not well formed and mirrors the
 * hook RequireWellFormedSlug, as the mirror rule asks, and the step SLUG_REVIEW before the submit,
 * which shows the slug the article gets, patches it into the draft or cancels. Their code is the
 * prebuilt bundle in dist/panel, built from resources/panel by `npm run build:fixture-addon` and
 * signed with the test key panel-signing-test-key.pem, whose public key the workbench trusts in
 * cbox-cms.addons.publishers (PRD 13.8).
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

    /** The form of entry.create, which the addon's checks and step apply to. */
    public const string ENTRY_CREATE = 'entry.create@1';

    /** The warning check: the title derives no slug. */
    public const string SLUG_HINT = 'fixtureaddon.slug-hint';

    /** The acknowledge check: a slug set by hand, instead of the derived one. */
    public const string SLUG_OVERRIDE = 'fixtureaddon.slug-override';

    /** The blocking check, which mirrors RequireWellFormedSlug: a slug that is not well formed. */
    public const string SLUG_SHAPE = 'fixtureaddon.slug-shape';

    /** The step before the submit: the slug the article gets, patched into the draft or cancelled. */
    public const string SLUG_REVIEW = 'fixtureaddon.slug-review';

    /** The path the step may patch: the addon's own field. */
    public const string SLUG_PATH = 'fields.ext.fixtureaddon.fixture_slug';

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
                new AllowedHook(CreateEntry::class, Phase::Validate),
                new AllowedHook(ReleaseVariant::class, Phase::Validate),
            ],
            schema: new SchemaContributions(
                extends: [new TypeName(FixtureArticle::TYPE)],
                directory: __DIR__.'/../schema',
            ),
            panel: new PanelContributions(
                sdk: new PanelApiVersion(1, 0),
                bundle: __DIR__.'/../dist/panel',
                acceptsExperimental: ['command.form.checks@1', 'command.form.steps@1'],
                contributions: [
                    new FormCheck(new ContributionId(self::SLUG_HINT), 'command.form.checks@1', self::ENTRY_CREATE, Severity::Warning),
                    new FormCheck(new ContributionId(self::SLUG_OVERRIDE), 'command.form.checks@1', self::ENTRY_CREATE, Severity::Acknowledge),
                    new FormCheck(new ContributionId(self::SLUG_SHAPE), 'command.form.checks@1', self::ENTRY_CREATE, Severity::Error, RequireWellFormedSlug::class),
                    new FlowStep(new ContributionId(self::SLUG_REVIEW), 'command.form.steps@1', self::ENTRY_CREATE, StepPosition::BeforeSubmit, [self::SLUG_PATH]),
                ],
                themes: [self::THEME => __DIR__.'/../resources/panel/theme.json'],
            ),
        );
    }
}
