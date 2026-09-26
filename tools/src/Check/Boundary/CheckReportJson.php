<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Boundary;

use Cbox\Cms\Tooling\Check\Domain\CheckReport;
use Cbox\Cms\Tooling\Check\Domain\GateResult;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;
use JsonException;
use UnexpectedValueException;

/**
 * The report file of `composer check --report=<file>`: the checked directory, and every gate
 * with its steps' status, exit code, time, reason, notes and full output. `composer check:selftest`
 * reads it to see which gate caught which planted violation.
 */
final readonly class CheckReportJson
{
    public const int FORMAT = 1;

    public static function encode(CheckReport $report): string
    {
        $gates = array_map(static fn (GateResult $gate): array => [
            'number' => $gate->number,
            'status' => $gate->status()->value,
            'steps' => array_map(static fn (StepResult $step): array => [
                'exit_code' => $step->exitCode,
                'name' => $step->step,
                'notes' => $step->notes,
                'output' => $step->output,
                'reason' => $step->reason,
                'seconds' => round($step->seconds, 3),
                'status' => $step->status->value,
            ], $gate->steps),
            'title' => $gate->title,
        ], $report->gates);

        return json_encode(
            ['directory' => $report->directory, 'format' => self::FORMAT, 'gates' => $gates, 'passed' => $report->passed()],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    public static function decode(string $json): CheckReport
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The check report is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }

        $report = self::object($data, 'report');

        if (($report['format'] ?? null) !== self::FORMAT) {
            throw new UnexpectedValueException('The check report is not in format '.self::FORMAT.'.');
        }

        $gates = [];

        foreach (self::list($report['gates'] ?? null, 'gates') as $index => $gate) {
            $gate = self::object($gate, "gates[{$index}]");
            $steps = [];

            foreach (self::list($gate['steps'] ?? null, "gates[{$index}].steps") as $stepIndex => $step) {
                $path = "gates[{$index}].steps[{$stepIndex}]";
                $step = self::object($step, $path);
                $status = StepStatus::tryFrom(self::string($step['status'] ?? null, "{$path}.status"))
                    ?? throw new UnexpectedValueException("{$path}.status is not a known status.");
                $exitCode = $step['exit_code'] ?? null;
                $reason = $step['reason'] ?? null;
                $seconds = $step['seconds'] ?? null;
                $notes = [];

                foreach (self::list($step['notes'] ?? [], "{$path}.notes") as $noteIndex => $note) {
                    $notes[] = self::string($note, "{$path}.notes[{$noteIndex}]");
                }

                $steps[] = StepResult::restore(
                    self::string($step['name'] ?? null, "{$path}.name"),
                    $status,
                    is_int($exitCode) || $exitCode === null ? $exitCode : throw new UnexpectedValueException("{$path}.exit_code is not an integer."),
                    self::string($step['output'] ?? null, "{$path}.output"),
                    is_int($seconds) || is_float($seconds) ? (float) $seconds : throw new UnexpectedValueException("{$path}.seconds is not a number."),
                    is_string($reason) || $reason === null ? $reason : throw new UnexpectedValueException("{$path}.reason is not a string."),
                    $notes,
                );
            }

            $number = $gate['number'] ?? null;
            $gates[] = new GateResult(
                is_int($number) ? $number : throw new UnexpectedValueException("gates[{$index}].number is not an integer."),
                self::string($gate['title'] ?? null, "gates[{$index}].title"),
                $steps,
            );
        }

        return new CheckReport(self::string($report['directory'] ?? null, 'directory'), $gates);
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw new UnexpectedValueException("{$path} is not a JSON object.");
        }

        $object = [];

        foreach ($value as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new UnexpectedValueException("{$path} is not a JSON array.");
        }

        return $value;
    }

    private static function string(mixed $value, string $path): string
    {
        return is_string($value) ? $value : throw new UnexpectedValueException("{$path} is not a string.");
    }
}
