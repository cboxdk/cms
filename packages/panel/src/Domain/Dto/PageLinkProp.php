<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A page of the panel a contribution may navigate to through the host, by its page id, with its
 * address on the panel's origin (contributions.v1.json, `#/$defs/page`).
 */
#[Internal]
final readonly class PageLinkProp
{
    public function __construct(
        public string $page,
        public string $url,
    ) {}
}
