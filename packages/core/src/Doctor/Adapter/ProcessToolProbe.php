<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\ToolProbe;
use JsonException;
use Override;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Finds node on PATH and asks it, from the project directory, which Playwright is installed and
 * where that Playwright expects its Chromium. Used by --dev only.
 */
#[Internal]
final readonly class ProcessToolProbe implements ToolProbe
{
    /** Seconds a tool may take to answer. */
    public const int TIMEOUT = 20;

    /**
     * Prints the installed Playwright's version and Chromium path as JSON. require() in `node -e`
     * resolves from the working directory, the project.
     */
    private const string PLAYWRIGHT = <<<'JS_WRAP'
    const out = {};
    try { out.version = require('playwright/package.json').version; } catch (e) { out.error = String(e && e.message); }
    if (out.version) {
      try { out.executable = require('playwright').chromium.executablePath(); } catch (e) { out.error = String(e && e.message); }
    }
    process.stdout.write(JSON.stringify(out));
    JS_WRAP;

    public function __construct(private string $projectPath) {}

    #[Override]
    public function nodeVersion(): string
    {
        $output = trim($this->run([$this->node(), '--version'], 'node --version'));

        if (preg_match('/\Av?(\d+\.\d+\.\d+)/', $output, $match) !== 1) {
            throw ProbeFailed::violation(sprintf('node --version printed "%s", which is not a version.', $output));
        }

        return $match[1];
    }

    #[Override]
    public function playwrightVersion(): string
    {
        $answer = $this->playwright();

        if (! is_string($answer['version'] ?? null)) {
            throw ProbeFailed::violation(sprintf(
                'The playwright package cannot be loaded from %s: %s',
                $this->projectPath,
                is_string($answer['error'] ?? null) ? $answer['error'] : 'it is not installed.',
            ));
        }

        return $answer['version'];
    }

    #[Override]
    public function chromiumExecutable(): string
    {
        $answer = $this->playwright();
        $executable = $answer['executable'] ?? null;

        if (! is_string($executable) || $executable === '') {
            throw ProbeFailed::violation(sprintf(
                'Playwright names no Chromium executable: %s',
                is_string($answer['error'] ?? null) ? $answer['error'] : 'it gave no path.',
            ));
        }

        if (! is_file($executable)) {
            throw ProbeFailed::violation(sprintf('Playwright expects Chromium at %s, which does not exist.', $executable));
        }

        return $executable;
    }

    private function node(): string
    {
        $node = new ExecutableFinder()->find('node');

        if ($node === null) {
            throw ProbeFailed::violation(sprintf('There is no node on PATH (%s).', getenv('PATH') ?: 'PATH is empty'));
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function playwright(): array
    {
        $output = $this->run([$this->node(), '-e', self::PLAYWRIGHT], 'node -e (Playwright)');

        try {
            $decoded = json_decode($output, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw ProbeFailed::violation(sprintf('Asking node for Playwright printed "%s", which is not JSON.', trim($output)), $invalid);
        }

        if (! is_array($decoded)) {
            throw ProbeFailed::violation('Asking node for Playwright did not print a JSON object.');
        }

        $answer = [];

        foreach ($decoded as $key => $value) {
            $answer[(string) $key] = $value;
        }

        return $answer;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, string $shown): string
    {
        $process = new Process($command, is_dir($this->projectPath) ? $this->projectPath : null, null, null, self::TIMEOUT);

        try {
            $process->run();
        } catch (Throwable $thrown) {
            throw ProbeFailed::violation(sprintf('%s could not run: %s', $shown, $thrown->getMessage()), $thrown);
        }

        if (! $process->isSuccessful()) {
            throw ProbeFailed::violation(sprintf(
                '%s exited with %d: %s',
                $shown,
                $process->getExitCode() ?? -1,
                trim($process->getErrorOutput().$process->getOutput()) ?: 'no output',
            ));
        }

        return $process->getOutput();
    }
}
