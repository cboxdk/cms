<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;

/**
 * A text of a type editor descriptor in one language (PRD 11.12, 14.1): a label, a description,
 * the instructions of a section or a field, or an input hint, as the blueprint gives it for the
 * locale. A descriptor carries the plain text beside a list of these, one per locale the blueprint
 * names, sorted by locale and each locale once (EditorText::checked()), so a form shows the text
 * of the panel's language and falls back to the plain one.
 */
#[Experimental]
final readonly class EditorText
{
    public const int MAX_TEXT = 1000;

    /**
     * @throws InvalidTypeEditor when the text is empty or too long
     */
    public function __construct(
        public Locale $locale,
        public string $text,
    ) {
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            throw InvalidTypeEditor::text($locale->value);
        }
    }

    /**
     * The texts sorted by locale, each locale once.
     *
     * @param  list<EditorText>  $texts
     * @return list<EditorText>
     *
     * @throws InvalidTypeEditor when a locale appears twice
     */
    public static function checked(array $texts, string $what): array
    {
        $byLocale = [];

        foreach ($texts as $text) {
            if (isset($byLocale[$text->locale->value])) {
                throw InvalidTypeEditor::duplicateLocale($what, $text->locale->value);
            }

            $byLocale[$text->locale->value] = $text;
        }

        ksort($byLocale, SORT_STRING);

        return array_values($byLocale);
    }
}
