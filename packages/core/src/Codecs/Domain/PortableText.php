<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Codecs\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Results\FieldPath;

/**
 * The rules `portable_text`, `styles`, `marks`, `lists` and `links` of a rich text field (PRD 11.10,
 * 11.12), checked on a document read from JSON.
 *
 * A document is a list of blocks. A block is an object with `_type` "block", a `_key` unique in
 * the document, `children` (at least one span), and optionally `style`, `listItem`, `level` (1 or
 * more, only with `listItem`) and `markDefs`. A span has `_type` "span", a `_key` unique in its
 * block, `text` and optionally `marks`, each a decorator the field allows or the `_key` of one of
 * its block's mark definitions. A mark definition has a `_key` unique in its block and a `_type`:
 * "link" with an absolute http or https `href` for the link kind `url`. Other keys are kept and not
 * checked, as Portable Text lets a renderer ignore what it does not know.
 */
#[Internal]
final readonly class PortableText
{
    /**
     * The `_type` of a mark definition for each link kind of the blueprint schema v1.
     *
     * @var array<string, string>
     */
    public const array LINK_TYPES = ['url' => 'link'];

    /**
     * @param  list<string>  $styles  the block styles the field allows
     * @param  list<string>  $marks  the decorator marks the field allows
     * @param  list<string>  $lists  the list kinds the field allows
     * @param  list<string>  $links  the link kinds the field allows
     *
     * @throws DecodingFailed with json_invalid, naming the first value that breaks a rule
     */
    public static function check(ListValue $document, FieldPath $path, array $styles, array $marks, array $lists, array $links): void
    {
        $blockKeys = [];

        foreach ($document->items as $index => $item) {
            $at = $path->then($index);
            $block = self::object($item, $at);

            if (self::text($block, '_type', $at) !== 'block') {
                throw DecodingFailed::invalid($at->then('_type'), 'is not "block": a rich text field of the blueprint schema v1 holds only blocks');
            }

            self::uniqueKey($block, $at, $blockKeys, 'another block of the document');
            self::oneOf(self::optionalText($block, 'style', $at), $styles, $at->then('style'), 'style');
            self::listItem($block, $at, $lists);
            $definitions = self::markDefinitions($block, $at, $links);
            self::children($block, $at, $marks, $definitions);
        }
    }

    /**
     * @param  list<string>  $lists
     */
    private static function listItem(MapValue $block, FieldPath $at, array $lists): void
    {
        $listItem = self::optionalText($block, 'listItem', $at);
        self::oneOf($listItem, $lists, $at->then('listItem'), 'list kind');
        $level = $block->get('level');

        if (! $level instanceof FieldValue) {
            return;
        }

        if ($listItem === null) {
            throw DecodingFailed::invalid($at->then('level'), 'is given without a listItem');
        }

        if (! $level instanceof IntegerValue || $level->value < 1) {
            throw DecodingFailed::invalid($at->then('level'), 'is not an integer of 1 or more');
        }
    }

    /**
     * The keys of the block's mark definitions.
     *
     * @param  list<string>  $links
     * @return list<string>
     */
    private static function markDefinitions(MapValue $block, FieldPath $at, array $links): array
    {
        $definitions = $block->get('markDefs');

        if (! $definitions instanceof FieldValue) {
            return [];
        }

        $keys = [];
        $allowed = array_values(array_intersect_key(self::LINK_TYPES, array_flip($links)));

        foreach (self::list($definitions, $at->then('markDefs'))->items as $index => $item) {
            $definitionAt = $at->then('markDefs', $index);
            $definition = self::object($item, $definitionAt);
            self::uniqueKey($definition, $definitionAt, $keys, 'another mark definition of the block');
            $type = self::text($definition, '_type', $definitionAt);

            if (! in_array($type, $allowed, true)) {
                throw DecodingFailed::invalid($definitionAt->then('_type'), sprintf('is the mark definition "%s", which the field does not allow (%s)', $type, self::shown($allowed)));
            }

            $href = self::text($definition, 'href', $definitionAt);

            if (! TextFormats::matches('url', $href)) {
                throw DecodingFailed::invalid($definitionAt->then('href'), 'is not an absolute http or https URL');
            }
        }

        return $keys;
    }

    /**
     * @param  list<string>  $marks
     * @param  list<string>  $definitions
     */
    private static function children(MapValue $block, FieldPath $at, array $marks, array $definitions): void
    {
        $children = $block->get('children');

        if (! $children instanceof FieldValue) {
            throw DecodingFailed::invalid($at->then('children'), 'is missing');
        }

        $spans = self::list($children, $at->then('children'))->items;

        if ($spans === []) {
            throw DecodingFailed::invalid($at->then('children'), 'is empty: a block has at least one span');
        }

        $keys = [];

        foreach ($spans as $index => $item) {
            $spanAt = $at->then('children', $index);
            $span = self::object($item, $spanAt);

            if (self::text($span, '_type', $spanAt) !== 'span') {
                throw DecodingFailed::invalid($spanAt->then('_type'), 'is not "span"');
            }

            self::uniqueKey($span, $spanAt, $keys, 'another span of the block');
            self::text($span, 'text', $spanAt);
            $spanMarks = $span->get('marks');

            if (! $spanMarks instanceof FieldValue) {
                continue;
            }

            foreach (self::list($spanMarks, $spanAt->then('marks'))->items as $markIndex => $mark) {
                if (! $mark instanceof TextValue || (! in_array($mark->value, $marks, true) && ! in_array($mark->value, $definitions, true))) {
                    throw DecodingFailed::invalid($spanAt->then('marks', $markIndex), sprintf('is neither a decorator the field allows (%s) nor the key of a mark definition of the block', self::shown($marks)));
                }
            }
        }
    }

    /**
     * Records the object's `_key`, which must be text that no earlier object in $keys has.
     *
     * @param  list<string>  $keys
     */
    private static function uniqueKey(MapValue $object, FieldPath $at, array &$keys, string $scope): void
    {
        $key = self::text($object, '_key', $at);

        if ($key === '') {
            throw DecodingFailed::invalid($at->then('_key'), 'is empty');
        }

        if (in_array($key, $keys, true)) {
            throw DecodingFailed::invalid($at->then('_key'), sprintf('"%s" is the key of %s', $key, $scope));
        }

        $keys[] = $key;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(?string $value, array $allowed, FieldPath $at, string $what): void
    {
        if ($value !== null && ! in_array($value, $allowed, true)) {
            throw DecodingFailed::invalid($at, sprintf('is the %s "%s", which the field does not allow (%s)', $what, $value, self::shown($allowed)));
        }
    }

    private static function object(FieldValue $value, FieldPath $at): MapValue
    {
        if (! $value instanceof MapValue) {
            throw DecodingFailed::invalid($at, 'is not an object');
        }

        return $value;
    }

    private static function list(FieldValue $value, FieldPath $at): ListValue
    {
        if (! $value instanceof ListValue) {
            throw DecodingFailed::invalid($at, 'is not a list');
        }

        return $value;
    }

    private static function text(MapValue $object, string $key, FieldPath $at): string
    {
        $value = self::optionalText($object, $key, $at);

        if ($value === null) {
            throw DecodingFailed::invalid($at->then($key), 'is missing');
        }

        return $value;
    }

    private static function optionalText(MapValue $object, string $key, FieldPath $at): ?string
    {
        $value = $object->get($key);

        if (! $value instanceof FieldValue) {
            return null;
        }

        if (! $value instanceof TextValue) {
            throw DecodingFailed::invalid($at->then($key), 'is not a string');
        }

        return $value->value;
    }

    /**
     * @param  list<string>  $values
     */
    private static function shown(array $values): string
    {
        return $values === [] ? 'none' : implode(', ', $values);
    }
}
