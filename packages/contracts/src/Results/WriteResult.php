<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Receipts\Receipt;

/**
 * How one call of a write ended (GUARDRAILS 2.1, PRD 6.1, 6.2): the Receipt with its outcome,
 * and what belongs to that outcome.
 *
 * - rejected: at least one catalog error, each with its field path where it has one. Nothing was
 *   committed.
 * - committed and committed_wait_timeout: no errors; the receipt carries the changeset.
 * - dry_run: no errors, and the plan the command would have committed (PRD 6.2 phase 6).
 *
 * Each surface translates it for its transport: REST, Inertia, MCP and the CLI (GUARDRAILS 2.1).
 */
#[Experimental]
final readonly class WriteResult
{
    /** @var list<CatalogError> */
    public array $errors;

    /**
     * @param  list<CatalogError>  $errors
     */
    public function __construct(
        public Receipt $receipt,
        array $errors = [],
        public ?Plan $plan = null,
    ) {
        $outcome = $receipt->outcome;

        if ($outcome === Outcome::Rejected && $errors === []) {
            throw InvalidWriteResult::rejectedWithoutErrors();
        }

        if ($outcome !== Outcome::Rejected && $errors !== []) {
            throw InvalidWriteResult::unexpectedErrors($outcome);
        }

        if ($outcome === Outcome::DryRun && ! $plan instanceof Plan) {
            throw InvalidWriteResult::dryRunWithoutPlan();
        }

        if ($outcome !== Outcome::DryRun && $plan instanceof Plan) {
            throw InvalidWriteResult::unexpectedPlan($outcome);
        }

        $this->errors = $errors;
    }

    public static function rejected(Receipt $receipt, CatalogError $error, CatalogError ...$more): self
    {
        return new self($receipt, [$error, ...array_values($more)]);
    }

    public static function committed(Receipt $receipt): self
    {
        return new self($receipt);
    }

    public static function dryRun(Receipt $receipt, Plan $plan): self
    {
        return new self($receipt, plan: $plan);
    }

    public function outcome(): Outcome
    {
        return $this->receipt->outcome;
    }
}
