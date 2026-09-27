<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The data classification of a top-level field (PRD 12.2). Fields inside a group inherit the
 * classification of the group.
 *
 * These are the classifications of the blueprint schema v1 in its first edition. It has no
 * `personal` or `sensitive`: a field of either declares its purpose, legal basis, retention,
 * recipients and subject (PRD 12.4, 12.14), which the first edition cannot express, so the schema
 * refuses them. A later schema that allows them is read as needing a newer cboxdk/cms-generators,
 * so this generator never maps personal data without its processing record.
 *
 * The classification decides whether MCP tools and agents see a field whose blueprint does not say
 * (PRD 12.2, 14.5): only a public one. An internal field reaches an external model only as the
 * installation configures it, and a confidential one not by default, so a blueprint opts each of
 * them in with `agents: true`. The core enforces this, not the blueprint author (GUARDRAILS 6).
 */
#[Internal]
enum Classification: string
{
    case Public = 'public';

    case Internal = 'internal';

    case Confidential = 'confidential';

    /**
     * Whether agents see a field of this classification when its blueprint leaves out `agents`.
     */
    public function seenByAgentsByDefault(): bool
    {
        return $this === self::Public;
    }
}
