<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\DoctorOptions;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\DoctorExitCode;
use Cbox\Cms\Core\Doctor\Actions\RunDoctor;
use Cbox\Cms\Core\Doctor\Boundary\DoctorReportJson;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:doctor`: checks the installation and the runtime contract, and says for each problem what
 * is wrong and how to fix it (PRD 3.3, 4.2, 13.2, GUARDRAILS 7.1).
 *
 * --dev adds the development tools: Node, Playwright and its Chromium. --json prints only the
 * document described by packages/contracts/resources/schemas/doctor.v1.json. The exit code comes
 * from DoctorExitCode, the one place the codes are defined: 0 for ok, 78 for a blocking violation
 * of the configuration or the contract, 75 for a blocking check whose dependency is unavailable
 * right now, and 79 when only checks that affect readiness fail, so the kernel may start but is not
 * ready.
 */
#[Internal]
#[Description('Check the installation and the runtime contract, and explain how to fix what is wrong')]
#[Signature('cms:doctor
        {--dev : Also check the development tools: Node, Playwright and its Chromium}
        {--json : Print the result as JSON, described by the schema doctor.v1.json, and nothing else}')]
final class DoctorCommand extends Command
{
    public function handle(RunDoctor $doctor, LoggerInterface $log): int
    {
        $report = $doctor->run(DoctorOptions::parse($this->option('dev')));

        if ($this->option('json') === true) {
            $this->output->write(DoctorReportJson::encode($report), false, OutputInterface::OUTPUT_RAW);
        } else {
            $this->describe($report);
        }

        $failed = array_values(array_filter($report->results, static fn (CheckResult $result): bool => $result->failed()));
        $context = [
            'status' => $report->exit->status(),
            'exit_code' => $report->exit->value,
            'dev' => $report->dev,
            'failed' => array_map(static fn (CheckResult $result): string => $result->id->value.' '.($result->code ?? ''), $failed),
        ];

        if ($report->exit === DoctorExitCode::Ok) {
            $log->info('cms:doctor found nothing wrong.', $context);
        } else {
            $log->warning('cms:doctor found problems.', $context);
        }

        return $report->exit->value;
    }

    private function describe(DoctorReport $report): void
    {
        $width = max(array_map(static fn (CheckResult $result): int => strlen($result->id->value), $report->results) ?: [0]);

        foreach ($report->results as $result) {
            $label = match ($result->status) {
                CheckStatus::Pass => '<info>pass</info>',
                CheckStatus::Fail => '<error>FAIL</error>',
                CheckStatus::Skip => '<comment>skip</comment>',
            };

            $this->line(sprintf(' %s  %s  %s', $label, str_pad($result->id->value, $width), $result->explanation));

            $indent = str_repeat(' ', $width + 9);

            if ($result->cause !== null) {
                $this->line($indent.'cause  '.$result->cause);
            }

            if ($result->fix !== null) {
                $this->line($indent.'fix    '.$result->fix);
            }

            if ($result->code !== null && $result->failure !== null) {
                $this->line(sprintf(
                    '%scode   %s (%s, %s)',
                    $indent,
                    $result->code,
                    $result->failure->value,
                    $result->blocking ? 'blocks the kernel from starting' : 'affects readiness only',
                ));
            }
        }

        $this->newLine();

        $summary = sprintf('cms:doctor: %s (exit %d).', $report->exit->status(), $report->exit->value);

        match ($report->exit) {
            DoctorExitCode::Ok => $this->info($summary),
            DoctorExitCode::NotReady => $this->warn($summary.' The kernel may start, but is not ready until the checks that affect readiness pass.'),
            DoctorExitCode::Unavailable, DoctorExitCode::Violation => $this->error($summary.' The kernel may not start.'),
        };
    }
}
