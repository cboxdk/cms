<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Surface;

/**
 * Where a call came from (GUARDRAILS 2.1, PRD 6.1): one of the exposed surfaces, which #[Action]
 * names, or one of the kernel's internal issuers, which call actions without a surface.
 *
 * A call from an external surface carries the idempotency key its caller sent. An internal issuer
 * has no caller to send one, so its key is derived from its unit of work (Envelope::internal()).
 */
#[Experimental]
enum IssuingSurface: string
{
    case Rest = 'rest';
    case Inertia = 'inertia';
    case Mcp = 'mcp';
    case Cli = 'cli';

    /** A queued job. */
    case Job = 'job';

    /** The scheduler, such as a planned transition that comes due. */
    case Scheduler = 'scheduler';

    /** A subscriber of the event log (PRD 7.6). */
    case Subscriber = 'subscriber';

    /** A sidecar (PRD 6.10). */
    case Sidecar = 'sidecar';

    /** A seed that loads content. */
    case Seed = 'seed';

    public static function of(Surface $surface): self
    {
        return self::from($surface->value);
    }

    /**
     * The exposed surface, or null for an internal issuer.
     */
    public function surface(): ?Surface
    {
        return Surface::tryFrom($this->value);
    }

    /**
     * Whether the call came through an exposed surface, whose caller sends the idempotency key.
     */
    public function isExternal(): bool
    {
        return $this->surface() instanceof Surface;
    }
}
