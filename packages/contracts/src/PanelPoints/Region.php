<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where on its page a slot sits, which decides what its fills give the host. A toolbar takes
 * toolbar items, columns take column descriptors and tabs take tab descriptors, so tables, tabs
 * and toolbars stay accessible and alike; only sections and an aside take free markup.
 */
#[Experimental]
enum Region: string
{
    case Toolbar = 'toolbar';
    case Columns = 'columns';
    case Tabs = 'tabs';
    case Sections = 'sections';
    case Aside = 'aside';

    /**
     * Whether a fill of a slot in this region renders its own markup, rather than a descriptor the
     * host renders.
     */
    public function takesMarkup(): bool
    {
        return $this === self::Sections || $this === self::Aside;
    }
}
