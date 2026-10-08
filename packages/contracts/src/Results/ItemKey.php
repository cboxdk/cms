<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The key of one item of a list, which a FieldPath writes as the segment `[#<key>]` (PRD 6.1,
 * 11.10; decision D3 of the editing experience proposal). A list whose items carry a key of their
 * own, such as the blocks of a rich text document or of a blocks field, is addressed by that key
 * instead of by the item's place, so a path survives a reorder: `fields.body[#k3f9].heading` still
 * names the same block after it has moved, where `fields.body[2].heading` names another one.
 *
 * A key is 1 to 64 characters, each a letter, a digit, an underscore or a hyphen, so it holds
 * nothing the written form of a path uses and needs no escaping.
 */
#[Experimental]
final readonly class ItemKey
{
    /** The form of a key, the same form the TypeScript twin reads. */
    public const string PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidWriteResult::itemKey($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
