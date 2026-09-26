<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * The results of every gate of one `composer check`, and the directory it checked.
 */
final readonly class CheckReport
{
    /**
     * @param  list<GateResult>  $gates
     */
    public function __construct(
        public string $directory,
        public array $gates,
    ) {
        $numbers = array_map(static fn (GateResult $gate): int => $gate->number, $gates);

        if (count(array_unique($numbers)) !== count($numbers)) {
            throw new InvalidArgumentException('A check report lists a gate twice.');
        }
    }

    public function passed(): bool
    {
        return $this->failedGates() === [];
    }

    /**
     * @return list<int>
     */
    public function failedGates(): array
    {
        $failed = array_filter($this->gates, static fn (GateResult $gate): bool => $gate->status() === StepStatus::Fail);

        return array_values(array_map(static fn (GateResult $gate): int => $gate->number, $failed));
    }

    public function gate(int $number): ?GateResult
    {
        foreach ($this->gates as $gate) {
            if ($gate->number === $number) {
                return $gate;
            }
        }

        return null;
    }
}
