<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Boundary;

use InvalidArgumentException;

/**
 * The options of `composer check`:
 *   --report=<file>  also write the report as JSON to the file
 *   --brief          leave the output of failed steps out of the console; the report keeps it
 */
final readonly class CheckOptions
{
    public const string USAGE = 'Usage: composer check [-- [--report=<file>] [--brief]]';

    private function __construct(
        public ?string $reportFile,
        public bool $brief,
    ) {}

    /**
     * @param  list<string>  $arguments  the arguments after the script name
     */
    public static function parse(array $arguments): self
    {
        $reportFile = null;
        $brief = false;

        foreach ($arguments as $argument) {
            if ($argument === '--brief') {
                $brief = true;
            } elseif (str_starts_with($argument, '--report=') && strlen($argument) > strlen('--report=')) {
                $reportFile = substr($argument, strlen('--report='));
            } else {
                throw new InvalidArgumentException("Unknown option {$argument}. ".self::USAGE);
            }
        }

        return new self($reportFile, $brief);
    }
}
