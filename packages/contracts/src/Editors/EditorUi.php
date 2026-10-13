<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The hints a form takes for one input (PRD 14.1, gap G2), from the field's `ui` in its blueprint:
 * instructions shown with the input and an input hint shown in it, each with its texts per
 * locale; whether the input is read only or hidden; how a select shows its choices; and the
 * summary template of a repeated group, which names the group's nested fields in braces, such as
 * `{title} ({year})`, so a collapsed item shows its values. Every member is presentation only: a
 * read-only or hidden field is still written by the commands and validated as its rule says.
 */
#[Experimental]
final readonly class EditorUi
{
    public const int MAX_SUMMARY = 200;

    /** @var list<EditorText> sorted by locale */
    public array $instructionsByLocale;

    /** @var list<EditorText> sorted by locale */
    public array $inputHintByLocale;

    /**
     * @param  list<EditorText>  $instructionsByLocale
     * @param  list<EditorText>  $inputHintByLocale
     *
     * @throws InvalidTypeEditor when a text is empty or too long
     */
    public function __construct(
        public ?string $instructions = null,
        array $instructionsByLocale = [],
        public ?string $inputHint = null,
        array $inputHintByLocale = [],
        public bool $readOnly = false,
        public bool $hidden = false,
        public ?SelectDisplay $display = null,
        public ?string $summary = null,
    ) {
        if ($instructions === '' || mb_strlen($instructions ?? '') > EditorText::MAX_TEXT) {
            throw InvalidTypeEditor::instructions('the field');
        }

        if ($inputHint === '' || mb_strlen($inputHint ?? '') > EditorField::MAX_LABEL) {
            throw InvalidTypeEditor::inputHint();
        }

        if ($summary === '' || mb_strlen($summary ?? '') > self::MAX_SUMMARY) {
            throw InvalidTypeEditor::summary();
        }

        $this->instructionsByLocale = EditorText::checked($instructionsByLocale, 'the instructions of the field');
        $this->inputHintByLocale = EditorText::checked($inputHintByLocale, 'the input hint of the field');
    }

    /**
     * Whether the form takes no hint at all: the ui of a field whose blueprint has none.
     */
    public function isEmpty(): bool
    {
        return $this->instructions === null && $this->inputHint === null && ! $this->readOnly && ! $this->hidden && ! $this->display instanceof SelectDisplay && $this->summary === null;
    }
}
