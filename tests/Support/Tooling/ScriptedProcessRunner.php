<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Tooling;

use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\ProcessRunner;
use Closure;

/**
 * A ProcessRunner for tests: a script decides each command's outcome, and every call is recorded.
 */
final class ScriptedProcessRunner implements ProcessRunner
{
    /** @var list<RecordedCommand> */
    public array $calls = [];

    /**
     * @param  Closure(list<string>, string): ProcessOutcome  $script
     */
    public function __construct(private readonly Closure $script) {}

    public static function passing(): self
    {
        return new self(static fn (array $command, string $directory): ProcessOutcome => new ProcessOutcome(0, 'ok', 0.1));
    }

    public function run(array $command, string $directory, array $environment = [], ?Closure $echo = null, bool $ownProcessGroup = false): ProcessOutcome
    {
        $this->calls[] = new RecordedCommand($command, $directory, $environment, $ownProcessGroup);
        $outcome = ($this->script)($command, $directory);

        if ($echo instanceof Closure) {
            $echo($outcome->output);
        }

        return $outcome;
    }

    /**
     * @return list<string>
     */
    public function commandLines(): array
    {
        return array_map(static fn (RecordedCommand $call): string => implode(' ', $call->command), $this->calls);
    }
}
