<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A node gets its label in one language (PRD 5.8): the text a breadcrumb and the panel's tree
 * show for the node. A node has at most one label per language, so setting one replaces the label
 * the node had in that language. The label is 1 to MAX_LENGTH characters without control
 * characters that start and end with a character that is not white space, as LABEL_PATTERN says
 * and the `node_labels` table checks.
 *
 * A label is presentation, never an address: it changes no route and no URL, and the event that
 * tells about it carries the node and the language, never the text.
 */
#[Experimental]
final readonly class NodeLabelSet implements Mutation
{
    /** The longest label, in characters. */
    public const int MAX_LENGTH = 200;

    /** No control character, and a character that is not white space at each end. */
    public const string LABEL_PATTERN = '^[^\s\x00-\x1F\x7F]([^\x00-\x1F\x7F]*[^\s\x00-\x1F\x7F])?$';

    /**
     * @throws InvalidMutation for anything else than a label
     */
    public function __construct(
        public NodeId $node,
        public Locale $locale,
        public string $label,
    ) {
        if (preg_match('/'.self::LABEL_PATTERN.'/u', $label) !== 1 || mb_strlen($label, 'UTF-8') > self::MAX_LENGTH) {
            throw InvalidMutation::nodeLabel($node, $locale);
        }
    }

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->node;
    }
}
