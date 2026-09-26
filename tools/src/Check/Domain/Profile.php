<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

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
     * @param  list<string>  $phpunitSuites
     * @return list<Gate>
     */
    public function gates(string $php, array $composer, array $phpunitSuites): array
    {
        return match ($this) {
            self::Local => LocalProfile::gates($php, $composer, $phpunitSuites),
            self::Pr => PrProfile::gates($php, $composer, $phpunitSuites),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Local => 'the local profile of GUARDRAILS 10, gates 1 to 6',
            self::Pr => 'the PR profile of GUARDRAILS 10 as CI runs it today, gates 1 to 6, with 7 to 11 reported as not run',
        };
    }
}
