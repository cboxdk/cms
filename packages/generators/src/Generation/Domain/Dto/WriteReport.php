<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What writing the generated code changed on disk. Paths are relative to the generation root and
 * sorted.
 */
#[Internal]
final readonly class WriteReport
{
    /**
     * @param  list<string>  $written  files that were new or had other contents
     * @param  list<string>  $unchanged  files that already had the generated contents
     * @param  list<string>  $removed  stale files in the owned directories that were deleted
     */
    public function __construct(
        public array $written,
        public array $unchanged,
        public array $removed,
    ) {}

    public function changed(): bool
    {
        return $this->written !== [] || $this->removed !== [];
    }
}
