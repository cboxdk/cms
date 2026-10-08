<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * One command of a gate, a step the profile does not run, with the reason, or a step whose
 * outcome the profile decided without a command, with the reason or note that says why. A step
 * can run its command in a process group of its own, which the runner kills when the step ends,
 * with variables of its own on top of the runner's, and can have a reader for the report its
 * command prints and a precheck that passes it without the command when the steps before it
 * already decided its outcome.
 */
final readonly class Step
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function __construct(
        public string $name,
        public array $command,
        public ?string $notRunReason,
        public bool $ownProcessGroup = false,
        public ?OutputReader $reader = null,
        public array $environment = [],
        public ?StepStatus $decided = null,
        public ?string $decision = null,
        public ?StepPrecheck $precheck = null,
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
     * @param  array<string, string>  $environment  variables set for this command on top of the
     *                                              runner's and the inherited environment
     * @param  StepPrecheck|null  $precheck  asked just before the command would run; a note passes
     *                                       the step without it
     */
    public static function run(string $name, array $command, bool $ownProcessGroup = false, ?OutputReader $reader = null, array $environment = [], ?StepPrecheck $precheck = null): self
    {
        if ($command === []) {
            throw new InvalidArgumentException("Step {$name} needs a command.");
        }

        foreach (array_keys($environment) as $variable) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $variable) !== 1) {
                throw new InvalidArgumentException("Step {$name} sets the variable [{$variable}], which is not a variable name.");
            }
        }

        return new self($name, $command, null, $ownProcessGroup, $reader, $environment, precheck: $precheck);
    }

    public static function notRun(string $name, string $reason): self
    {
        if ($reason === '') {
            throw new InvalidArgumentException("Step {$name} needs a reason for not running.");
        }

        return new self($name, [], $reason);
    }

    /**
     * A step another part of the same CI run runs, such as the Postgres suite in the gates part,
     * which the shards run (ShardPlan). It is not run here, and it is not reported as not run
     * either: the verdict over the parts fails unless every part reported (ShardVerdict).
     */
    public static function elsewhere(string $name, string $where): self
    {
        if ($where === '' || str_contains($where, "\n")) {
            throw new InvalidArgumentException("Step {$name} needs a one-line reason that says which part runs it.");
        }

        return new self($name, [], null, decided: StepStatus::Elsewhere, decision: $where);
    }

    /**
     * A step that passes without a command, such as mutation on changed files when no file
     * changed. The note says why and is listed with the step.
     */
    public static function passed(string $name, string $note): self
    {
        if ($note === '' || str_contains($note, "\n")) {
            throw new InvalidArgumentException("Step {$name} needs a one-line note that says why it passed.");
        }

        return new self($name, [], null, decided: StepStatus::Pass, decision: $note);
    }

    /**
     * A step that fails without a command, because what it needs to run is missing, such as the
     * base of the change for mutation on changed files.
     */
    public static function failed(string $name, string $reason): self
    {
        if ($reason === '') {
            throw new InvalidArgumentException("Step {$name} needs a reason for failing.");
        }

        return new self($name, [], null, decided: StepStatus::Fail, decision: $reason);
    }

    /**
     * The same step with more arguments at the end of its command, such as Pest's --shard for the
     * share of a suite one shard of the plan runs (ShardPlan).
     */
    public function with(string ...$arguments): self
    {
        if (! $this->runs()) {
            throw new InvalidArgumentException("Step {$this->name} runs no command, so it takes no arguments.");
        }

        if ($arguments === []) {
            throw new InvalidArgumentException("Step {$this->name} was given no argument.");
        }

        return new self(
            $this->name,
            [...$this->command, ...array_values($arguments)],
            null,
            $this->ownProcessGroup,
            $this->reader,
            $this->environment,
            precheck: $this->precheck,
        );
    }

    /**
     * Whether the step runs a command. A step that is not run, and one decided without a
     * command, does not.
     */
    public function runs(): bool
    {
        return $this->notRunReason === null && ! $this->decided instanceof StepStatus;
    }
}
