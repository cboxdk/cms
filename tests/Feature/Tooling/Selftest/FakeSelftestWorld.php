<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Selftest;

use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Check\Boundary\CheckReportJson;
use Cbox\Cms\Tooling\Check\Domain\CheckReport;
use Cbox\Cms\Tooling\Check\Domain\GateResult;
use Cbox\Cms\Tooling\Check\Domain\GateSelection;
use Cbox\Cms\Tooling\Check\Domain\LocalProfile;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\PrProfile;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Selftest\Adapter\GateSelftest;
use Cbox\Cms\Tooling\Selftest\Domain\Plant;
use Cbox\Cms\Tooling\Selftest\Domain\Plants;

final class FakeSelftestWorld
{
    public ?string $worktree = null;

    /** Where the worktree's autoloader maps Cbox\\Cms\\Core\\: null for the worktree's packages/core/src. */
    public ?string $coreTarget = null;

    /** @var list<string> steps whose planted files `composer check` does not report */
    public array $missedSteps = [];

    public int $checkExitCode = 1;

    /** The exit code of `composer check -- --pr --gate=...`; null for checkExitCode. */
    public ?int $prCheckExitCode = null;

    public bool $stillListed = false;

    public bool $checkRanInWorktree = false;

    public int $dropExitCode = 0;

    /** @var list<string> the worktrees whose test database was dropped while they still existed */
    public array $droppedWhileExisting = [];

    /**
     * @param  list<string>  $command
     */
    public function outcome(array $command, string $directory): ProcessOutcome
    {
        return match (true) {
            $command === ['git', 'rev-parse', 'HEAD'] => new ProcessOutcome(0, "0123abc\n", 0.0),
            array_slice($command, 0, 3) === ['git', 'worktree', 'add'] => $this->addWorktree($command[4]),
            array_slice($command, 0, 2) === ['composer', 'check'] => $this->check($command, $directory),
            array_slice($command, 0, 3) === ['git', 'worktree', 'remove'] => $this->removeWorktree($command[4]),
            array_slice($command, 0, 2) === ['php', '/srv/main/'.GateSelftest::DROP_DATABASE] => $this->drop($command[2]),
            $command === ['git', 'worktree', 'list', '--porcelain'] => new ProcessOutcome(0, "worktree /srv/main\nHEAD 0123abc\n\n".($this->stillListed ? "worktree {$this->worktree}\n" : ''), 0.0),
            default => new ProcessOutcome(0, '', 0.1),
        };
    }

    private function addWorktree(string $path): ProcessOutcome
    {
        ScratchDirectory::write($path.'/workbench/app/Cms/Generated/TypeHandle.php', "<?php\n");
        ScratchDirectory::write($path.'/js/ui-kit/src/index.ts', "export { Alert } from './components/Alert';\n");
        mkdir($path.'/packages/core/src', 0o777, true);
        $target = $this->coreTarget === null ? "\$baseDir . '/packages/core/src'" : var_export($this->coreTarget, true);
        ScratchDirectory::write($path.'/vendor/composer/autoload_psr4.php', "<?php\n\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\n\nreturn [\n    'Cbox\\\\Cms\\\\Core\\\\' => [{$target}],\n    'Psr\\\\Log\\\\' => [\$vendorDir . '/psr/log/src'],\n];\n");
        $this->worktree = $path;

        return new ProcessOutcome(0, '', 0.0);
    }

    /**
     * @param  list<string>  $command
     */
    private function check(array $command, string $directory): ProcessOutcome
    {
        $this->checkRanInWorktree = $directory === realpath((string) $this->worktree);
        $options = array_slice($command, 3);
        $reportFile = '';
        $selected = [];

        foreach ($options as $option) {
            if (str_starts_with($option, '--report=')) {
                $reportFile = substr($option, strlen('--report='));
            } elseif (str_starts_with($option, '--gate=')) {
                $selected[] = (int) substr($option, strlen('--gate='));
            }
        }

        $profile = in_array('--pr', $options, true) ? PrProfile::gates('php', ['composer']) : LocalProfile::gates('php', ['composer']);
        $gates = [];

        foreach (GateSelection::only($profile, $selected) as $gate) {
            $steps = [];

            foreach ($gate->steps as $step) {
                $plants = array_filter(Plants::all(), static fn (Plant $plant): bool => $plant->gate === $gate->number && $plant->step === $step->name);
                $output = implode("\n", array_map(static fn (Plant $plant): string => $plant->path.' '.implode(' ', $plant->markers), $plants));
                $missed = in_array($step->name, $this->missedSteps, true);

                $steps[] = match (true) {
                    ! $step->runs() => StepResult::notRun($step->name, (string) $step->notRunReason),
                    $plants === [] || $missed => StepResult::ran($step->name, new ProcessOutcome(0, 'ok', 1.0)),
                    default => StepResult::ran($step->name, new ProcessOutcome(1, $output, 1.0)),
                };
            }

            $gates[] = new GateResult($gate->number, $gate->title, $steps);
        }

        file_put_contents($reportFile, CheckReportJson::encode(new CheckReport($directory, $gates)));

        $exitCode = in_array('--pr', $options, true) ? ($this->prCheckExitCode ?? $this->checkExitCode) : $this->checkExitCode;

        return new ProcessOutcome($exitCode, "Gate 1  Pint and Prettier\n", 1.0);
    }

    private function drop(string $worktree): ProcessOutcome
    {
        if (is_dir($worktree)) {
            $this->droppedWhileExisting[] = $worktree;
        }

        return $this->dropExitCode === 0
            ? new ProcessOutcome(0, "Dropped the test database cms_test_0123456789ab of {$worktree}.\n", 0.1)
            : new ProcessOutcome($this->dropExitCode, "The owner role cms_owner has no CREATEDB.\n", 0.1);
    }

    private function removeWorktree(string $path): ProcessOutcome
    {
        ScratchDirectory::delete($path);

        return new ProcessOutcome(0, '', 0.0);
    }
}
