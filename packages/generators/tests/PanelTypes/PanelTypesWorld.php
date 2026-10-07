<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\PanelTypes;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\FormCheck;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\Ownership;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Contracts\PanelPoints\ReplacementContribution;
use Cbox\Cms\Contracts\PanelPoints\ReplacementKey;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\IssuedCommand;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Generators\PanelTypes\Adapter\RegistryAddonUiSource;
use Cbox\Cms\Generators\Tests\PanelTypes\Fixtures\NoteFieldV1;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyCodecs;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyNotes;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteCardV1;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteToolbarV1;

/**
 * A registry as cms:build compiles it for an installation with two addons that contribute to the
 * panel: tally, with a slot fill whose data query is tally.notes@1, a form check on the form of
 * entry.create@1, a nav entry, which runs no code, a replacement at a point whose props class
 * names the SDK's NoteFieldProps through HostProps, and the command entry.create@1 it may issue;
 * and approvals, with a slot fill of its own. The source reads it with the kernel's command codecs
 * and the tally queries' codecs, and finds each package in a directory the test gives.
 */
final class PanelTypesWorld
{
    public static function registry(): CompiledRegistry
    {
        $card = new PanelPoint('notes.detail.card', 1, PointKind::Slot, 'notes.detail', '1.0', 'fixture.points.note_card', Region::Sections);
        $checks = new PanelPoint('notes.form.checks', 1, PointKind::FormCheck, 'notes.form', '1.0', 'fixture.points.note_checks');
        $field = new PanelPoint('notes.form.field', 1, PointKind::Replacement, 'notes.form', '1.0', 'fixture.points.note_field', multiplicity: Multiplicity::Exclusive, ownership: Ownership::Own, keyedBy: ReplacementKey::ValueClass);

        return new CompiledRegistry(
            commands: [],
            hooks: [],
            panel: [
                new PanelPointEntry($card, NoteCardV1::class, 'cboxdk/cms', PointStability::Experimental, [
                    new PanelFill(new SlotFill(new ContributionId('tally.badge'), 'notes.detail.card@1', TallyNotes::class), 'acme/cms-tally', 1000, query: CommandRef::fromString('tally.notes@1')),
                    new PanelFill(new SlotFill(new ContributionId('approvals.badge'), 'notes.detail.card@1'), 'acme/cms-approvals', 1000),
                    new PanelFill(new NavContribution(new ContributionId('tally.nav'), 'notes.detail.card@1', 'tally.nav', page: 'notes.detail'), 'acme/cms-tally', 1000),
                ]),
                new PanelPointEntry($checks, NoteToolbarV1::class, 'cboxdk/cms', PointStability::Stable, [
                    new PanelFill(new FormCheck(new ContributionId('tally.title-check'), 'notes.form.checks@1', 'entry.create@1', Severity::Warning), 'acme/cms-tally', 1000, command: CommandRef::fromString('entry.create@1')),
                ]),
                new PanelPointEntry($field, NoteFieldV1::class, 'cboxdk/cms', PointStability::Experimental, [
                    new PanelFill(new ReplacementContribution(new ContributionId('tally.slug-input'), 'notes.form.field@1', 'Acme\\Tally\\Slug'), 'acme/cms-tally', 1000),
                ]),
            ],
            addons: [
                new AddonEntry(new AddonNamespace('approvals'), 'acme/cms-approvals', new CoreApiVersion(1, 0), ClassificationAccess::Public, [], false),
                new AddonEntry(new AddonNamespace('tally'), 'acme/cms-tally', new CoreApiVersion(1, 0), ClassificationAccess::Internal, [new IssuedCommand(CommandRef::fromString('entry.create@1'), CreateEntry::class)], false),
            ],
        );
    }

    /**
     * The source over a cache that holds the registry, or none when it is null, with every
     * package below the root, or none.
     */
    public static function source(?CompiledRegistry $registry, ?string $root): RegistryAddonUiSource
    {
        $cache = new FakeRegistryCache;

        if ($registry instanceof CompiledRegistry) {
            $cache->write($registry);
        }

        return new RegistryAddonUiSource(
            $cache,
            app(CommandCodecs::class),
            new QueryCodecs(...TallyCodecs::all()),
            static fn (string $package): ?string => $root === null ? null : $root.'/'.$package,
        );
    }
}
