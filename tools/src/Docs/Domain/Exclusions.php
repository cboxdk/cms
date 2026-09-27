<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\Valkey\RealValkey;

/**
 * What the inventory rule finds in the kernel packages that is not an extension point an
 * application or addon builds on, each with its reason. Keep the list short: a new entry is a
 * decision that something public needs no page.
 */
final readonly class Exclusions
{
    /**
     * @return list<Exclusion>
     */
    public static function all(): array
    {
        $stability = 'marks the stability of API (GUARDRAILS 2.3); it is not an extension point';
        $monorepo = "needs this monorepo's database roles and compose services, which an addon does not have yet";

        return [
            new Exclusion(ClaimResult::class, 'a closed union of Fresh, Replay, Conflict and InFlight, which IdempotencyStore::claim() returns; nothing else implements it'),
            new Exclusion(Stable::class, $stability),
            new Exclusion(Experimental::class, $stability),
            new Exclusion(Internal::class, $stability),
            new Exclusion(RealPostgres::class, $monorepo),
            new Exclusion(RealValkey::class, $monorepo),
        ];
    }
}
