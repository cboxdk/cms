<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Override;

/**
 * The affected projections of the compiled registry (PRD 7.6, 13.2): for each event class, the
 * projections of the subscribers cms:build registered for it, as CompiledRegistry::projectionsFor()
 * gives them, each pending.
 */
#[Internal]
final readonly class RegistryAffectedProjections implements AffectedProjections
{
    public function __construct(private CompiledRegistry $registry) {}

    #[Override]
    public function pendingFor(array $events): array
    {
        $seen = [];
        $pending = [];

        foreach ($events as $event) {
            foreach ($this->registry->projectionsFor($event::class) as $projection) {
                if (isset($seen[$projection->value])) {
                    continue;
                }

                $seen[$projection->value] = $projection;
                $pending[] = ProjectionStatus::pending($projection);
            }
        }

        return ProjectionStatus::listOf($pending);
    }
}
