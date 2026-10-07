<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveFill;

/**
 * An active fill with the point's props as its codec wrote them for it, which its data query's
 * input is taken from (ContributionProps), or null for a point whose props the page holds in the
 * browser, whose query takes no input.
 */
#[Internal]
final readonly class EncodedFill
{
    public function __construct(
        public ActiveFill $fill,
        public ?JsonDocument $props,
    ) {}
}
