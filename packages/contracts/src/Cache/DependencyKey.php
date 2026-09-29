<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cache;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\Uuid7;

/**
 * A content key a rendered output depends on (PRD 9.4): `e-{entry}` or `n-{node}`, the kind's
 * prefix, a hyphen and the id's canonical UUID in lower case. The keys name content, not sites,
 * so one key purges every site that shows the content, through mounts too, in one call.
 *
 * The same key is a fragment's dependency in the FragmentStore, a surrogate key for a CdnDriver,
 * and the key the delivery API returns for an external frontend's own cache (PRD 9.4).
 */
#[Experimental]
final readonly class DependencyKey
{
    /** The longest key: a one-letter prefix, a hyphen and a 36-character UUID. */
    public const int MAX_LENGTH = 38;

    public function __construct(
        public DependencyKind $kind,
        public Uuid7 $content,
    ) {}

    public static function entry(EntryId $entry): self
    {
        return new self(DependencyKind::Entry, $entry->value);
    }

    public static function node(NodeId $node): self
    {
        return new self(DependencyKind::Node, $node->value);
    }

    /**
     * Parses the form toString() writes. Anything else throws InvalidCacheValue.
     */
    public static function fromString(string $value): self
    {
        $parts = explode('-', $value, 2);
        $kind = DependencyKind::tryFrom($parts[0]);

        if ($kind === null || count($parts) !== 2 || strtolower($parts[1]) !== $parts[1]) {
            throw InvalidCacheValue::dependencyKey($value);
        }

        try {
            return new self($kind, new Uuid7($parts[1]));
        } catch (InvalidUuid7) {
            throw InvalidCacheValue::dependencyKey($value);
        }
    }

    public function toString(): string
    {
        return $this->kind->value.'-'.$this->content->value;
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }
}
