<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Scaffold;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\Confirm;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\FlowStep;
use Cbox\Cms\Contracts\PanelPoints\FormCheck;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Contracts\PanelPoints\Tone;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\IssuedCommand;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\PointSchemaDirectory;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\NumberLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\ObjectLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Property;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\StringLiteral;
use Cbox\Cms\Generators\PanelStories\Adapter\RegistryPanelPointSource;
use Cbox\Cms\Generators\PanelStories\Domain\PanelPointSource;
use Cbox\Cms\Generators\PanelTypes\Adapter\RegistryAddonUiSource;
use Cbox\Cms\Generators\PanelTypes\Domain\AddonUiSource;
use Cbox\Cms\Generators\Scaffold\Adapter\CodecDocumentSamples;
use Cbox\Cms\Generators\Scaffold\Domain\DocumentSamples;
use Cbox\Cms\Generators\Tests\Scaffold\Fakes\FakeDocumentSamples;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Panel\Shell\Domain\Dto\ShellNavV1;
use Cbox\Cms\Panel\Shell\Domain\Dto\ShellPageV1;
use Cbox\Cms\Panel\Shell\Domain\Dto\ViewerSummaryV1;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyCodecs;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyNotes;

/**
 * A registry as cms:build compiles it for an installation whose points take the props types the
 * SDK exports today, the shell's ShellNavV1, ShellPageV1 and ViewerSummaryV1, so that a stub
 * written for them type checks against the SDK: a slot point notes.detail.card@1, a form check
 * point notes.form.checks@1, a flow step point notes.form.steps@1, and the shell's own
 * shell.user-menu@1 and shell.nav@1. The addon tally (acme/cms-tally) contributes a fill with the
 * data query tally.notes@1, a form check and a flow step on entry.create@1, an action and a nav
 * entry, and may issue entry.create@1; the addon approvals contributes nothing that runs code.
 */
final class ScaffoldWorld
{
    public const string PACKAGE = 'acme/cms-tally';

    public const string NAMESPACE = 'tally';

    /** Where the fake output has the tally addon's package. */
    public const string ROOT = '/srv/addons/acme/cms-tally';

    /** The schemas of the shell's points, which the world's points borrow. */
    private const string SHELL_SCHEMAS = __DIR__.'/../../../panel/resources/schemas/points';

    private function __construct() {}

    public static function registry(): CompiledRegistry
    {
        $card = new PanelPoint('notes.detail.card', 1, PointKind::Slot, 'notes.detail', '1.0', 'fixture.points.note_card', Region::Sections);
        $checks = new PanelPoint('notes.form.checks', 1, PointKind::FormCheck, 'notes.form', '1.0', 'fixture.points.note_checks');
        $steps = new PanelPoint('notes.form.steps', 1, PointKind::FlowStep, 'notes.form', '1.0', 'fixture.points.note_steps');
        $menu = new PanelPoint('shell.user-menu', 1, PointKind::Action, 'shell', '1.0', 'panel.points.shell_user_menu');
        $nav = new PanelPoint('shell.nav', 1, PointKind::Nav, 'shell', '1.0', 'panel.points.shell_nav');
        $entryCreate = CommandRef::fromString('entry.create@1');

        return new CompiledRegistry(
            commands: [],
            hooks: [],
            panel: [
                new PanelPointEntry($card, ViewerSummaryV1::class, 'cboxdk/cms', PointStability::Experimental, [
                    new PanelFill(new SlotFill(new ContributionId('tally.badge'), 'notes.detail.card@1', TallyNotes::class), self::PACKAGE, 1000, query: CommandRef::fromString('tally.notes@1')),
                    new PanelFill(new SlotFill(new ContributionId('approvals.badge'), 'notes.detail.card@1'), 'acme/cms-approvals', 1000),
                ]),
                new PanelPointEntry($checks, ShellPageV1::class, 'cboxdk/cms', PointStability::Experimental, [
                    new PanelFill(new FormCheck(new ContributionId('tally.title-check'), 'notes.form.checks@1', 'entry.create@1', Severity::Warning), self::PACKAGE, 1000, command: $entryCreate),
                ]),
                new PanelPointEntry($steps, ShellNavV1::class, 'cboxdk/cms', PointStability::Experimental, [
                    new PanelFill(new FlowStep(new ContributionId('tally.confirm'), 'notes.form.steps@1', 'entry.create@1', StepPosition::BeforeSubmit, ['fields.ext.tally.reason']), self::PACKAGE, 1000, command: $entryCreate),
                ]),
                new PanelPointEntry($menu, ViewerSummaryV1::class, 'cboxdk/cms', PointStability::Experimental, [
                    new PanelFill(new ActionContribution(new ContributionId('tally.request'), 'shell.user-menu@1', CreateEntry::class, 'tally.request', 'plus', ['note' => '/actor'], Confirm::DryRun, Tone::Warning), self::PACKAGE, 1000, command: $entryCreate),
                ]),
                new PanelPointEntry($nav, ShellNavV1::class, 'cboxdk/cms', PointStability::Experimental, [
                    new PanelFill(new NavContribution(new ContributionId('tally.nav'), 'shell.nav@1', 'tally.nav', page: 'notes.detail'), self::PACKAGE, 1000),
                ]),
            ],
            addons: [
                new AddonEntry(new AddonNamespace('approvals'), 'acme/cms-approvals', new CoreApiVersion(1, 0), ClassificationAccess::Public, [], false),
                new AddonEntry(new AddonNamespace(self::NAMESPACE), self::PACKAGE, new CoreApiVersion(1, 0), ClassificationAccess::Internal, [new IssuedCommand($entryCreate, CreateEntry::class)], false),
            ],
        );
    }

    /**
     * A cache that holds the registry, or none when it is null.
     */
    public static function cache(?CompiledRegistry $registry): RegistryCache
    {
        $cache = new FakeRegistryCache;

        if ($registry instanceof CompiledRegistry) {
            $cache->write($registry);
        }

        return $cache;
    }

    /**
     * The addon source over the cache, with every package below the root, or none when it is null.
     */
    public static function addons(RegistryCache $cache, ?string $root): RegistryAddonUiSource
    {
        return new RegistryAddonUiSource(
            $cache,
            app(CommandCodecs::class),
            self::queries(),
            static fn (string $package): ?string => $root === null ? null : $root.'/'.$package,
        );
    }

    /**
     * The point source over the cache, with the schemas of the world's points.
     */
    public static function points(RegistryCache $cache): RegistryPanelPointSource
    {
        return new RegistryPanelPointSource($cache, [new PointSchemaDirectory(self::schemas())]);
    }

    /**
     * The samples from the kernel's command codecs and the tally queries' codecs.
     */
    public static function samples(): CodecDocumentSamples
    {
        return new CodecDocumentSamples(app(CommandCodecs::class), self::queries());
    }

    /**
     * Binds the application's sources to the world's registry, with every package below a scratch
     * directory that holds the tally addon's composer.json, and gives the addon's package
     * directory, for the commands' tests.
     */
    public static function bindToApplication(): string
    {
        $cache = self::cache(self::registry());
        $scratch = SchemaFixtures::scratch();
        app()->instance(RegistryCache::class, $cache);
        app()->bind(AddonUiSource::class, static fn (): RegistryAddonUiSource => self::addons($cache, $scratch));
        app()->bind(PanelPointSource::class, static fn (): RegistryPanelPointSource => self::points($cache));
        app()->bind(DocumentSamples::class, static fn (): CodecDocumentSamples => self::samples());
        SchemaFixtures::write($scratch.'/'.self::PACKAGE.'/composer.json', self::composerJson());

        return $scratch.'/'.self::PACKAGE;
    }

    /**
     * Fake samples of entry.create@1 and tally.notes@1, small enough to read in a stub.
     */
    public static function fakeSamples(): FakeDocumentSamples
    {
        return new FakeDocumentSamples()
            ->withCommand(CommandRef::fromString('entry.create@1'), new ObjectLiteral([
                new Property('entry', new StringLiteral('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01')),
                new Property('fields', new ObjectLiteral([])),
            ]))
            ->withResult(CommandRef::fromString('tally.notes@1'), new ObjectLiteral([new Property('count', new NumberLiteral('0'))]));
    }

    public static function queries(): QueryCodecs
    {
        return new QueryCodecs(...TallyCodecs::all());
    }

    /**
     * A directory with the schemas of the world's points: the shell's, and the shell's schemas
     * again under the names of the three points that borrow their props.
     */
    public static function schemas(): string
    {
        $directory = SchemaFixtures::scratch().'/points';

        foreach (['shell.nav', 'shell.page', 'shell.user-menu'] as $name) {
            SchemaFixtures::write($directory.'/'.$name.'.v1.json', (string) file_get_contents(self::SHELL_SCHEMAS.'/'.$name.'.v1.json'));
        }

        foreach (['notes.detail.card' => 'shell.user-menu', 'notes.form.checks' => 'shell.page', 'notes.form.steps' => 'shell.nav'] as $point => $shell) {
            SchemaFixtures::write($directory.'/'.$point.'.v1.json', (string) file_get_contents(self::SHELL_SCHEMAS.'/'.$shell.'.v1.json'));
        }

        return $directory;
    }

    /**
     * The composer.json of the tally addon, as a scaffold reads it.
     */
    public static function composerJson(): string
    {
        return <<<'JSON'
            {
                "name": "acme/cms-tally",
                "type": "library",
                "require": {"cboxdk/cms": "^1.0"},
                "autoload": {"psr-4": {"Acme\\Tally\\": "src/"}},
                "autoload-dev": {"psr-4": {"Acme\\Tally\\Tests\\": "tests/"}},
                "extra": {"laravel": {"providers": ["Acme\\Tally\\TallyServiceProvider"]}}
            }

            JSON;
    }
}
