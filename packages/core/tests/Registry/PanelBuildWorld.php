<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\AllowedHook;
use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Addons\SchemaContributions;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\Confirm;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\DecoratorContribution;
use Cbox\Cms\Contracts\PanelPoints\FlowStep;
use Cbox\Cms\Contracts\PanelPoints\FormCheck;
use Cbox\Cms\Contracts\PanelPoints\LoginNotice;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\ObserverContribution;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Contracts\PanelPoints\ProviderContribution;
use Cbox\Cms\Contracts\PanelPoints\ReplacementContribution;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Contracts\PanelPoints\Tone;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeSelection;
use Cbox\Cms\Core\Registry\Actions\BuildRegistry;
use Cbox\Cms\Core\Registry\Boundary\JsonSchemaNodes;
use Cbox\Cms\Core\Registry\Domain\BundleFileKind;
use Cbox\Cms\Core\Registry\Domain\BundleIntegrity;
use Cbox\Cms\Core\Registry\Domain\BundlePath;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonBundle;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleFile;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleManifest;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\ContractShapes;
use Cbox\Cms\Core\Registry\Domain\Dto\ContributionOverride;
use Cbox\Cms\Core\Registry\Domain\Dto\DeclaredAddons;
use Cbox\Cms\Core\Registry\Domain\Dto\ReplacementChoice;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Cms\Core\Tests\PanelThemes\Fakes\FakeThemeStylesheets;
use Cbox\Cms\Core\Tests\PanelThemes\ThemeWorld;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeContractSchemas;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeOpenApiDocuments;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\ApprovalReason;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\DenySelfApproval;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\ListApprovalQueue;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\PendingApprovals;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\RequestApproval;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost\DraftNote;
use PHPUnit\Framework\Assert;

/**
 * A world for compiling addons' panel contributions (PRD 13.4): the scan roots of the host
 * fixture PanelHost (acme/notes: a point of every kind, the command notes.draft on Inertia, the
 * query notes.search and a validate hook) and of the addon fixture PanelAddon
 * (acme/cms-approvals, namespace approvals: its commands, its query, its hooks and a value class),
 * the JSON Schemas of their contracts, and manifests that contribute to the points. A manifest's
 * bundle is made to match its contributions unless a test gives another.
 */
final class PanelBuildWorld
{
    public const string HOST = 'acme/notes';

    public const string ADDON = 'acme/cms-approvals';

    public const string STAMPS = 'acme/cms-stamps';

    public const string BUNDLE = '/srv/addons/approvals/dist/panel';

    /** The theme files a build reads, by path: a pale accent below AA. */
    public const array THEMES = ['/srv/addons/approvals/resources/panel/pale.json' => ThemeWorld::PALE];

    public static function roots(): ScanRoots
    {
        return new ScanRoots(RegistryFixtures::root('PanelHost', self::HOST), RegistryFixtures::root('PanelAddon', self::ADDON));
    }

    /**
     * A valid contribution of every kind but a theme, to every point of the host fixture an addon
     * may contribute to.
     *
     * @return list<PanelContribution>
     */
    public static function everyKind(): array
    {
        $draft = new Scope(commands: [new CommandRef(new CommandName('notes.draft'), 1)]);

        return [
            new SlotFill(new ContributionId('approvals.badge'), 'notes.detail.sections@1', PendingApprovals::class),
            new SlotFill(new ContributionId('approvals.legacy'), 'notes.legacy@1'),
            new ActionContribution(new ContributionId('approvals.request'), 'notes.detail.actions@1', RequestApproval::class, 'approvals.request.label', 'key', ['note' => '/note'], Confirm::DryRun, Tone::Warning, 50),
            new NavContribution(new ContributionId('approvals.queue-link'), 'notes.nav@1', 'approvals.nav.queue', 'approvals.queue', 'inbox', scope: new Scope(requires: new CommandName('approvals.pending'))),
            new PageContribution(new ContributionId('approvals.queue'), 'notes.page@1', 'queue', ListApprovalQueue::class),
            new DecoratorContribution(new ContributionId('approvals.submit-guard'), 'notes.form.submit@1', [Tighten::DisabledReason, Tighten::Description], DenySelfApproval::class, scope: $draft),
            new ReplacementContribution(new ContributionId('approvals.stars-input'), 'notes.form.field@1', 'approvals:stars'),
            new ReplacementContribution(new ContributionId('approvals.request-form'), 'notes.form.command@1', 'approvals.request@1'),
            new ReplacementContribution(new ContributionId('approvals.reason-input'), 'notes.form.value@1', ApprovalReason::class),
            new FormCheck(new ContributionId('approvals.self-approval'), 'notes.form.checks@1', 'notes.draft@1', Severity::Error, DenySelfApproval::class),
            new FormCheck(new ContributionId('approvals.hint'), 'notes.form.checks@1', 'notes.draft@1', Severity::Warning),
            new FlowStep(new ContributionId('approvals.four-eyes'), 'notes.form.steps@1', 'notes.draft@1', StepPosition::BeforeSubmit, ['fields.ext.approvals.reason'], 20),
            new FlowStep(new ContributionId('approvals.request-step'), 'notes.form.steps@1', 'approvals.request@1', StepPosition::AfterReceipt, ['reason']),
            new ObserverContribution(new ContributionId('approvals.observer'), 'notes.observe@1'),
            new ProviderContribution(new ContributionId('approvals.palette'), 'notes.palette@1'),
            new LoginNotice(new ContributionId('approvals.notice'), 'notes.login.notice@1', 'approvals.login.notice', Tone::Info),
        ];
    }

    /**
     * The addon's manifest with the contributions.
     *
     * @param  list<PanelContribution>  $contributions
     * @param  list<string>  $accepts
     * @param  list<string>|null  $issues  null for the addon's commands on Inertia
     * @param  array<string, string>  $themes  theme files by name, read from THEMES
     */
    public static function manifest(
        array $contributions,
        array $accepts = ['notes.detail.sections@1'],
        ?PanelApiVersion $sdk = null,
        ?array $issues = null,
        string $namespace = 'approvals',
        string $package = self::ADDON,
        ?string $bundle = self::BUNDLE,
        array $themes = [],
    ): AddonManifest {
        return new AddonManifest(
            $package,
            new AddonNamespace($namespace),
            new CoreApiVersion(CoreApiVersion::CURRENT_MAJOR, CoreApiVersion::CURRENT_MINOR),
            __DIR__.'/AddonFiles/docs',
            new AddonCapabilities(ClassificationAccess::Internal, $issues ?? [RequestApproval::class], uiTheme: true),
            $package === self::ADDON ? [
                new AllowedHook(DraftNote::class, Phase::Validate),
                new AllowedHook(DraftNote::class, Phase::Transform),
                new AllowedHook(RequestApproval::class, Phase::Authorize),
            ] : [],
            [],
            new SchemaContributions([new ContributedFieldType($namespace.':stars')], fieldTypeContributor: AddonFieldTypes::class),
            new PanelContributions($sdk ?? PanelApiVersion::current(), $bundle, $accepts, $contributions, $themes),
        );
    }

    /**
     * The manifests as cms:build declares them, each with a bundle that matches its contributions
     * unless the bundles say otherwise.
     *
     * @param  list<AddonManifest>  $manifests
     * @param  array<string, AddonBundle>  $bundles  by package
     */
    public static function addons(array $manifests, array $bundles = []): DeclaredAddons
    {
        foreach ($manifests as $manifest) {
            if ($manifest->panel?->bundle !== null && ! array_key_exists($manifest->package, $bundles)) {
                $bundles[$manifest->package] = self::bundle($manifest);
            }
        }

        return new DeclaredAddons($manifests, [], $bundles);
    }

    /**
     * A bundle that registers code for exactly the manifest's contributions that run code, or for
     * the given ids, and imports the given modules.
     *
     * @param  list<string>|null  $contributions
     * @param  list<string>  $externals
     * @param  list<string>  $problems
     */
    public static function bundle(AddonManifest $manifest, ?array $contributions = null, array $externals = ['react', '@cboxdk/cms-panel/extend'], array $problems = []): AddonBundle
    {
        $ids = $contributions ?? array_values(array_map(
            static fn (PanelContribution $contribution): string => $contribution->id()->value,
            array_filter($manifest->panel->contributions ?? [], static fn (PanelContribution $contribution): bool => $contribution->runsCode()),
        ));

        return new AddonBundle(new BundleManifest(
            new BundlePath('addon.js'),
            [
                new BundleFile(new BundlePath('addon.js'), BundleIntegrity::of('export default {};'), BundleFileKind::Script),
                new BundleFile(new BundlePath('addon.css'), BundleIntegrity::of('@layer cms.addon {}'), BundleFileKind::Style),
            ],
            $externals,
            array_map(static fn (string $id): ContributionId => new ContributionId($id), $ids),
        ), $problems);
    }

    /**
     * The JSON Schemas of the fixtures' contracts: notes.draft and approvals.request, the query
     * approvals.pending, and the props of notes.detail.sections@1 and notes.detail.actions@1.
     *
     * @param  array<string, string>  $points  other props schemas by point id
     */
    public static function shapes(array $points = []): ContractShapes
    {
        $props = '{"type": "object", "additionalProperties": false, "required": ["count", "note"], "properties": {"count": {"type": "integer"}, "note": {"type": "string"}}}';
        $pointSchemas = ['notes.detail.actions@1' => $props, 'notes.detail.sections@1' => $props, ...$points];

        return new ContractShapes(
            [
                'approvals.request@1' => JsonSchemaNodes::read('{"type": "object", "additionalProperties": false, "required": ["note", "reason"], "properties": {"note": {"type": "string"}, "reason": {"type": ["string", "null"]}}}'),
                'notes.draft@1' => JsonSchemaNodes::read('{"type": "object", "additionalProperties": false, "required": ["fields", "note"], "properties": {"note": {"type": "string"}, "fields": {"$ref": "#/$defs/fields"}}, "$defs": {"fields": {"type": "object", "properties": {"ext": {"type": "object", "additionalProperties": {"type": "object", "additionalProperties": {"$ref": "#/$defs/value"}}}}, "additionalProperties": {"$ref": "#/$defs/value"}}, "value": {"type": ["string", "integer", "boolean", "null", "array", "object"]}}}'),
            ],
            [
                'approvals.pending@1' => JsonSchemaNodes::read('{"type": "object", "additionalProperties": false, "required": ["note"], "properties": {"note": {"type": "string"}}}'),
                'approvals.queue@1' => JsonSchemaNodes::read('{"type": "object", "additionalProperties": false, "properties": {}}'),
            ],
            array_map(JsonSchemaNodes::read(...), $pointSchemas),
        );
    }

    /**
     * The installation's settings: both fixture addons allowed, and the overrides given.
     *
     * @param  list<ContributionOverride>  $overrides
     * @param  list<ReplacementChoice>  $replacements
     * @param  list<string>|null  $allowed
     */
    public static function settings(array $overrides = [], array $replacements = [], ?array $allowed = [self::ADDON, self::STAMPS], ThemeSelection $themes = new ThemeSelection): BuildSettings
    {
        return new BuildSettings($allowed, $overrides, $replacements, themes: $themes);
    }

    public static function build(DeclaredAddons $addons, ?BuildSettings $settings = null, ?ContractShapes $shapes = null, ?ScanRoots $roots = null): CompiledRegistry
    {
        return new BuildRegistry(new AttributeScanner, new RegistryCompiler, new FakeRegistryCache, new FakeOpenApiDocuments, new FakeContractSchemas($shapes ?? self::shapes()), ThemeWorld::compiler(self::THEMES), new FakeThemeStylesheets)
            ->build($roots ?? self::roots(), $addons, $settings ?? self::settings());
    }

    /**
     * The problems a build refuses with.
     *
     * @return list<BuildProblem>
     */
    public static function refused(DeclaredAddons $addons, ?BuildSettings $settings = null, ?ContractShapes $shapes = null, ?ScanRoots $roots = null): array
    {
        try {
            self::build($addons, $settings, $shapes, $roots);
        } catch (RegistryBuildFailed $failed) {
            return $failed->problems;
        }

        Assert::fail('The build wrote the registry.');
    }
}
