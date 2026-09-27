<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The data classification of a top-level field (PRD 12.2). Fields inside a group inherit the
 * classification of the group.
 *
 * The classification decides whether MCP tools and agents see a field whose blueprint does not say
 * (PRD 12.2, 14.5): only a public one. An internal field reaches an external model only as the
 * installation configures it, and a confidential or personal one not by default, so a blueprint
 * opts each of them in with `agents: true`. A sensitive field reaches an external model only under
 * a data processing agreement or BAA, which a blueprint cannot declare, so no blueprint lets agents
 * see it. The core enforces this, not the blueprint author (GUARDRAILS 6).
 */
#[Internal]
enum Classification: string
{
    case Public = 'public';

    case Internal = 'internal';

    case Confidential = 'confidential';

    case Personal = 'personal';

    case Sensitive = 'sensitive';

    /**
     * Whether agents see a field of this classification when its blueprint leaves out `agents`.
     */
    public function seenByAgentsByDefault(): bool
    {
        return $this === self::Public;
    }

    /**
     * Whether a blueprint may let agents see a field of this classification with `agents: true`.
     */
    public function mayBeSeenByAgents(): bool
    {
        return $this !== self::Sensitive;
    }
}
