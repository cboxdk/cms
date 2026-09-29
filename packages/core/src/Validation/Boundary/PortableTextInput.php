<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Validation\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\RuleName;

/**
 * The rules `portable_text`, `styles`, `marks`, `lists` and `links` of a rich text field (PRD
 * 11.10, 11.12), checked on input from outside.
 *
 * A document is a list of blocks. A block is an object with `_type` "block", a `_key` that no other
 * block of the document has, `children` (at least one span), and optionally `style`, `listItem`,
 * `level` (1 or more, only with `listItem`) and `markDefs`. A span has `_type` "span", a `_key` that
 * no other span of its block has, `text`, and optionally `marks`, each a decorator the field allows
 * or the `_key` of one of its block's mark definitions. A mark definition has a `_key` that no
 * other of its block has and a `_type`: "link", with an absolute http or https `href`, for the link
 * kind `url`. A field without one of the rules `styles`, `marks`, `lists` or `links` allows every
 * value of it. Other keys are not checked, as Portable Text lets a renderer ignore what it does not
 * know.
 *
 * A broken structure is validation_invalid_rich_text; a style, mark, list or link the field does
 * not allow is validation_rich_text_not_allowed.
 */
#[Internal]
final readonly class PortableTextInput
{
    /**
     * The `_type` of a mark definition for each link kind of the blueprint schema v1.
     *
     * @var array<string, string>
     */
    public const array LINK_TYPES = ['url' => 'link'];

    public static function check(FieldRules $field, mixed $value, FieldPath $path, ValidationErrors $errors): bool
    {
        $blocks = InputPaths::list($value);

        if ($blocks === null) {
            $errors->add(ErrorCode::ValidationWrongType, $path, 'is not a list of Portable Text blocks.');

            return false;
        }

        $allowed = new PortableTextRules(
            self::allowed($field, RuleName::Styles),
            self::allowed($field, RuleName::Marks),
            self::allowed($field, RuleName::Lists),
            self::allowed($field, RuleName::Links),
        );
        $keys = [];

        foreach ($blocks as $index => $item) {
            if ($errors->full()) {
                break;
            }

            self::block($item, $path->then($index), $allowed, $keys, $errors);
        }

        return true;
    }

    /**
     * The arguments of the rule, or null when the field has no such rule and allows every value.
     *
     * @return ?list<string>
     */
    private static function allowed(FieldRules $field, RuleName $rule): ?array
    {
        return $field->rule($rule)?->arguments;
    }

    /**
     * @param  array<string, true>  $keys  the keys of the document's blocks so far
     */
    private static function block(mixed $item, FieldPath $at, PortableTextRules $allowed, array &$keys, ValidationErrors $errors): void
    {
        $block = InputPaths::object($item);

        if ($block === null) {
            $errors->add(ErrorCode::ValidationInvalidRichText, $at, 'is not a block object.');

            return;
        }

        if (($block['_type'] ?? null) !== 'block') {
            $errors->add(ErrorCode::ValidationInvalidRichText, $at->then('_type'), 'is not "block": a rich text field holds only blocks.');

            return;
        }

        self::key($block, $at, $keys, 'another block of the document', $errors);
        self::oneOf($block, 'style', $at, $allowed->styles, 'style', $errors);
        self::oneOf($block, 'listItem', $at, $allowed->lists, 'list kind', $errors);

        if (array_key_exists('level', $block) && $block['level'] !== null) {
            if (($block['listItem'] ?? null) === null) {
                $errors->add(ErrorCode::ValidationInvalidRichText, $at->then('level'), 'is given without a listItem.');
            } elseif (! is_int($block['level']) || $block['level'] < 1) {
                $errors->add(ErrorCode::ValidationInvalidRichText, $at->then('level'), 'is not a whole number of 1 or more.');
            }
        }

        $definitions = self::markDefinitions($block['markDefs'] ?? null, $at->then('markDefs'), $allowed->links, $errors);
        self::children($block['children'] ?? null, $at->then('children'), $allowed->marks, $definitions, $errors);
    }

    /**
     * The keys of the block's mark definitions.
     *
     * @param  ?list<string>  $links
     * @return array<string, true>
     */
    private static function markDefinitions(mixed $value, FieldPath $at, ?array $links, ValidationErrors $errors): array
    {
        if ($value === null) {
            return [];
        }

        $definitions = InputPaths::list($value);

        if ($definitions === null) {
            $errors->add(ErrorCode::ValidationInvalidRichText, $at, 'is not a list of mark definitions.');

            return [];
        }

        $keys = [];
        $types = array_values($links === null ? self::LINK_TYPES : array_intersect_key(self::LINK_TYPES, array_flip($links)));

        foreach ($definitions as $index => $item) {
            $definitionAt = $at->then($index);
            $definition = InputPaths::object($item);

            if ($definition === null) {
                $errors->add(ErrorCode::ValidationInvalidRichText, $definitionAt, 'is not a mark definition object.');

                continue;
            }

            self::key($definition, $definitionAt, $keys, 'another mark definition of the block', $errors);
            $type = $definition['_type'] ?? null;

            if (! is_string($type)) {
                $errors->add(ErrorCode::ValidationInvalidRichText, $definitionAt->then('_type'), 'is not text.');

                continue;
            }

            if (! in_array($type, $types, true)) {
                $errors->add(ErrorCode::ValidationRichTextNotAllowed, $definitionAt->then('_type'), sprintf('is a mark definition the field does not allow; it allows %s.', self::listed($types)));

                continue;
            }

            $href = $definition['href'] ?? null;

            if (! is_string($href) || ! InputValidator::inFormat('url', $href)) {
                $errors->add(ErrorCode::ValidationInvalidFormat, $definitionAt->then('href'), 'is not an absolute http or https URL.');
            }
        }

        return $keys;
    }

    /**
     * @param  ?list<string>  $marks
     * @param  array<string, true>  $definitions
     */
    private static function children(mixed $value, FieldPath $at, ?array $marks, array $definitions, ValidationErrors $errors): void
    {
        $spans = InputPaths::list($value);

        if ($spans === null || $spans === []) {
            $errors->add(ErrorCode::ValidationInvalidRichText, $at, 'is not a list of at least one span.');

            return;
        }

        $keys = [];

        foreach ($spans as $index => $item) {
            $spanAt = $at->then($index);
            $span = InputPaths::object($item);

            if ($span === null || ($span['_type'] ?? null) !== 'span') {
                $errors->add(ErrorCode::ValidationInvalidRichText, $spanAt, 'is not a span object with the _type "span".');

                continue;
            }

            self::key($span, $spanAt, $keys, 'another span of the block', $errors);
            $text = $span['text'] ?? null;

            if (! is_string($text) || ! InputValidator::storableText($text)) {
                $errors->add(ErrorCode::ValidationInvalidRichText, $spanAt->then('text'), 'is not text in UTF-8 without the character U+0000.');
            }

            self::marks($span['marks'] ?? null, $spanAt->then('marks'), $marks, $definitions, $errors);
        }
    }

    /**
     * @param  ?list<string>  $marks
     * @param  array<string, true>  $definitions
     */
    private static function marks(mixed $value, FieldPath $at, ?array $marks, array $definitions, ValidationErrors $errors): void
    {
        if ($value === null) {
            return;
        }

        $items = InputPaths::list($value);

        if ($items === null) {
            $errors->add(ErrorCode::ValidationInvalidRichText, $at, 'is not a list of marks.');

            return;
        }

        foreach ($items as $index => $mark) {
            if (! is_string($mark)) {
                $errors->add(ErrorCode::ValidationInvalidRichText, $at->then($index), 'is not text.');
            } elseif ($marks !== null && ! in_array($mark, $marks, true) && ! isset($definitions[$mark])) {
                $errors->add(ErrorCode::ValidationRichTextNotAllowed, $at->then($index), sprintf('is neither a decorator the field allows (%s) nor the key of a mark definition of the block.', self::listed($marks)));
            }
        }
    }

    /**
     * Records the object's `_key`, which must be text that no earlier object in $keys has.
     *
     * @param  array<array-key, mixed>  $object
     * @param  array<string, true>  $keys
     */
    private static function key(array $object, FieldPath $at, array &$keys, string $scope, ValidationErrors $errors): void
    {
        $key = $object['_key'] ?? null;

        if (! is_string($key) || $key === '' || ! InputValidator::storableText($key)) {
            $errors->add(ErrorCode::ValidationInvalidRichText, $at->then('_key'), 'is not text that is not empty.');

            return;
        }

        if (isset($keys[$key])) {
            $errors->add(ErrorCode::ValidationInvalidRichText, $at->then('_key'), sprintf('is the key of %s.', $scope));

            return;
        }

        $keys[$key] = true;
    }

    /**
     * Checks an optional member that must be one of the allowed values.
     *
     * @param  array<array-key, mixed>  $object
     * @param  ?list<string>  $allowed
     */
    private static function oneOf(array $object, string $member, FieldPath $at, ?array $allowed, string $what, ValidationErrors $errors): void
    {
        $value = $object[$member] ?? null;

        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $errors->add(ErrorCode::ValidationInvalidRichText, $at->then($member), 'is not text.');
        } elseif ($allowed !== null && ! in_array($value, $allowed, true)) {
            $errors->add(ErrorCode::ValidationRichTextNotAllowed, $at->then($member), sprintf('is a %s the field does not allow; it allows %s.', $what, self::listed($allowed)));
        }
    }

    /**
     * @param  list<string>  $values
     */
    private static function listed(array $values): string
    {
        return $values === [] ? 'none' : implode(', ', $values);
    }
}
