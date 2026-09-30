<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Content;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\Identifier;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * One variant of an entry, the aggregate whose head a command moves and releases (PRD 5.4).
 */
#[Experimental]
final readonly class VariantRef implements AggregateRef, Identifier
{
    public function __construct(
        public EntryId $entry,
        public VariantKey $variant,
    ) {}

    public function equals(self $other): bool
    {
        return $this->entry->equals($other->entry) && $this->variant->equals($other->variant);
    }

    /**
     * The entry's UUID, a colon and the variant key, such as "<uuid>:shared": the id of the variant
     * as an event carries it (PRD 7.2).
     */
    #[Override]
    public function toString(): string
    {
        return $this->entry->toString().':'.$this->variant->value;
    }

    /**
     * "variant:", the entry's UUID, a colon and the variant key.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return 'variant:'.$this->entry->toString().':'.$this->variant->value;
    }
}
