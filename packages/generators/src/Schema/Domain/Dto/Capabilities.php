<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\History;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Stages;

/**
 * The capabilities of a type (PRD 3.1, 5). `routable` defaults to false.
 */
#[Internal]
final readonly class Capabilities
{
    public const bool DEFAULT_ROUTABLE = false;

    public function __construct(
        public History $history,
        public Stages $stages,
        public Localization $localization,
        public bool $routable,
    ) {}
}
