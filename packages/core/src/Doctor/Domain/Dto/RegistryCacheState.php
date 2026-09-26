<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use DateTimeImmutable;

/**
 * The registry cache that cms:build writes, next to Composer's record of what is installed
 * (PRD 13.2).
 */
#[Internal]
final readonly class RegistryCacheState
{
    /**
     * @param  string  $location  the cache directory
     * @param  list<string>  $missingFiles  the registry files that do not exist
     * @param  DateTimeImmutable|null  $builtAt  when the oldest registry file was written; null when one is missing
     * @param  string|null  $damage  why the files could not be read, or null when they could
     * @param  string  $manifest  the path of vendor/composer/installed.json
     * @param  DateTimeImmutable|null  $manifestChangedAt  when Composer last wrote it; null when it does not exist
     */
    public function __construct(
        public string $location,
        public array $missingFiles,
        public ?DateTimeImmutable $builtAt,
        public ?string $damage,
        public string $manifest,
        public ?DateTimeImmutable $manifestChangedAt,
    ) {}
}
