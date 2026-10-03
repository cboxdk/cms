<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PointId;

/**
 * A panel point's props as the SDK types them: the point's id, the TypeScript name of its props,
 * the short name of its props class, such as AccountMeSectionsV1, and whether the point is stable,
 * so its props come from @cboxdk/cms-panel/extend, or experimental, from /experimental.
 */
#[Internal]
final readonly class PointType
{
    public function __construct(
        public PointId $point,
        public string $name,
        public bool $stable,
    ) {}
}
