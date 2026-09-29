<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Validation\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What a rich text field allows: the arguments of its rules `styles`, `marks`, `lists` and `links`,
 * or null for a rule the field does not have, which allows every value.
 */
#[Internal]
final readonly class PortableTextRules
{
    /**
     * @param  ?list<string>  $styles
     * @param  ?list<string>  $marks
     * @param  ?list<string>  $lists
     * @param  ?list<string>  $links
     */
    public function __construct(
        public ?array $styles,
        public ?array $marks,
        public ?array $lists,
        public ?array $links,
    ) {}
}
