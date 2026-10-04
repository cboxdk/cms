<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\Confirm;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointName;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Core\PanelThemes\Actions\CompilePanelThemes;
use Cbox\Cms\Core\Registry\Actions\BuildRegistry;
use Cbox\Cms\Core\Registry\Boundary\JsonSchemaNodes;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\ContractShapes;
use Cbox\Cms\Core\Registry\Domain\Dto\ContributionOverride;
use Cbox\Cms\Core\Registry\Domain\Dto\DeclaredAddons;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Cms\Core\Tests\PanelThemes\Fakes\FakeThemeSources;
use Cbox\Cms\Core\Tests\PanelThemes\Fakes\FakeThemeStylesheets;
use Cbox\Cms\Core\Tests\Pipeline\Tally\AddTally;
use Cbox\Cms\Core\Tests\Pipeline\Tally\AddTallyCodec;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeContractSchemas;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeOpenApiDocuments;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\PanelBuildWorld;
use Cbox\Cms\Panel\Boundary\Generated\Points\PanelPointCodecs;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PointCodec;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\PanelServiceProvider;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk\DeskCardsCodec;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\HeavyTally;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyBoard;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyCodecs;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyNotes;

/**
 * The world of the panel's contributions (PRD 13.4): the host fixture Desk (acme/desk, the page
 * desk.overview with the slots desk.cards@1, props note and a confidential memo, and desk.aside@1,
 * which has no codec) and the addon fixture Tally (acme/cms-tally, namespace tally, reads
 * internal, its queries tally.notes and tally.heavy, the second above every budget), compiled by
 * cms:build's code with the addon's manifest, which contributes to desk.cards@1:
 *
 * - COUNT, at 20, reading its data with tally.notes;
 * - AUDIT, at 10, shown only to a viewer who holds tally.audit;
 * - HEAVY, at 30, reading its data with tally.heavy;
 *
 * ASIDE to desk.aside@1, and to the panel's shell, whose points the panel's own scan root
 * declares: the page BOARD at /x/tally/board, reading its data with tally.board and shown only to
 * a viewer who holds tally.board, the nav entry BOARD_LINK that opens it, and the action ADD of the
 * viewer's menu, which runs the core tests' tally.add for the viewer's own tally after a dry run;
 * the addon's capabilities let it issue tally.add.
 */
final class ContributionWorld
{
    public const string HOST = 'acme/desk';

    public const string ADDON = 'acme/cms-tally';

    public const string PAGE = 'desk.overview';

    public const string COUNT = 'tally.count';

    public const string AUDIT = 'tally.audit';

    public const string HEAVY = 'tally.heavy';

    public const string ASIDE = 'tally.aside';

    /** The addon's page, at /x/tally/board, reading its data with tally.board. */
    public const string BOARD = 'tally.board';

    /** The path of BOARD below /x/tally/. */
    public const string BOARD_PATH = 'board';

    /** The nav entry that opens BOARD. */
    public const string BOARD_LINK = 'tally.board-link';

    /** The action of the viewer's menu that runs tally.add for the viewer's own tally. */
    public const string ADD = 'tally.add-one';

    /** The permission AUDIT requires. */
    public const string AUDIT_PERMISSION = 'tally.audit';

    /** The permission BOARD requires, the read of its data. */
    public const string BOARD_PERMISSION = 'tally.board';

    /** The permission ADD needs: the command it runs. */
    public const string ADD_PERMISSION = 'tally.add';

    /** The tally command's directory in the core's tests, scanned as part of the addon's package. */
    public const string TALLY_COMMAND = __DIR__.'/../../../core/tests/Pipeline/Tally';

    /** The points the addon's manifest accepts: the host's two and the panel's three shell points. */
    public const array POINTS = ['desk.cards@1', 'desk.aside@1', 'shell.nav@1', 'shell.page@1', 'shell.user-menu@1'];

    /**
     * The addon's contributions.
     *
     * @return list<PanelContribution>
     */
    public static function contributions(): array
    {
        return [
            new SlotFill(new ContributionId(self::COUNT), 'desk.cards@1', TallyNotes::class, 20),
            new SlotFill(new ContributionId(self::AUDIT), 'desk.cards@1', priority: 10, scope: new Scope(requires: new CommandName(self::AUDIT_PERMISSION))),
            new SlotFill(new ContributionId(self::HEAVY), 'desk.cards@1', HeavyTally::class, 30),
            new SlotFill(new ContributionId(self::ASIDE), 'desk.aside@1'),
            new PageContribution(new ContributionId(self::BOARD), 'shell.page@1', self::BOARD_PATH, TallyBoard::class, scope: new Scope(requires: new CommandName(self::BOARD_PERMISSION))),
            new NavContribution(new ContributionId(self::BOARD_LINK), 'shell.nav@1', 'tally.nav.board', self::BOARD, 'inbox'),
            new ActionContribution(new ContributionId(self::ADD), 'shell.user-menu@1', AddTally::class, 'tally.add.label', 'plus', ['tally' => '/actor'], Confirm::DryRun),
        ];
    }

    /**
     * The addon's manifest.
     *
     * @param  list<PanelContribution>|null  $contributions  null for contributions()
     */
    public static function manifest(?array $contributions = null, ClassificationAccess $reads = ClassificationAccess::Internal): AddonManifest
    {
        return new AddonManifest(
            self::ADDON,
            new AddonNamespace('tally'),
            new CoreApiVersion(CoreApiVersion::CURRENT_MAJOR, CoreApiVersion::CURRENT_MINOR),
            __DIR__.'/Fixtures/Tally',
            new AddonCapabilities($reads, [AddTally::class]),
            panel: new PanelContributions(PanelApiVersion::current(), PanelBuildWorld::BUNDLE, self::POINTS, $contributions ?? self::contributions()),
        );
    }

    /**
     * The registry cms:build compiles for the host and the addon.
     *
     * @param  list<ContributionOverride>  $overrides
     * @param  list<PanelContribution>  $core  the core's own contributions, in the namespace cms
     */
    public static function registry(?AddonManifest $manifest = null, array $overrides = [], array $core = []): CompiledRegistry
    {
        $manifest ??= self::manifest();

        return new BuildRegistry(new AttributeScanner, new RegistryCompiler, new FakeRegistryCache, new FakeOpenApiDocuments('tally.add@1', 'tally.board@1'), new FakeContractSchemas(self::shapes()), new CompilePanelThemes(new FakeThemeSources), new FakeThemeStylesheets)->build(
            new ScanRoots(
                new ScanRoot(self::HOST, __DIR__.'/Fixtures/Desk'),
                new ScanRoot(self::ADDON, __DIR__.'/Fixtures/Tally'),
                new ScanRoot(self::ADDON, self::TALLY_COMMAND),
                new ScanRoot(PanelServiceProvider::PACKAGE, dirname(__DIR__, 2).'/src'),
            ),
            new DeclaredAddons([$manifest], [], [self::ADDON => PanelBuildWorld::bundle($manifest)], $core),
            new BuildSettings(null, $overrides),
        );
    }

    /**
     * The registry cache, holding the registry.
     */
    public static function cache(CompiledRegistry $registry): FakeRegistryCache
    {
        $cache = new FakeRegistryCache;
        $cache->write($registry);

        return $cache;
    }

    /**
     * The codec of desk.cards@1; desk.aside@1 has none.
     */
    public static function pointCodecs(): PointCodecs
    {
        return new PointCodecs(new PointCodec(new PointId(new PointName('desk.cards'), 1), new DeskCardsCodec), ...PanelPointCodecs::all());
    }

    /**
     * The JSON Schemas cms:build checks the contributions against: the props of the slots and the
     * input of the queries.
     */
    public static function shapes(): ContractShapes
    {
        $points = [];

        foreach (['shell.nav', 'shell.page', 'shell.user-menu'] as $shell) {
            $points[$shell.'@1'] = JsonSchemaNodes::read((string) file_get_contents(PanelServiceProvider::pointSchemaDirectory().'/'.$shell.'.v1.json'));
        }

        return new ContractShapes(
            ['tally.add@1' => JsonSchemaNodes::read(AddTallyCodec::SCHEMA)],
            [
                'tally.board@1' => JsonSchemaNodes::read(TallyCodecs::NO_INPUT),
                'tally.heavy@1' => JsonSchemaNodes::read(TallyCodecs::INPUT),
                'tally.notes@1' => JsonSchemaNodes::read(TallyCodecs::INPUT),
            ],
            [
                'desk.aside@1' => JsonSchemaNodes::read('{"type":"object","additionalProperties":false,"required":["note"],"properties":{"note":{"type":"string"}}}'),
                'desk.cards@1' => JsonSchemaNodes::read(DeskCardsCodec::SCHEMA),
                ...$points,
            ],
        );
    }
}
