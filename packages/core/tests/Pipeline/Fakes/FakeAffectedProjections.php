<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Core\Pipeline\Domain\AffectedProjections;
use Override;

/**
 * The affected projections a test sets: the projections of each event class, compared without
 * case as PHP compares class names. AffectedProjectionsBehaviour holds it to
 * RegistryAffectedProjections.
 */
final readonly class FakeAffectedProjections implements AffectedProjections
{
    /** @var array<string, list<ProjectionName>> */
    private array $projections;

    /**
     * @param  array<string, list<ProjectionName>>  $projections  the projections by event class
     */
    public function __construct(array $projections = [])
    {
        $byClass = [];

        foreach ($projections as $class => $names) {
            $byClass[strtolower(ltrim($class, '\\'))] = $names;
        }

        $this->projections = $byClass;
    }

    #[Override]
    public function pendingFor(array $events): array
    {
        $pending = [];

        foreach ($events as $event) {
            foreach ($this->projections[strtolower($event::class)] ?? [] as $projection) {
                $pending[$projection->value] = ProjectionStatus::pending($projection);
            }
        }

        return ProjectionStatus::listOf(array_values($pending));
    }
}
