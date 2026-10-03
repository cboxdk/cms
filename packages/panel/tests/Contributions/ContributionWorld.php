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
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointName;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
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
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeContractSchemas;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeOpenApiDocuments;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\PanelBuildWorld;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PointCodec;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk\DeskCardsCodec;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\HeavyTally;
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
 * and ASIDE to desk.aside@1.
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

    /** The permission AUDIT requires. */
    public const string AUDIT_PERMISSION = 'tally.audit';

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
            new AddonCapabilities($reads),
            panel: new PanelContributions(PanelApiVersion::current(), PanelBuildWorld::BUNDLE, ['desk.cards@1', 'desk.aside@1'], $contributions ?? self::contributions()),
        );
    }

    /**
     * The registry cms:build compiles for the host and the addon.
     *
     * @param  list<ContributionOverride>  $overrides
     */
    public static function registry(?AddonManifest $manifest = null, array $overrides = []): CompiledRegistry
    {
        $manifest ??= self::manifest();

        return new BuildRegistry(new AttributeScanner, new RegistryCompiler, new FakeRegistryCache, new FakeOpenApiDocuments, new FakeContractSchemas(self::shapes()))->build(
            new ScanRoots(new ScanRoot(self::HOST, __DIR__.'/Fixtures/Desk'), new ScanRoot(self::ADDON, __DIR__.'/Fixtures/Tally')),
            new DeclaredAddons([$manifest], [], [self::ADDON => PanelBuildWorld::bundle($manifest)]),
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
        return new PointCodecs(new PointCodec(new PointId(new PointName('desk.cards'), 1), new DeskCardsCodec));
    }

    /**
     * The JSON Schemas cms:build checks the contributions against: the props of the slots and the
     * input of the queries.
     */
    public static function shapes(): ContractShapes
    {
        return new ContractShapes(
            [],
            [
                'tally.heavy@1' => JsonSchemaNodes::read(TallyCodecs::INPUT),
                'tally.notes@1' => JsonSchemaNodes::read(TallyCodecs::INPUT),
            ],
            [
                'desk.aside@1' => JsonSchemaNodes::read('{"type":"object","additionalProperties":false,"required":["note"],"properties":{"note":{"type":"string"}}}'),
                'desk.cards@1' => JsonSchemaNodes::read(DeskCardsCodec::SCHEMA),
            ],
        );
    }
}
