<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\CreatePlacementAggregates;
use Cbox\Cms\Core\Placements\Domain\Dto\SlugClaim;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Placements\Domain\PlacementSlugRef;
use Override;

/**
 * The write action of placement.create (PRD 5.7, 6.2), exposed on every surface. resolve() reads
 * the placement, which the command expects to be absent, the entry, the node and the site, whether
 * each slug is taken below the node, and every placement of the entry in each locale, at the
 * Clock's time; refusals() refuses what those reads rule out; plan() is PlacementPlanner::create().
 *
 * It is decided on the placement's node (PRD 5.10): a node the actor's regions do not reach reads
 * as absent and the call is unauthorized, whatever the entry's home. The entry must be one the
 * actor can read, the node a node of the site that is not a mount, each locale one the site
 * publishes in and given once, and each slug free below the node (invariant 15).
 *
 * @implements WriteAction<CreatePlacement, CreatePlacementAggregates>
 * @implements RefusesCommand<CreatePlacement, CreatePlacementAggregates>
 */
#[Action(handles: CreatePlacement::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class CreatePlacementAction implements RefusesCommand, WriteAction
{
    /** Where the command holds its slugs, for the paths of errors. */
    public const string SLUGS = 'slugs';

    public function __construct(
        private PlacementReader $placements,
        private Clock $clock,
        private PlacementPlanner $planner = new PlacementPlanner,
    ) {}

    /**
     * @param  CreatePlacement  $command
     */
    #[Override]
    public function resolve(Command $command): CreatePlacementAggregates
    {
        $at = $this->clock->now();
        $slugs = [];
        $placements = [];
        $locales = [];

        foreach ($command->slugs as $slug) {
            $slugs[] = new SlugClaim(
                new PlacementSlugRef($command->node, $slug->locale, $slug->slug),
                $this->placements->slugTaken($command->node, $slug->locale, $slug->slug),
            );

            if (! isset($locales[$slug->locale->value])) {
                $locales[$slug->locale->value] = true;
                $placements[] = $this->placements->placements($command->entry, $slug->locale);
            }
        }

        return new CreatePlacementAggregates(
            $command->placement,
            $this->placements->placementVersion($command->placement),
            $command->entry,
            $this->placements->entry($command->entry),
            $command->node,
            $this->placements->node($command->node),
            $command->site,
            $this->placements->site($command->site),
            $slugs,
            $placements,
            $at,
        );
    }

    /**
     * @param  CreatePlacement  $command
     * @param  CreatePlacementAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $node = $aggregates->storedNode;

        if (! $node instanceof StoredNode) {
            return [new CatalogError(ErrorCode::Unauthorized, new FieldPath('node'), sprintf(
                'No node %s is reached by the actor\'s grants, and a placement is created with the rights on its node (PRD 5.10).',
                $command->node->toString(),
            ))];
        }

        $errors = [];
        $site = $aggregates->storedSite;

        if ($node->mount) {
            $errors[] = $this->invalid(new FieldPath('node'), sprintf('The node %s is a mount, which shows the placements of its source and holds none of its own (PRD 5.8).', $command->node->toString()));
        }

        if (! $site instanceof StoredSite) {
            $errors[] = $this->invalid(new FieldPath('site'), sprintf('No site %s exists.', $command->site->toString()));
        } elseif (! $site->holds($node->path)) {
            $errors[] = $this->invalid(new FieldPath('node'), sprintf('The node %s is not below the root of the site %s.', $command->node->toString(), $command->site->toString()));
        }

        if ($aggregates->entryVersion === null) {
            $errors[] = $this->invalid(new FieldPath('entry'), sprintf('No entry %s exists that the actor can read.', $command->entry->toString()));
        }

        if ($command->slugs === []) {
            $errors[] = $this->invalid(new FieldPath(self::SLUGS), 'A placement has a slug in at least one locale.');
        }

        $seen = [];
        $taken = [];

        foreach ($command->slugs as $index => $slug) {
            $locale = $slug->locale;

            if (isset($seen[$locale->value])) {
                $errors[] = $this->invalid(new FieldPath(self::SLUGS, $index, 'locale'), sprintf('The locale %s is given more than once.', $locale->value));
            }

            $seen[$locale->value] = true;

            if ($site instanceof StoredSite && ! $site->publishes($locale)) {
                $errors[] = $this->invalid(new FieldPath(self::SLUGS, $index, 'locale'), sprintf('The site %s does not publish in %s.', $command->site->toString(), $locale->value));
            }

            if (($aggregates->slugs[$index] ?? null)?->taken === true) {
                $taken[] = new CatalogError(ErrorCode::PlacementSlugTaken, new FieldPath(self::SLUGS, $index, 'slug'), sprintf(
                    'Another placement below the node %s has the slug in %s.',
                    $command->node->toString(),
                    $locale->value,
                ));
            }
        }

        return [...$errors, ...$taken];
    }

    /**
     * @param  CreatePlacement  $command
     * @param  CreatePlacementAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return $this->planner->create($command, $aggregates);
    }

    private function invalid(FieldPath $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, $path, $message);
    }
}
