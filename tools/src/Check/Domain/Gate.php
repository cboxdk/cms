<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * A numbered gate of GUARDRAILS 10 and the steps that make it up.
 */
final readonly class Gate
{
    /**
     * @param  list<Step>  $steps
     */
    public function __construct(
        public int $number,
        public string $title,
        public array $steps,
    ) {
        if ($number < 1) {
            throw new InvalidArgumentException("A gate number starts at 1, got {$number}.");
        }

        if ($steps === []) {
            throw new InvalidArgumentException("Gate {$number} has no steps.");
        }

        $names = array_map(static fn (Step $step): string => $step->name, $steps);

        if (count(array_unique($names)) !== count($names)) {
            throw new InvalidArgumentException("Gate {$number} names a step twice.");
        }
    }
}
