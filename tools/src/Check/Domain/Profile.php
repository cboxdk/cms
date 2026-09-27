<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use InvalidArgumentException;

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
     * @param  MutationScope|null  $mutation  what changed since the base of the change; the PR
     *                                        profile needs it for mutation on changed files, the
     *                                        local profile has no such step
     * @return list<Gate>
     */
    public function gates(string $php, array $composer, ?MutationScope $mutation = null): array
    {
        return match ($this) {
            self::Local => LocalProfile::gates($php, $composer),
            self::Pr => PrProfile::gates($php, $composer, $mutation ?? throw new InvalidArgumentException('The PR profile needs the scope of mutation on changed files.')),
        };
    }

    /**
     * Whether the profile runs mutation on changed files, so its scope must be found first.
     */
    public function mutates(): bool
    {
        return $this === self::Pr;
    }

    public function description(): string
    {
        return match ($this) {
            self::Local => 'the local profile of GUARDRAILS 10, gates 1 to 6',
            self::Pr => 'the PR profile of GUARDRAILS 10 as CI runs it today, gates 1 to 6 with mutation on changed files, 8, 9 and 10, with 7 and 11 reported as not run',
        };
    }
}
