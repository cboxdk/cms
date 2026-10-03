<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\PanelStories;

use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\Confirm;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\DecoratorContribution;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Contracts\PanelPoints\Tone;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\PointSchemaDirectory;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Generators\PanelStories\Adapter\RegistryPanelPointSource;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteCardV1;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteToolbarV1;

/**
 * A registry as cms:build compiles it with four panel points of the panel's point fixtures:
 *
 * - notes.detail.card@1, a slot in the sections region with a props schema, with the core's own
 *   contribution at 100 and an addon's at 1000, and one the installation disabled;
 * - notes.list.toolbar@1, an action point of at most two with a props schema, with an addon's
 *   action;
 * - notes.form.submit@1, a decorator without a schema, with an addon's decorator;
 * - notes.wiring@1, an #[Internal] slot without a schema or a contribution.
 *
 * The source reads its schemas from the panel's point fixtures.
 */
final class PanelStoriesWorld
{
    /** The schemas of the panel's point fixtures. */
    public const string SCHEMAS = __DIR__.'/../../../panel/tests/Points/Fixtures/schemas';

    /** The golden modules of the world, which a test of the JS suite renders and tsc checks. */
    public const string GOLDEN = __DIR__.'/../../../../js/panel/tests/host/stories-golden/generated';

    public static function registry(): CompiledRegistry
    {
        $card = new PanelPoint('notes.detail.card', 1, PointKind::Slot, 'notes.detail', '1.0', 'fixture.points.note_card', Region::Sections);
        $toolbar = new PanelPoint('notes.list.toolbar', 1, PointKind::Action, 'notes.list', '1.0', 'fixture.points.notes_toolbar', multiplicity: Multiplicity::Max, max: 2);
        $submit = new PanelPoint('notes.form.submit', 1, PointKind::Decorator, 'notes.form', '1.0', 'fixture.points.note_submit', tightens: [Tighten::Description, Tighten::ToneTowardsDanger]);
        $wiring = new PanelPoint('notes.wiring', 1, PointKind::Slot, 'notes.detail', '1.0', 'fixture.points.note_wiring', Region::Aside);

        return new CompiledRegistry(
            commands: [],
            hooks: [],
            panel: [
                new PanelPointEntry($card, NoteCardV1::class, 'cboxdk/cms', PointStability::Experimental, [
                    new PanelFill(new SlotFill(new ContributionId('cms.summary'), 'notes.detail.card@1', priority: 100), 'cboxdk/cms', 100),
                    new PanelFill(new SlotFill(new ContributionId('approvals.badge'), 'notes.detail.card@1'), 'acme/cms-approvals', 1000),
                    new PanelFill(new SlotFill(new ContributionId('approvals.hidden'), 'notes.detail.card@1'), 'acme/cms-approvals', 1000, enabled: false),
                ]),
                new PanelPointEntry($submit, NoteToolbarV1::class, 'cboxdk/cms', PointStability::Experimental, [
                    new PanelFill(new DecoratorContribution(new ContributionId('approvals.guard'), 'notes.form.submit@1', [Tighten::Description]), 'acme/cms-approvals', 1000),
                ]),
                new PanelPointEntry($toolbar, NoteToolbarV1::class, 'cboxdk/cms', PointStability::Stable, [
                    new PanelFill(new ActionContribution(new ContributionId('approvals.request'), 'notes.list.toolbar@1', CreateEntry::class, 'approvals.request', 'plus', ['note' => '/filter'], Confirm::DryRun, Tone::Warning), 'acme/cms-approvals', 1000, command: CommandRef::fromString('entry.create@1')),
                ]),
                new PanelPointEntry($wiring, NoteCardV1::class, 'cboxdk/cms', PointStability::Internal),
            ],
        );
    }

    /**
     * The source over a cache that holds the registry, or none when it is null.
     */
    public static function source(?CompiledRegistry $registry): RegistryPanelPointSource
    {
        $cache = new FakeRegistryCache;

        if ($registry instanceof CompiledRegistry) {
            $cache->write($registry);
        }

        return new RegistryPanelPointSource($cache, [new PointSchemaDirectory(self::SCHEMAS)]);
    }
}
