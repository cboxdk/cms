<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What RenameProbeAction::resolve() read: the entry and its shared variant, each at its version
 * or absent, the head revision, and any further reads a test adds, such as the actor.
 */
final readonly class ProbeAggregates implements Aggregates
{
    /**
     * @param  list<ReadVersion>  $extra
     */
    public function __construct(
        public EntryId $entry,
        public ?AggregateVersion $entryVersion,
        public ?AggregateVersion $variantVersion,
        public ?RevisionNumber $head,
        public array $extra = [],
    ) {}

    public function variant(): VariantRef
    {
        return new VariantRef($this->entry, VariantKey::shared());
    }

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(
            new ReadVersion($this->entry, $this->entryVersion),
            new ReadVersion($this->variant(), $this->variantVersion),
            ...$this->extra,
        );
    }

    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return AuthorizationScope::anywhere();
    }
}
