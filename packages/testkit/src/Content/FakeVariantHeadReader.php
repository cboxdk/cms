<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Content;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantHead;
use Cbox\Cms\Contracts\Content\VariantHeadReader;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Override;

/**
 * The testkit's VariantHeadReader: the heads a test gives it with with(), in memory, read back as
 * the kernel's reader on Postgres reads them, so a test of a write action that reads a variant's
 * head before it plans sees what the kernel gives. It runs the shared suite
 * VariantHeadReaderContract. A head given with null fields is one whose draft revision was
 * written under another schema version than the type's, and reads back with null fields.
 */
#[Experimental]
final readonly class FakeVariantHeadReader implements VariantHeadReader
{
    /**
     * @param  array<string, VariantHead>  $heads  by entry and variant
     */
    public function __construct(private array $heads = []) {}

    /**
     * A reader that also holds these heads, each in place of one it held of the same entry and
     * variant.
     */
    public function with(VariantHead ...$heads): self
    {
        $held = $this->heads;

        foreach ($heads as $head) {
            $held[$this->key($head->entry, $head->variant)] = $head;
        }

        return new self($held);
    }

    #[Override]
    public function head(EntryId $entry, VariantKey $variant): ?VariantHead
    {
        return $this->heads[$this->key($entry, $variant)] ?? null;
    }

    private function key(EntryId $entry, VariantKey $variant): string
    {
        return $entry->toString().'|'.$variant->value;
    }
}
