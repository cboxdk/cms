<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Boundary;

use Cbox\Cms\Tooling\Check\Domain\Profile;
use InvalidArgumentException;

/**
 * The options of `composer check`:
 *   --report=<file>  also write the report as JSON to the file
 *   --brief          leave the output of failed steps out of the console; the report keeps it
 *   --pr             the PR profile as CI runs it (bin/ci) instead of the local profile. Not
 *                    --profile, which is Composer's own option for timing and memory
 */
final readonly class CheckOptions
{
    public const string USAGE = 'Usage: composer check [-- [--report=<file>] [--brief] [--pr]]';

    private function __construct(
        public ?string $reportFile,
        public bool $brief,
        public Profile $profile,
    ) {}

    /**
     * @param  list<string>  $arguments  the arguments after the script name
     */
    public static function parse(array $arguments): self
    {
        $reportFile = null;
        $brief = false;
        $profile = Profile::Local;

        foreach ($arguments as $argument) {
            if ($argument === '--brief') {
                $brief = true;
            } elseif (str_starts_with($argument, '--report=') && strlen($argument) > strlen('--report=')) {
                $reportFile = substr($argument, strlen('--report='));
            } elseif ($argument === '--pr') {
                $profile = Profile::Pr;
            } else {
                throw new InvalidArgumentException("Unknown option {$argument}. ".self::USAGE);
            }
        }

        return new self($reportFile, $brief, $profile);
    }
}
