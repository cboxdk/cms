<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PointName;

/**
 * A point a panel page renders (PRD 13.4), by name, with its props as the newest version of the
 * point declares them: an object of that version's props class, which the point's generated codec
 * writes for each contribution. The contributions to an older version of the point get the props
 * its declared downcast builds from these.
 *
 * A point whose props exist only in the browser, such as the input of one field of the generic
 * command form or the receipt a run of it shows, is rendered with no props (heldByPage()): the
 * server resolves its contributions, with their scope, permission and data, and the page builds
 * the props, typed by the generated TypeScript of the same schema, and hands them to each
 * contribution itself.
 */
#[Experimental]
final readonly class RenderedPoint
{
    /**
     * @param  object|null  $props  the newest version's props, or null for a point whose props the page holds
     */
    public function __construct(
        public PointName $name,
        public ?object $props,
    ) {}

    /**
     * The point rendered with the props the page holds in the browser.
     */
    public static function heldByPage(PointName $name): self
    {
        return new self($name, null);
    }
}
