<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\TestDatabase\Domain;

/**
 * The verdict on one test database and why, as `composer test-db:prune` prints it.
 */
final readonly class PruneDecision
{
    public function __construct(
        public string $name,
        public PruneVerdict $verdict,
        public string $reason,
    ) {}

    /**
     * One line of the report, such as `drop cms_test_0123456789ab: its checkout ... no longer exists.`
     */
    public function line(): string
    {
        return "{$this->verdict->value} {$this->name}: {$this->reason}";
    }
}
