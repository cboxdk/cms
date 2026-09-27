<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Selftest\Adapter;

use Cbox\Cms\Tooling\Check\Boundary\CheckReportJson;
use Cbox\Cms\Tooling\Check\Domain\CheckReport;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\ProcessRunner;
use Cbox\Cms\Tooling\Check\Domain\ReportFormatter;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Selftest\Domain\Plant;
use Cbox\Cms\Tooling\Selftest\Domain\Plants;
use Cbox\Cms\Tooling\Selftest\Domain\PlantVerdict;
use Cbox\Cms\Tooling\Selftest\Domain\SelftestFailed;
use FilesystemIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * `composer check:selftest`: proves that `composer check` catches what it must, in a checkout
 * that is not this one.
 *
 * It adds a git worktree of HEAD in the system's temporary directory, runs `composer install`
 * and `npm ci` there (vendor/ is installed, never symlinked, so the path repositories' symlinks
 * point into the worktree), and asserts that vendor/cboxdk/* resolves inside the worktree. It
 * plants the violations from Plants, runs `composer check` in the worktree with a report file,
 * and asserts that each violation made the right step fail with a path inside the worktree.
 * Finally it drops the worktree's own Postgres test database, which the Postgres suite in the
 * worktree created (cms_test_<hash of the worktree's path>), removes the worktree and its
 * temporary directory, also after an error or Ctrl-C, and asserts that git no longer lists the
 * worktree. So a run leaves no worktree and no database behind. It prints the temporary
 * directory it made before anything else runs, and touches no other directory with its prefix:
 * a selftest in another checkout may run at the same time.
 */
final readonly class GateSelftest
{
    public const string PREFIX = 'cbox-cms-selftest-';

    /**
     * The start of the line that names the temporary directory this run made. Other checkouts'
     * selftests make directories with the same prefix at the same time, so this line is how a
     * reader, or a test, tells this run's directory from theirs.
     */
    public const string TEMPORARY_DIRECTORY = 'Temporary directory: ';

    /** The script that drops a checkout's test database, relative to the repository. */
    public const string DROP_DATABASE = 'tools/bin/drop-test-database.php';

    /**
     * @param  list<string>  $composer  the command that runs Composer
     * @param  resource  $stream
     * @param  string  $php  the PHP binary that runs the repository's tool scripts
     */
    public function __construct(
        private ProcessRunner $processes,
        private array $composer,
        private mixed $stream,
        private string $php = PHP_BINARY,
    ) {}

    public function run(string $repository): int
    {
        $temporary = realpath(sys_get_temp_dir()) ?: throw new SelftestFailed('The system temporary directory does not exist.');
        $base = $temporary.'/'.self::PREFIX.bin2hex(random_bytes(4));
        $worktree = $base.'/laravel-cms';
        $passed = false;

        $this->write("composer check:selftest: plants one known violation per gate in a worktree of HEAD and checks that composer check catches each one.\n");

        if (! mkdir($base, 0o700)) {
            throw new SelftestFailed("Cannot create {$base}.");
        }

        $this->write(self::TEMPORARY_DIRECTORY.$base."\n");

        $this->stopOnSignals();

        try {
            $passed = $this->inWorktree($repository, $worktree, $base);
        } catch (SelftestFailed $failure) {
            $this->write("\nThe selftest could not finish: {$failure->getMessage()}\n");
        } finally {
            $cleaned = $this->removeWorktree($repository, $worktree, $base);
        }

        $this->write($passed && $cleaned
            ? "\nSelftest passed: every planted violation was caught by its gate, with its path inside the worktree.\n"
            : "\nSelftest failed.\n");

        return $passed && $cleaned ? 0 : 1;
    }

    private function inWorktree(string $repository, string $worktree, string $base): bool
    {
        $head = trim($this->must(['git', 'rev-parse', 'HEAD'], $repository, 'git rev-parse HEAD')->output);
        $this->must(['git', 'worktree', 'add', '--detach', $worktree, $head], $repository, 'git worktree add');
        $worktree = realpath($worktree) ?: throw new SelftestFailed('git worktree add did not create the worktree.');
        $this->write("Worktree: {$worktree} (HEAD {$head})\n");

        $this->timed('composer install', [...$this->composer, 'install', '--no-interaction', '--no-progress'], $worktree);
        $this->timed('npm ci', ['npm', 'ci', '--no-audit', '--no-fund'], $worktree, ['PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD' => '1']);
        $this->assertOwnVendor($worktree);

        foreach (Plants::all() as $plant) {
            $plant->plantIn($worktree);
        }

        $this->write(sprintf("Planted %d violations. Running composer check in the worktree; this takes a few minutes.\n\n", count(Plants::all())));
        $report = $this->check($worktree, $base.'/check-report.json');

        return $this->verdicts($report, $worktree);
    }

    /**
     * vendor/ must be the worktree's own directory, and every path package must resolve inside
     * the worktree; otherwise the gates would read this checkout's packages instead of the plants.
     */
    private function assertOwnVendor(string $worktree): void
    {
        if (is_link($worktree.'/vendor') || ! is_dir($worktree.'/vendor')) {
            throw new SelftestFailed('vendor/ in the worktree is not a directory of its own.');
        }

        $packages = glob($worktree.'/vendor/cboxdk/cms-*') ?: [];

        if ($packages === []) {
            throw new SelftestFailed('The worktree has no vendor/cboxdk/cms-* packages.');
        }

        foreach ($packages as $package) {
            $real = realpath($package) ?: throw new SelftestFailed("{$package} does not resolve.");
            $inside = str_starts_with($real, $worktree.'/');
            $this->write(sprintf("realpath vendor/cboxdk/%s = %s (%s)\n", basename($package), $real, $inside ? 'inside the worktree' : 'OUTSIDE the worktree'));

            if (! $inside) {
                throw new SelftestFailed('vendor/cboxdk/'.basename($package)." resolves to {$real}, outside the worktree.");
            }
        }
    }

    private function check(string $worktree, string $reportFile): CheckReport
    {
        $outcome = $this->processes->run(
            [...$this->composer, 'check', '--', '--report='.$reportFile, '--brief'],
            $worktree,
            CheckRunner::ENVIRONMENT,
            $this->write(...),
        );

        if ($outcome->exitCode === 0) {
            throw new SelftestFailed('composer check passed with the violations planted.');
        }

        $json = is_file($reportFile) ? file_get_contents($reportFile) : false;

        if ($json === false) {
            throw new SelftestFailed('composer check exited '.($outcome->exitCode ?? 'without an exit code').' and wrote no report.');
        }

        try {
            $report = CheckReportJson::decode($json);
        } catch (UnexpectedValueException $exception) {
            throw new SelftestFailed($exception->getMessage(), 0, $exception);
        }

        if ($report->directory !== $worktree) {
            throw new SelftestFailed("composer check ran in {$report->directory}, not in the worktree.");
        }

        return $report;
    }

    private function verdicts(CheckReport $report, string $worktree): bool
    {
        $this->write("\nPlanted violations\n");
        $caught = true;

        foreach (Plants::all() as $plant) {
            $verdict = PlantVerdict::of($plant, $report, $worktree);
            $caught = $caught && $verdict->caught();
            $this->write(sprintf(
                "  %-7s gate %d %-16s %s\n            %s\n",
                $verdict->caught() ? 'caught' : 'MISSED',
                $plant->gate,
                $plant->step,
                $plant->violation,
                $verdict->reportedPath ?? $plant->path,
            ));

            foreach ($verdict->problems as $problem) {
                $this->write("            - {$problem}\n");
            }

            if (! $verdict->caught()) {
                $this->writeStepOutput($report, $plant);
            }
        }

        return $caught;
    }

    private function writeStepOutput(CheckReport $report, Plant $plant): void
    {
        $step = $report->gate($plant->gate)?->step($plant->step);

        if ($step instanceof StepResult) {
            $this->write(ReportFormatter::failureOutput($step));
        }
    }

    /**
     * Drops the worktree's test database, removes the worktree, prunes git's record of it and
     * deletes the temporary directory, then checks that git lists the worktree no more and the
     * directory is gone.
     */
    private function removeWorktree(string $repository, string $worktree, string $base): bool
    {
        $this->ignoreSignals();
        $dropped = true;

        if (is_dir($worktree)) {
            $dropped = $this->dropDatabase($repository, $worktree);
            $this->processes->run(['git', 'worktree', 'remove', '--force', $worktree], $repository);
        }

        $this->delete($base);
        $this->processes->run(['git', 'worktree', 'prune'], $repository);

        $list = $this->processes->run(['git', 'worktree', 'list', '--porcelain'], $repository);
        $listed = [];

        foreach (explode("\n", $list->output) as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $listed[] = substr($line, strlen('worktree '));
            }
        }

        $stillListed = array_filter($listed, static fn (string $path): bool => $path === $worktree || str_starts_with($path, $base.'/')) !== [];
        $this->write("\nRemoved the worktree. git worktree list:\n".implode('', array_map(static fn (string $path): string => "  {$path}\n", $listed)));

        if (! $list->succeeded() || $stillListed) {
            $this->write("git still lists {$worktree}.\n");

            return false;
        }

        if (file_exists($base)) {
            $this->write("{$base} still exists.\n");

            return false;
        }

        $this->write("{$base} is removed.\n");

        return $dropped;
    }

    /**
     * Drops the Postgres test database that the worktree's suites created, as the owner role. It
     * runs while the worktree still exists, because the name is derived from its real path.
     */
    private function dropDatabase(string $repository, string $worktree): bool
    {
        $outcome = $this->processes->run([$this->php, $repository.'/'.self::DROP_DATABASE, $worktree], $repository);
        $this->write("\n".rtrim($outcome->output)."\n");

        if (! $outcome->succeeded()) {
            $this->write('Could not drop the test database of the worktree; exit code '.($outcome->exitCode ?? 'none').".\n");

            return false;
        }

        return true;
    }

    /**
     * Deletes the selftest's temporary directory without following symlinks. It refuses any path
     * that is not a selftest directory in the system temporary directory.
     */
    private function delete(string $base): void
    {
        $temporary = realpath(sys_get_temp_dir());

        if ($temporary === false || dirname($base) !== $temporary || ! str_starts_with(basename($base), self::PREFIX)) {
            throw new SelftestFailed("Refusing to delete {$base}: it is not a selftest directory.");
        }

        self::deleteTree($base);
    }

    private static function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry instanceof SplFileInfo) {
                self::deleteTree($entry->getPathname());
            }
        }

        rmdir($path);
    }

    /**
     * Ctrl-C or SIGTERM ends the selftest through its clean-up.
     */
    private function stopOnSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, static function (int $signal): never {
                throw new SelftestFailed("Stopped by signal {$signal}.");
            });
        }
    }

    /**
     * The clean-up runs to the end; a second Ctrl-C does not leave a half-removed worktree.
     */
    private function ignoreSignals(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_IGN);
            pcntl_signal(SIGTERM, SIG_IGN);
        }
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function timed(string $label, array $command, string $directory, array $environment = []): void
    {
        $outcome = $this->must($command, $directory, $label, $environment);
        $this->write(sprintf("%s: done in %.1f s\n", $label, $outcome->seconds));
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function must(array $command, string $directory, string $label, array $environment = []): ProcessOutcome
    {
        $outcome = $this->processes->run($command, $directory, CheckRunner::ENVIRONMENT + $environment);

        if (! $outcome->succeeded()) {
            throw new SelftestFailed("{$label} failed with exit code ".($outcome->exitCode ?? 'none').":\n".rtrim($outcome->output));
        }

        return $outcome;
    }

    private function write(string $text): void
    {
        fwrite($this->stream, $text);
        fflush($this->stream);
    }
}
