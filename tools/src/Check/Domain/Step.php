<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * One command of a gate, or a step the profile does not run, with the reason.
 */
final readonly class Step
{
    /**
     * @param  list<string>  $command
     */
    private function __construct(
        public string $name,
        public array $command,
        public ?string $notRunReason,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A step needs a name.');
        }
    }

    /**
     * @param  list<string>  $command  the program and its arguments, run in the checked directory
     */
    public static function run(string $name, array $command): self
    {
        if ($command === []) {
            throw new InvalidArgumentException("Step {$name} needs a command.");
        }

        return new self($name, $command, null);
    }

    public static function notRun(string $name, string $reason): self
    {
        if ($reason === '') {
            throw new InvalidArgumentException("Step {$name} needs a reason for not running.");
        }

        return new self($name, [], $reason);
    }

    public function runs(): bool
    {
        return $this->notRunReason === null;
    }
}
