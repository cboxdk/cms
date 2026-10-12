<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Plans\Mutations\NodeLabelSet;
use InvalidArgumentException;

/**
 * The label of a node in one language (PRD 5.8): the text a breadcrumb and the panel's tree show
 * for the node. It is 1 to NodeLabelSet::MAX_LENGTH characters with no control character, and
 * starts and ends with a character that is not white space, as NodeLabelSet::LABEL_PATTERN says
 * and the JSON Schema of node.set_label states. It is text a reader sees, so no event and no error
 * message repeats it.
 */
#[Experimental]
final readonly class NodeLabel
{
    public function __construct(public string $value)
    {
        if (
            preg_match('/'.NodeLabelSet::LABEL_PATTERN.'/u', $value) !== 1
            || mb_strlen($value, 'UTF-8') > NodeLabelSet::MAX_LENGTH
        ) {
            throw new InvalidArgumentException(sprintf(
                'A node\'s label is 1 to %d characters without control characters that starts and ends with a character that is not white space.',
                NodeLabelSet::MAX_LENGTH,
            ));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
