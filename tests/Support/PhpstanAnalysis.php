<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support;

use JsonException;
use RuntimeException;

/**
 * The outcome of one PHPStan run with --error-format=json: the exit code and the error
 * identifiers, which do not depend on how PHPStan formats its text output.
 */
final readonly class PhpstanAnalysis
{
    /**
     * @param  list<string>  $identifiers
     */
    public function __construct(
        public int $exitCode,
        public array $identifiers,
    ) {}

    /**
     * @throws JsonException
     */
    public static function fromJson(int $exitCode, string $json): self
    {
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $files = is_array($report) ? ($report['files'] ?? null) : null;

        if (! is_array($files)) {
            throw new RuntimeException('PHPStan did not print a JSON report with files.');
        }

        $identifiers = [];
        foreach ($files as $file) {
            $messages = is_array($file) ? ($file['messages'] ?? []) : [];

            foreach (is_array($messages) ? $messages : [] as $message) {
                if (is_array($message) && is_string($message['identifier'] ?? null)) {
                    $identifiers[] = $message['identifier'];
                }
            }
        }

        return new self($exitCode, $identifiers);
    }
}
