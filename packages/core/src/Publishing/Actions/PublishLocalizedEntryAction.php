<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Publishing\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\ReportsVisibility;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Actions\VariantReleasePlanner;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredVariants;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Cbox\Cms\Core\Placements\Actions\PlacementPlanner;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacementLocale;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishLocalizedEntry;
use Cbox\Cms\Core\Publishing\Domain\Dto\PublishLocalizedEntryAggregates;
use Cbox\Cms\Core\Publishing\Domain\Dto\VariantRelease;
use Cbox\Cms\Core\Publishing\Domain\VisibilityReport;
use LogicException;
use Override;

/**
 * The write action of entry.publish version 2 (PRD 6.2, 6.4, 10.3), exposed on every surface. It
 * is composite: its plan is the VariantReleasePlanner's release of each variant the command names,
 * the shared one first, followed by the PlacementPlanner's window of the home placement in the
 * locale, with the canonical moves that window causes, so the releases and the placement going live
 * are authorized, validated and committed as one changeset. It calls no other write action
 * (GUARDRAILS 4.1), and it works for a type with localization variants and for one with
 * localization none alike.
 *
 * resolve() reads the entry with the heads of the variants the command names and its type, the home
 * placement and every placement of the entry in every locale, at the Clock's time. refusals()
 * refuses a placement the actor's regions do not reach (unauthorized), one that is not the entry's
 * home placement, a locale the placement does not have or is withdrawn in, a list of variants
 * without the shared one or, for a type with localization variants, without the locale's, a
 * variant that is neither, and a revision a type with stages none cannot have or a type with stages
 * needs. plan() leaves out what changes nothing: the release of a variant's revision that is
 * released already, and a window when the placement is live already and the command asks for now,
 * or has the window the command asks for. The kernel refuses the releases and the window to an
 * agent (invariant 18). becomesVisible() gives the dry run every placement the plan makes visible
 * (VisibilityReport), decided by the shared variant's release, as invariant 6 has it.
 *
 * @implements WriteAction<PublishLocalizedEntry, PublishLocalizedEntryAggregates>
 * @implements RefusesCommand<PublishLocalizedEntry, PublishLocalizedEntryAggregates>
 * @implements ReportsVisibility<PublishLocalizedEntry, PublishLocalizedEntryAggregates>
 */
#[Action(handles: PublishLocalizedEntry::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class PublishLocalizedEntryAction implements RefusesCommand, ReportsVisibility, WriteAction
{
    public function __construct(
        private EntryReader $entries,
        private PlacementReader $placements,
        private TypeCatalog $types,
        private Clock $clock,
        private VariantReleasePlanner $releases = new VariantReleasePlanner,
        private PlacementPlanner $planner = new PlacementPlanner,
        private VisibilityReport $report = new VisibilityReport,
    ) {}

    /**
     * @param  PublishLocalizedEntry  $command
     */
    #[Override]
    public function resolve(Command $command): PublishLocalizedEntryAggregates
    {
        $at = $this->clock->now();
        $stored = $this->entries->variants($command->entry, $command->keys());

        return new PublishLocalizedEntryAggregates(
            $command->entry,
            $command->variants,
            $stored,
            $stored instanceof StoredVariants ? $this->types->find($stored->type) : null,
            $command->placement,
            $this->placements->placementVersion($command->placement),
            $this->placements->placement($command->placement),
            $command->locale,
            $this->placements->everyLocale($command->entry),
            $at,
        );
    }

    /**
     * @param  PublishLocalizedEntry  $command
     * @param  PublishLocalizedEntryAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $placement = $aggregates->storedPlacement;

        if (! $placement instanceof StoredPlacement) {
            return [new CatalogError(ErrorCode::Unauthorized, new FieldPath('placement'), sprintf(
                'No placement %s is below a node the actor\'s grants reach, and a placement is put live with the rights on its node (PRD 5.10).',
                $command->placement->toString(),
            ))];
        }

        $entry = $aggregates->stored;
        $type = $aggregates->type;

        if (! $entry instanceof StoredVariants || ! $type instanceof TypeDefinition) {
            return [$this->invalid(new FieldPath('entry'), sprintf('The entry %s has no type of this installation.', $command->entry->toString()))];
        }

        if (! $placement->entry->equals($command->entry) || ! $placement->node->equals($entry->home)) {
            return [$this->invalid(new FieldPath('placement'), sprintf(
                'The placement %s is not a placement of the entry %s below its home node %s, which entry.publish puts live; use placement.set_window for another placement.',
                $command->placement->toString(),
                $command->entry->toString(),
                $entry->home->toString(),
            ))];
        }

        $errors = [];
        $locale = $placement->locale($command->locale);

        if (! $locale instanceof StoredPlacementLocale) {
            $errors[] = $this->invalid(new FieldPath('locale'), sprintf('The placement %s has no locale %s.', $command->placement->toString(), $command->locale->value));
        } elseif ($locale->visibility === Visibility::Withdrawn) {
            $errors[] = $this->invalid(new FieldPath('locale'), sprintf('The placement %s is withdrawn in %s, and only a reinstatement can change that (invariant 7).', $command->placement->toString(), $command->locale->value));
        }

        return [...$errors, ...$this->variantRefusals($command, $type)];
    }

    /**
     * @param  PublishLocalizedEntry  $command
     * @param  PublishLocalizedEntryAggregates  $aggregates
     *
     * @throws LogicException when the entry, its type or the placement was read as absent, which the kernel rules out first
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $entry = $aggregates->stored;
        $placement = $aggregates->storedPlacement?->locale($command->locale);

        if (! $entry instanceof StoredVariants || ! $aggregates->type instanceof TypeDefinition || ! $placement instanceof StoredPlacementLocale) {
            throw new LogicException(sprintf('entry.publish planned the entry %s, which it read without a type or a home placement in %s; the kernel refuses such a call first.', $command->entry->toString(), $command->locale->value));
        }

        $steps = [];

        foreach ($this->sharedFirst($command->variants) as $variant) {
            if ($variant->revision instanceof RevisionNumber && $aggregates->type->capabilities->stages !== Stages::None) {
                $steps[] = $this->releases->plan($entry->of($variant->variant), $variant->revision, $variant->variant);
            }
        }

        if ($this->changesWindow($command, $placement, $aggregates)) {
            $steps[] = $this->planner->setWindow(
                $command->placement,
                $command->locale,
                $command->window ?? new TimeWindow($aggregates->at),
                $aggregates->inLocale(),
                $aggregates->at,
            );
        }

        return new Plan(...$steps);
    }

    /**
     * @param  PublishLocalizedEntry  $command
     * @param  PublishLocalizedEntryAggregates  $aggregates
     */
    #[Override]
    public function becomesVisible(Command $command, Aggregates $aggregates, Plan $plan): array
    {
        $releasedBefore = $aggregates->sharedReleased();
        $sharedReleases = array_filter(
            $plan->mutations(),
            static fn (Mutation $mutation): bool => $mutation instanceof VariantReleased && $mutation->variant->isShared(),
        );

        return $this->report->of($releasedBefore, $releasedBefore || $sharedReleases !== [], $aggregates->everywhere, $plan, $aggregates->at);
    }

    /**
     * The refusals of the variants the command names: the shared one and, for a type with
     * localization variants, the locale's must be among them, no other may, and each names a
     * revision exactly when the type has stages.
     *
     * @return list<CatalogError>
     */
    private function variantRefusals(PublishLocalizedEntry $command, TypeDefinition $type): array
    {
        $errors = [];
        $localized = $type->capabilities->localization === Localization::Variants;
        $staged = $type->capabilities->stages !== Stages::None;
        $named = array_map(static fn (VariantKey $variant): string => $variant->value, $command->keys());

        if (! in_array(VariantKey::SHARED, $named, true)) {
            $errors[] = $this->invalid(new FieldPath('variants'), 'entry.publish names the shared variant: every locale serves it, so the entry is shown only once it is released (invariant 6).');
        }

        if ($localized && ! in_array($command->localeVariant()->value, $named, true)) {
            $errors[] = $this->invalid(new FieldPath('variants'), sprintf(
                'The type %s has localization variants, so entry.publish in %s names the variant %s, whose content the locale is served from (PRD 10.1).',
                $type->name->value,
                $command->locale->value,
                $command->localeVariant()->value,
            ));
        }

        foreach ($command->variants as $index => $variant) {
            if (! $variant->variant->isShared() && (! $localized || ! $variant->variant->equals($command->localeVariant()))) {
                $errors[] = $this->invalid(new FieldPath('variants', $index, 'variant'), $localized
                    ? sprintf('entry.publish in %s publishes the shared variant and the variant %s; the variant %s is published in its own locale.', $command->locale->value, $command->localeVariant()->value, $variant->variant->value)
                    : sprintf('The type %s has localization none, whose entries have the shared variant alone (PRD 10.1).', $type->name->value));
            }

            if ($staged && ! $variant->revision instanceof RevisionNumber) {
                $errors[] = $this->invalid(new FieldPath('variants', $index, 'revision'), sprintf('The type %s has stages, so entry.publish names the revision of the variant %s to release.', $type->name->value, $variant->variant->value));
            }

            if (! $staged && $variant->revision instanceof RevisionNumber) {
                $errors[] = $this->invalid(new FieldPath('variants', $index, 'revision'), sprintf('The type %s has stages none: its entries are public as soon as they are saved, so there is no revision to release; leave the revision out.', $type->name->value));
            }
        }

        return $errors;
    }

    /**
     * The variants with the shared one first and the others in the order given, so the releases
     * of one run release the shared variant before a language.
     *
     * @param  non-empty-list<VariantRelease>  $variants
     * @return list<VariantRelease>
     */
    private function sharedFirst(array $variants): array
    {
        return [
            ...array_filter($variants, static fn (VariantRelease $variant): bool => $variant->variant->isShared()),
            ...array_filter($variants, static fn (VariantRelease $variant): bool => ! $variant->variant->isShared()),
        ];
    }

    /**
     * Whether the command changes the placement's window: a window it has not, or now for a
     * placement that is not live now.
     */
    private function changesWindow(PublishLocalizedEntry $command, StoredPlacementLocale $placement, PublishLocalizedEntryAggregates $aggregates): bool
    {
        if (! $command->window instanceof TimeWindow) {
            return ! $placement->visibility->visibleAt($placement->window, $aggregates->at);
        }

        return ! $placement->window instanceof TimeWindow
            || ! $placement->window->equals($command->window)
            || $placement->visibility !== Visibility::of($command->window, $aggregates->at);
    }

    private function invalid(FieldPath $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, $path, $message);
    }
}
