<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Validation\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\FieldPath;

/**
 * Why a value is not a link of its field (LinkInput): the code of the rule it breaks, where in the
 * link, the member such as `url` and the item of `rel`, or the link itself when both are null, and
 * a reason without the value.
 */
#[Internal]
final readonly class LinkProblem
{
    public function __construct(
        public ErrorCode $code,
        public ?string $member,
        public ?int $item,
        public string $reason,
    ) {}

    /**
     * The path of the value that breaks the rule, below the link's path.
     */
    public function at(?FieldPath $link): ?FieldPath
    {
        if ($this->member === null) {
            return $link;
        }

        $path = InputPaths::below($link, $this->member);

        return $this->item === null ? $path : $path->then($this->item);
    }
}
