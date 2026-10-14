<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Validation\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\InvalidContentValue;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\InvalidLink;
use Cbox\Cms\Contracts\Fields\Link;
use Cbox\Cms\Contracts\Fields\LinkKind;
use Cbox\Cms\Contracts\Fields\LinkRel;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\NodeId;
use stdClass;

/**
 * Reads a link field's value from decoded JSON (B2-T76), for the input validator and the record
 * codecs alike, so both refuse the same values at the same paths, as the generated TypeScript
 * runtime's checkLink() does. The checks run in this order, and the first that fails is the
 * problem:
 *
 * 1. the value is an object, validation_wrong_type;
 * 2. `kind` is there, validation_required, is text, validation_wrong_type, and one of the kinds the
 *    field allows, validation_not_an_option;
 * 3. the object has only the members of its kind, validation_unknown_field at the link;
 * 4. for an entry, `entry` is there and is an entry's id, a UUIDv7, and `locale`, when it is there,
 *    is a locale, validation_invalid_format; for a node, `node` is a node's id; for a URL, `url`
 *    is there, has at most Link::MAX_URL_LENGTH characters, validation_too_long, and is an absolute
 *    http or https URL, validation_invalid_format, and `rel`, when it is there, is a list of rels,
 *    validation_not_an_option, each once, validation_duplicate_item.
 *
 * A member that is optional is left out, never null: null is validation_wrong_type. A link is a
 * JSON object, so an empty list, which JSON decoded to arrays gives for `[]`, is not one.
 */
#[Internal]
final readonly class LinkInput
{
    /**
     * The link the value holds, or the first rule it breaks.
     *
     * @param  list<LinkKind>  $kinds  the kinds the field allows
     */
    public static function read(mixed $value, array $kinds): Link|LinkProblem
    {
        $members = match (true) {
            $value instanceof stdClass => get_object_vars($value),
            is_array($value) && $value !== [] && ! array_is_list($value) => $value,
            default => null,
        };

        if ($members === null) {
            return new LinkProblem(ErrorCode::ValidationWrongType, null, null, 'is not a link, an object of its kind and its target');
        }

        $kind = $members[Link::KIND] ?? null;

        if ($kind === null) {
            return new LinkProblem(ErrorCode::ValidationRequired, Link::KIND, null, 'is missing; a link has the kind of its target');
        }

        if (! is_string($kind)) {
            return new LinkProblem(ErrorCode::ValidationWrongType, Link::KIND, null, 'is not text');
        }

        $allowed = LinkKind::tryFrom($kind);

        if (! $allowed instanceof LinkKind || ! in_array($allowed, $kinds, true)) {
            return new LinkProblem(ErrorCode::ValidationNotAnOption, Link::KIND, null, sprintf(
                'is not one of the kinds the field allows: %s',
                implode(', ', array_map(static fn (LinkKind $one): string => $one->value, $kinds)),
            ));
        }

        $keys = self::members($allowed);

        foreach (array_keys($members) as $key) {
            if (! in_array($key, $keys, true)) {
                return new LinkProblem(ErrorCode::ValidationUnknownField, null, null, sprintf(
                    'has the key %s, which a link to %s does not have',
                    InputPaths::shown($key),
                    $allowed === LinkKind::Url ? 'a URL' : 'an '.($allowed === LinkKind::Entry ? 'entry' : 'node'),
                ));
            }
        }

        return match ($allowed) {
            LinkKind::Entry => self::entry($members),
            LinkKind::Node => self::node($members),
            LinkKind::Url => self::url($members),
        };
    }

    /**
     * The members a link of the kind has.
     *
     * @return list<string>
     */
    public static function members(LinkKind $kind): array
    {
        return match ($kind) {
            LinkKind::Entry => [Link::KIND, Link::ENTRY, Link::LOCALE],
            LinkKind::Node => [Link::KIND, Link::NODE],
            LinkKind::Url => [Link::KIND, Link::URL, Link::REL],
        };
    }

    /**
     * @param  array<array-key, mixed>  $members
     */
    private static function entry(array $members): Link|LinkProblem
    {
        $entry = self::id($members, Link::ENTRY, 'an entry\'s id');

        if ($entry instanceof LinkProblem) {
            return $entry;
        }

        if (! array_key_exists(Link::LOCALE, $members)) {
            return Link::toEntry(EntryId::fromString($entry));
        }

        $locale = $members[Link::LOCALE];

        if (! is_string($locale)) {
            return new LinkProblem(ErrorCode::ValidationWrongType, Link::LOCALE, null, 'is not text; leave it out to link to the entry in the reader\'s language');
        }

        try {
            return Link::toEntry(EntryId::fromString($entry), new Locale($locale));
        } catch (InvalidContentValue) {
            return new LinkProblem(ErrorCode::ValidationInvalidFormat, Link::LOCALE, null, 'is not a locale, a BCP 47 tag such as "da" or "en-GB"');
        }
    }

    /**
     * @param  array<array-key, mixed>  $members
     */
    private static function node(array $members): Link|LinkProblem
    {
        $node = self::id($members, Link::NODE, 'a node\'s id');

        return $node instanceof LinkProblem ? $node : Link::toNode(NodeId::fromString($node));
    }

    /**
     * @param  array<array-key, mixed>  $members
     */
    private static function url(array $members): Link|LinkProblem
    {
        $url = $members[Link::URL] ?? null;

        if ($url === null) {
            return new LinkProblem(ErrorCode::ValidationRequired, Link::URL, null, 'is missing; a link to a URL has it');
        }

        if (! is_string($url)) {
            return new LinkProblem(ErrorCode::ValidationWrongType, Link::URL, null, 'is not text');
        }

        $length = mb_strlen($url, 'UTF-8');

        if ($length > Link::MAX_URL_LENGTH) {
            return new LinkProblem(ErrorCode::ValidationTooLong, Link::URL, null, sprintf('has %d characters; it may have at most %d', $length, Link::MAX_URL_LENGTH));
        }

        if (! Link::isWebUrl($url)) {
            return new LinkProblem(ErrorCode::ValidationInvalidFormat, Link::URL, null, 'is not an absolute http or https URL');
        }

        if (! array_key_exists(Link::REL, $members)) {
            return Link::toUrl($url);
        }

        $items = InputPaths::list($members[Link::REL]);

        if ($items === null) {
            return new LinkProblem(ErrorCode::ValidationWrongType, Link::REL, null, 'is not a list of rels; leave it out for none');
        }

        $rels = [];

        foreach ($items as $index => $item) {
            if (! is_string($item)) {
                return new LinkProblem(ErrorCode::ValidationWrongType, Link::REL, $index, 'is not text');
            }

            $rel = LinkRel::tryFrom($item);

            if (! $rel instanceof LinkRel) {
                return new LinkProblem(ErrorCode::ValidationNotAnOption, Link::REL, $index, sprintf(
                    'is not one of the rels: %s',
                    implode(', ', array_map(static fn (LinkRel $one): string => $one->value, LinkRel::cases())),
                ));
            }

            if (in_array($rel, $rels, true)) {
                return new LinkProblem(ErrorCode::ValidationDuplicateItem, Link::REL, $index, sprintf('is the same rel as item %d', (int) array_search($rel, $rels, true)));
            }

            $rels[] = $rel;
        }

        try {
            return Link::toUrl($url, ...$rels);
        } catch (InvalidLink) {
            return new LinkProblem(ErrorCode::ValidationInvalidFormat, Link::URL, null, 'is not an absolute http or https URL');
        }
    }

    /**
     * The id at the member, or the problem with it.
     *
     * @param  array<array-key, mixed>  $members
     */
    private static function id(array $members, string $member, string $what): string|LinkProblem
    {
        $id = $members[$member] ?? null;

        if ($id === null) {
            return new LinkProblem(ErrorCode::ValidationRequired, $member, null, sprintf('is missing; a link to %s names it', $member === Link::ENTRY ? 'an entry' : 'a node'));
        }

        if (! is_string($id)) {
            return new LinkProblem(ErrorCode::ValidationWrongType, $member, null, sprintf('is not %s, a UUIDv7', $what));
        }

        try {
            return EntryId::fromString($id)->toString();
        } catch (InvalidUuid7) {
            return new LinkProblem(ErrorCode::ValidationWrongType, $member, null, sprintf('is not %s, a UUIDv7 such as "0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a6b"', $what));
        }
    }
}
