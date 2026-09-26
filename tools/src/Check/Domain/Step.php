<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * One command of a gate, or a step the profile does not run, with the reason. A step can run its
 * command in a process group of its own, which the runner kills when the step ends, and can have
 * a reader for the report its command prints.
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
        public bool $ownProcessGroup = false,
        public ?OutputReader $reader = null,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A step needs a name.');
        }
    }

    /**
     * @param  list<string>  $command  the program and its arguments, run in the checked directory
     * @param  bool  $ownProcessGroup  run the command as the leader of a new process group and kill
     *                                 what is left of the group when the command ends, so no
     *                                 process it started outlives the step
     * @param  OutputReader|null  $reader  reads the command's output after it ran
     */
    public static function run(string $name, array $command, bool $ownProcessGroup = false, ?OutputReader $reader = null): self
    {
        if ($command === []) {
            throw new InvalidArgumentException("Step {$name} needs a command.");
        }

        return new self($name, $command, null, $ownProcessGroup, $reader);
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
