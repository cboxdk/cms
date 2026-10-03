<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelStories\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Literal;

/**
 * A panel point as its story shows it (PRD 13.4, section 2.7 of the panel extension architecture):
 * its declaration, its stability, the sample props made from its schema, the schema itself, and
 * the contributions cms:build compiled for it that are enabled, in render order. A point without a
 * props schema, such as an #[Internal] one, has neither sample nor schema.
 */
#[Internal]
final readonly class StoryPoint
{
    /**
     * @param  list<PanelFill>  $fills
     */
    public function __construct(
        public PanelPoint $declaration,
        public string $class,
        public string $stability,
        public ?Literal $sample,
        public ?string $schema,
        public array $fills,
    ) {}
}
