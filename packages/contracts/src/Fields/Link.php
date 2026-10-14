<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Fields;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\InvalidContentValue;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * The value of a link field (B2-T76): its target, of one LinkKind.
 *
 * - An entry, by its id, and optionally the locale of the variant the link points at; without a
 *   locale it points at the entry in the reader's language.
 * - A node of the structure, by its id.
 * - An absolute http or https URL of at most MAX_URL_LENGTH characters, with the rels it declares,
 *   each once, in the order given.
 *
 * In the kernel's generic field values a link is a MapValue (toFieldValue()), the form its JSON
 * input has: `kind`, and for an entry `entry` and an optional `locale`, for a node `node`, and
 * for a URL `url` and an optional `rel`, a list of rels. fromFieldValue() reads it back, and also
 * from a GroupValue of the same members.
 */
#[Experimental]
final readonly class Link
{
    /** The most characters of a link's URL. */
    public const int MAX_URL_LENGTH = 2048;

    public const string KIND = 'kind';

    public const string ENTRY = 'entry';

    public const string LOCALE = 'locale';

    public const string NODE = 'node';

    public const string URL = 'url';

    public const string REL = 'rel';

    /**
     * @param  list<LinkRel>  $rel
     */
    private function __construct(
        public LinkKind $kind,
        public ?EntryId $entry,
        public ?Locale $locale,
        public ?NodeId $node,
        public ?string $url,
        public array $rel,
    ) {}

    /**
     * A link to an entry, in the locale given or, without one, in the reader's language.
     */
    public static function toEntry(EntryId $entry, ?Locale $locale = null): self
    {
        return new self(LinkKind::Entry, $entry, $locale, null, null, []);
    }

    public static function toNode(NodeId $node): self
    {
        return new self(LinkKind::Node, null, null, $node, null, []);
    }

    /**
     * A link to a URL with the rels it declares.
     *
     * @throws InvalidLink when the URL is not an absolute http or https URL of at most MAX_URL_LENGTH characters, or a rel is given twice
     */
    public static function toUrl(string $url, LinkRel ...$rel): self
    {
        if (! self::isWebUrl($url) || strlen($url) > self::MAX_URL_LENGTH) {
            throw InvalidLink::url();
        }

        $seen = [];

        foreach ($rel as $one) {
            if (isset($seen[$one->value])) {
                throw InvalidLink::rel($one);
            }

            $seen[$one->value] = true;
        }

        return new self(LinkKind::Url, null, null, null, $url, array_values($rel));
    }

    /**
     * Whether the text is an absolute http or https URL, the rule of the text format `url` and of
     * a link's URL. A link's URL is also at most MAX_URL_LENGTH characters.
     */
    public static function isWebUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('/\Ahttps?\z/i', (string) parse_url($value, PHP_URL_SCHEME)) === 1;
    }

    /**
     * The link in the generic field values: a MapValue of its members.
     */
    public function toFieldValue(): MapValue
    {
        $entries = [new MapEntry(self::KIND, new TextValue($this->kind->value))];

        if ($this->entry instanceof EntryId) {
            $entries[] = new MapEntry(self::ENTRY, new TextValue($this->entry->toString()));
        }

        if ($this->locale instanceof Locale) {
            $entries[] = new MapEntry(self::LOCALE, new TextValue($this->locale->value));
        }

        if ($this->node instanceof NodeId) {
            $entries[] = new MapEntry(self::NODE, new TextValue($this->node->toString()));
        }

        if ($this->url !== null) {
            $entries[] = new MapEntry(self::URL, new TextValue($this->url));
        }

        if ($this->rel !== []) {
            $entries[] = new MapEntry(self::REL, new ListValue(...array_map(static fn (LinkRel $rel): TextValue => new TextValue($rel->value), $this->rel)));
        }

        return new MapValue(...$entries);
    }

    /**
     * The link a field value holds, a MapValue or a GroupValue of the link's members.
     *
     * @throws InvalidLink when the value is not a link
     */
    public static function fromFieldValue(FieldValue $value): self
    {
        $members = self::members($value);
        $kind = LinkKind::tryFrom(self::text($members, self::KIND) ?? '') ?? throw InvalidLink::value('it has no kind entry, node or url');
        $allowed = match ($kind) {
            LinkKind::Entry => [self::KIND, self::ENTRY, self::LOCALE],
            LinkKind::Node => [self::KIND, self::NODE],
            LinkKind::Url => [self::KIND, self::URL, self::REL],
        };

        foreach (array_keys($members) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw InvalidLink::value(sprintf('a link of the kind %s has no member "%s"', $kind->value, $key));
            }
        }

        try {
            return match ($kind) {
                LinkKind::Entry => self::toEntry(
                    EntryId::fromString(self::text($members, self::ENTRY) ?? throw InvalidLink::value('a link to an entry names it')),
                    ($locale = self::text($members, self::LOCALE)) === null ? null : new Locale($locale),
                ),
                LinkKind::Node => self::toNode(NodeId::fromString(self::text($members, self::NODE) ?? throw InvalidLink::value('a link to a node names it'))),
                LinkKind::Url => self::toUrl(
                    self::text($members, self::URL) ?? throw InvalidLink::value('a link to a URL has it'),
                    ...self::rels($members[self::REL] ?? null),
                ),
            };
        } catch (InvalidUuid7|InvalidContentValue $exception) {
            throw InvalidLink::value($exception->getMessage());
        }
    }

    /**
     * The id the link names in the relations: its entry or its node; null for a URL.
     */
    public function target(): EntryId|NodeId|null
    {
        return $this->entry ?? $this->node;
    }

    /**
     * @return array<string, FieldValue>
     *
     * @throws InvalidLink
     */
    private static function members(FieldValue $value): array
    {
        $members = [];

        if ($value instanceof MapValue) {
            foreach ($value->entries as $entry) {
                $members[$entry->key] = $entry->value;
            }
        } elseif ($value instanceof GroupValue) {
            foreach ($value->fields->fields as $field) {
                $members[$field->handle->value] = $field->value;
            }
        } else {
            throw InvalidLink::value('it is not a map of its members');
        }

        return array_filter($members, static fn (FieldValue $member): bool => ! $member instanceof NullValue);
    }

    /**
     * @param  array<string, FieldValue>  $members
     *
     * @throws InvalidLink
     */
    private static function text(array $members, string $key): ?string
    {
        $value = $members[$key] ?? null;

        return match (true) {
            $value === null => null,
            $value instanceof TextValue => $value->value,
            default => throw InvalidLink::value(sprintf('its %s is not text', $key)),
        };
    }

    /**
     * @return list<LinkRel>
     *
     * @throws InvalidLink
     */
    private static function rels(?FieldValue $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! $value instanceof ListValue) {
            throw InvalidLink::value('its rel is not a list');
        }

        return array_map(
            static fn (FieldValue $item): LinkRel => $item instanceof TextValue && LinkRel::tryFrom($item->value) instanceof LinkRel
                ? LinkRel::from($item->value)
                : throw InvalidLink::value('its rel holds a value that is not a rel'),
            $value->items,
        );
    }
}
