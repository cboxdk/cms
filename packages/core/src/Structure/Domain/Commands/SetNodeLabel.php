<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Structure\Domain\NodeLabel;
use Override;

/**
 * Sets a node's label in one language (PRD 5.8), version 1 of node.set_label: the node, the
 * version the caller read it at, the language and the label, the text a breadcrumb and the
 * panel's tree show for the node. A node has one label per language, so the command replaces the
 * label the node had in that language. A label is presentation: it changes no route and no URL.
 *
 * The issuing actor needs node.set_label on the node, and a node its grants do not reach is
 * unauthorized. A node that does not exist, or one at another version than the caller read, is
 * version_conflict. An archived node, which is read-only structure, is validation_failed.
 */
#[CommandName('node.set_label', version: 1)]
#[Experimental]
final readonly class SetNodeLabel implements ExpectsVersions
{
    public function __construct(
        public NodeId $node,
        public AggregateVersion $version,
        public Locale $locale,
        public NodeLabel $label,
    ) {}

    /**
     * The node, at the version the caller read.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->node, $this->version));
    }
}
