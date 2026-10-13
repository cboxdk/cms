<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\VariantWithdrawn;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Domain\Commands\DeleteVariant;
use Cbox\Cms\Core\Entries\Domain\Dto\DeleteVariantAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Cbox\Cms\Core\Placements\Actions\PlacementPlanner;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use LogicException;
use Override;

/**
 * The write action of variant.delete (PRD 6.2, 10.3), exposed on every surface: it deletes one
 * translation of an entry and leaves the entry and its other variants as they are. It is
 * composite: its plan is VariantWithdrawn of the language's variant followed by the
 * PlacementPlanner's withdrawal of every placement of the entry in that locale, on every site, in
 * one changeset. It calls no other write action (GUARDRAILS 4.1).
 *
 * resolve() reads the entry with the head of the variant, its type, and for a language's variant
 * every placement of the entry in the variant's locale, past the actor's regions, because a
 * deletion is decided on the entry's home in the locale (PRD 5.10). The command expects the
 * variant at a version, so an entry the actor cannot reach, or a variant the entry does not have,
 * is the kernel's version_conflict.
 *
 * refusals() refuses, with validation_failed, a type without localization variants, whose one
 * variant is the shared one (PRD 10.1), the shared variant, which holds the fields every language
 * shares and is the entry's own content, and a variant withdrawn already, which only a
 * reinstatement changes (invariant 7).
 *
 * @implements WriteAction<DeleteVariant, DeleteVariantAggregates>
 * @implements RefusesCommand<DeleteVariant, DeleteVariantAggregates>
 */
#[Action(handles: DeleteVariant::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class DeleteVariantAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private EntryReader $entries,
        private PlacementReader $placements,
        private TypeCatalog $types,
        private PlacementPlanner $planner = new PlacementPlanner,
    ) {}

    /**
     * @param  DeleteVariant  $command
     */
    #[Override]
    public function resolve(Command $command): DeleteVariantAggregates
    {
        $stored = $this->entries->entry($command->entry, $command->variant);
        $locale = $command->variant->locale;

        return new DeleteVariantAggregates(
            $command->ref(),
            $stored,
            $stored instanceof StoredEntry ? $this->types->find($stored->type) : null,
            $stored instanceof StoredEntry && $locale instanceof Locale ? $this->placements->placements($command->entry, $locale) : null,
        );
    }

    /**
     * @param  DeleteVariant  $command
     * @param  DeleteVariantAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $type = $aggregates->type;

        if (! $type instanceof TypeDefinition) {
            return [$this->invalid('entry', sprintf('The entry %s has no type of this installation.', $command->entry->toString()))];
        }

        if ($type->capabilities->localization !== Localization::Variants) {
            return [$this->invalid('variant', sprintf(
                'The type %s has localization %s, whose entries have the shared variant alone (PRD 10.1); variant.delete deletes a language\'s variant of a type with localization variants.',
                $type->name->value,
                $type->capabilities->localization->value,
            ))];
        }

        if ($command->variant->isShared()) {
            return [$this->invalid('variant', 'variant.delete deletes a language\'s variant; the shared variant holds the fields every language shares and stays as long as the entry.')];
        }

        if ($aggregates->stored?->head?->withdrawn === true) {
            return [$this->invalid('variant', sprintf(
                'The variant %s of the entry %s is withdrawn already, and only a reinstatement can change that (invariant 7).',
                $command->variant->value,
                $command->entry->toString(),
            ))];
        }

        return [];
    }

    /**
     * @param  DeleteVariant  $command
     * @param  DeleteVariantAggregates  $aggregates
     *
     * @throws LogicException when the entry or its placements were read as absent, which the kernel's check of the expected version and refusals() rule out first
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        if (! $aggregates->stored instanceof StoredEntry || ! $aggregates->placements instanceof LocalePlacements) {
            throw new LogicException(sprintf(
                'variant.delete planned the variant %s of the entry %s, which was read as absent or has no locale; the kernel and refusals() reject such a call before it plans.',
                $command->variant->value,
                $command->entry->toString(),
            ));
        }

        return new Plan(
            new VariantWithdrawn($command->entry, $command->variant),
            $this->planner->withdraw($aggregates->placements),
        );
    }

    private function invalid(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), $message);
    }
}
