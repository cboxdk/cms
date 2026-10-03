<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationTally;

/**
 * The profiles of GUARDRAILS 10 that `composer check` runs. Local is `composer check`; Pr is what
 * CI runs through `bin/ci` with `--pr`. Both run the same gate steps, so a gate's command
 * is defined once, in the local profile.
 */
enum Profile: string
{
    case Local = 'local';
    case Pr = 'pr';

    /**
     * @param  list<string>  $composer
     * @param  MutationScope|null  $mutation  what changed since the base of the change; a part of
     *                                        the PR profile that runs mutation on changed files
     *                                        needs it, the local profile has no such step
     * @param  PrPart|null  $part  the part of the PR profile to run; null for the default,
     *                             without mutation testing
     * @param  MutationTally|null  $tally  where mutation on changed files counts each class
     * @return list<Gate>
     */
    public function gates(string $php, array $composer, ?MutationScope $mutation = null, ?PrPart $part = null, ?MutationTally $tally = null): array
    {
        return match ($this) {
            self::Local => LocalProfile::gates($php, $composer),
            self::Pr => PrProfile::gates($php, $composer, $mutation, $part, $tally),
        };
    }

    /**
     * Whether the profile can run mutation on changed files, so a part of it that does
     * (PrPart::runsMutation(), with --mutation) must find its scope first.
     */
    public function mutates(): bool
    {
        return $this === self::Pr;
    }

    public function description(): string
    {
        return match ($this) {
            self::Local => 'the local profile of GUARDRAILS 10, gates 1 to 6',
            self::Pr => 'the PR profile of GUARDRAILS 10 as CI runs it today, gates 1 to 10, with 11 reported as not run',
        };
    }
}
