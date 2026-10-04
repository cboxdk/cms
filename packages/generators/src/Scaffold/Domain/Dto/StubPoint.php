<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Literal;

/**
 * A panel point as a stub is written for it: its id, its kind, the name of its props type, the
 * SDK subpath that exports it, and sample props from its schema, or null when it has none.
 */
#[Internal]
final readonly class StubPoint
{
    public function __construct(
        public PointId $id,
        public PointKind $kind,
        public string $props,
        public bool $stable,
        public ?Literal $sample,
    ) {}
}
