<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * The plain text of `composer check`: a line per step while it runs, and a summary with every
 * gate and step marked pass, fail or not run.
 */
final readonly class ReportFormatter
{
    private const int STATUS_WIDTH = 9;

    public static function header(string $directory): string
    {
        return "composer check: the local profile of GUARDRAILS 10, gates 1 to 6, in {$directory}\n";
    }

    public static function gateHeading(Gate $gate): string
    {
        return "\nGate {$gate->number}  {$gate->title}\n";
    }

    public static function stepLine(StepResult $result): string
    {
        $detail = match ($result->status) {
            StepStatus::NotRun => ': '.$result->reason,
            StepStatus::Pass => sprintf('  %.1f s', $result->seconds),
            StepStatus::Fail => sprintf(
                '  %.1f s, %s',
                $result->seconds,
                $result->reason ?? ($result->exitCode === null ? 'no exit code' : "exit code {$result->exitCode}"),
            ),
        };

        return '  '.str_pad($result->status->value, self::STATUS_WIDTH).' '.$result->step.$detail."\n";
    }

    public static function failureOutput(StepResult $result): string
    {
        $rule = str_repeat('-', 72);
        $output = rtrim($result->output);

        return "{$rule}\n".($output === '' ? '(no output)' : $output)."\n{$rule}\n";
    }

    public static function summary(CheckReport $report): string
    {
        $lines = ["\nSummary"];

        foreach ($report->gates as $gate) {
            $reason = $gate->notRunReason();
            $lines[] = sprintf(
                '  Gate %-3d %s %s%s',
                $gate->number,
                str_pad($gate->status()->value, self::STATUS_WIDTH),
                $gate->title,
                $reason === null ? '' : ': '.$reason,
            );

            if ($reason !== null || count($gate->steps) === 1) {
                continue;
            }

            foreach ($gate->steps as $step) {
                $lines[] = '           '.str_pad($step->status->value, self::STATUS_WIDTH).' '.$step->step
                    .($step->reason === null ? '' : ': '.$step->reason);
            }
        }

        $failed = $report->failedGates();
        $lines[] = '';
        $lines[] = $failed === []
            ? 'composer check passed.'
            : 'composer check failed: '.(count($failed) === 1 ? 'gate ' : 'gates ').self::numbers($failed).' failed.';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<int>  $numbers
     */
    private static function numbers(array $numbers): string
    {
        $last = array_pop($numbers);

        return $numbers === [] ? (string) $last : implode(', ', $numbers).' and '.$last;
    }
}
