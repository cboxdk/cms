<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\FieldFallback;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\WhenHidden;

/**
 * A field of a type editor descriptor (PRD 11.12, 12.2, 14.1): what a form needs to render one
 * input and validate its value. The fields of a type are a flat list in form order; a nested field
 * of a group names the group's path in `parent`, so the form rebuilds the tree while the contract
 * stays without recursion.
 *
 * - `path` addresses the field: its handle for a top-level field of the owner, `ext.<namespace>.<handle>`
 *   for a top-level extension field, and the parent's path, a dot and the handle inside a group.
 *   `namespace` is null for the owner's fields, and a nested field takes its group's.
 * - `fieldType` is the field type as the blueprint names it, a core field type such as `text` or
 *   `<namespace>:<handle>` for an addon's, and `base` the core field type whose form the value
 *   takes (EditorBase); a form picks its input by the base and may refine it by the type.
 * - `presence` is what the generated validator requires: Required at every write, RequiredOnRelease
 *   for an extension field the blueprint requires (PRD 11.12, point 1), else Optional.
 * - `classification`, `agents` and `encrypted` are the field's as the TypeCatalog has them;
 *   readableBy() is the rule the queries filter by.
 * - `choices` are the options of a select, in the blueprint's order, and empty for any other base;
 *   `targets` are the types a relation's targets may have, in the blueprint's order, so a picker
 *   searches entries of those types (PRD 5.11), and empty for any other base;
 *   `repeat` is how a group repeats, and null for a group that does not or any other base.
 * - `rule` is the field's value rule in the form of the generated validation runtime, a ValueRule
 *   as the generated TypeScript module of the record declares it, so a form validates with the
 *   same interpreter; it is null for a group, whose object or list rule the form builds from the
 *   nested fields and `repeat`.
 * - `translatable` says which variant holds the field in a type with `localization: variants`
 *   (PRD 10.1): a language's variant when it is true, the shared variant when it is false, so a
 *   form shows the field once per language or once for every language; a nested field takes its
 *   group's. `fallback` is how a translatable field falls back (PRD 10.3), and Variant for every
 *   other field.
 * - `labels` and `descriptions` are the label and the description per locale the blueprint names
 *   (gap G2), beside the plain `label` and `description`, so a form shows the panel's language.
 * - `ui` holds the hints the blueprint's `ui` gives the input (EditorUi): the display of a select
 *   only for a select, and a summary only for a repeated group.
 * - `defaultValue` is the form's starting value from the blueprint's `default`, as one JSON
 *   document, or null: `{"kind":"constant","value":<the value in the record's JSON form>}` for a
 *   constant, or `{"kind":"token","token":"today"}` or `"now"` for a date or datetime the form
 *   takes from the clock. It is form only: a command never fills it in, and a group, rich text
 *   and a relation have none.
 * - `when` is the condition a conditional field is shown on (gap G3), as one JSON document in the
 *   form of the generated validation runtime's ConditionRule, such as
 *   `{"op":"eq","field":{"up":0,"key":"kind"},"value":"external"}`: `field` names a sibling in the
 *   field's object by `key`, or `up` objects above it, an item of a repeated group being one
 *   object, so a form evaluates it with the runtime's conditionHolds(). `whenHidden` is what
 *   happens to the value while the field is hidden. A field has both or neither; `presence` holds
 *   while the field is shown.
 */
#[Experimental]
final readonly class EditorField
{
    public const int MAX_LABEL = 100;

    public const int MAX_DESCRIPTION = 1000;

    private const string FIELD_TYPE = '/\A(?:[a-z][a-z0-9]{0,19}:)?[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

    /** @var list<EditorText> sorted by locale */
    public array $labels;

    /** @var list<EditorText> sorted by locale */
    public array $descriptions;

    /**
     * @param  list<EditorChoice>  $choices  the options of a select, each value once
     * @param  bool  $translatable  whether a language's variant holds the field, in a type with localization variants
     * @param  FieldFallback  $fallback  how a translatable field falls back; Variant for every other field
     * @param  list<EditorText>  $labels  the label per locale
     * @param  list<EditorText>  $descriptions  the description per locale
     * @param  EditorUi  $ui  the hints the form takes for the input
     * @param  ?JsonDocument  $defaultValue  the form's starting value, or null
     * @param  ?JsonDocument  $when  the condition the field is shown on, or null
     * @param  ?WhenHidden  $whenHidden  what happens to the value of a hidden field, or null
     * @param  list<TypeName>  $targets  the types a relation's targets may have, each once
     *
     * @throws InvalidTypeEditor when the field breaks its rules
     */
    public function __construct(
        public string $path,
        public FieldHandle $handle,
        public ?FieldNamespace $namespace,
        public ?string $parent,
        public string $fieldType,
        public EditorBase $base,
        public string $label,
        public ?string $description,
        public Presence $presence,
        public ClassificationAccess $classification,
        public bool $agents,
        public bool $encrypted,
        public array $choices,
        public ?EditorRepeat $repeat,
        public ?JsonDocument $rule,
        public bool $translatable = false,
        public FieldFallback $fallback = FieldFallback::Variant,
        array $labels = [],
        array $descriptions = [],
        public EditorUi $ui = new EditorUi,
        public ?JsonDocument $defaultValue = null,
        public ?JsonDocument $when = null,
        public ?WhenHidden $whenHidden = null,
        public array $targets = [],
    ) {
        $expected = ($parent === null ? ($namespace instanceof FieldNamespace ? 'ext.'.$namespace->value.'.' : '') : $parent.'.').$handle->value;

        if ($path !== $expected) {
            throw InvalidTypeEditor::path($path, $expected);
        }

        if (preg_match(self::FIELD_TYPE, $fieldType) !== 1) {
            throw InvalidTypeEditor::fieldType($fieldType);
        }

        if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
            throw InvalidTypeEditor::label('the field '.$path);
        }

        if ($description === '' || mb_strlen($description ?? '') > self::MAX_DESCRIPTION) {
            throw InvalidTypeEditor::description('the field '.$path);
        }

        if ($agents && ClassificationAccess::Confidential->rank() < $classification->rank()) {
            throw InvalidTypeEditor::agents($path, $classification);
        }

        if (($base === EditorBase::Select) !== ($choices !== [])) {
            throw InvalidTypeEditor::choices($path);
        }

        $values = [];

        foreach ($choices as $choice) {
            if (isset($values[$choice->value->value])) {
                throw InvalidTypeEditor::duplicateChoice($path, $choice->value->value);
            }

            $values[$choice->value->value] = true;
        }

        if (($base === EditorBase::Relation) !== ($targets !== [])) {
            throw InvalidTypeEditor::targets($path);
        }

        $names = [];

        foreach ($targets as $target) {
            if (isset($names[$target->value])) {
                throw InvalidTypeEditor::targets($path);
            }

            $names[$target->value] = true;
        }

        if ($repeat instanceof EditorRepeat && $base !== EditorBase::Group) {
            throw InvalidTypeEditor::repeat($path);
        }

        if (($base === EditorBase::Group) === ($rule instanceof JsonDocument)) {
            throw InvalidTypeEditor::rule($path);
        }

        if ($fallback !== FieldFallback::Variant && ! $translatable) {
            throw InvalidTypeEditor::fallback($path);
        }

        if ($ui->display instanceof SelectDisplay && $base !== EditorBase::Select) {
            throw InvalidTypeEditor::display($path);
        }

        if ($ui->summary !== null && ! $repeat instanceof EditorRepeat) {
            throw InvalidTypeEditor::summary($path);
        }

        if ($defaultValue instanceof JsonDocument && in_array($base, [EditorBase::Group, EditorBase::RichText, EditorBase::Relation], true)) {
            throw InvalidTypeEditor::defaultValue($path);
        }

        if (($when instanceof JsonDocument) !== ($whenHidden instanceof WhenHidden)) {
            throw InvalidTypeEditor::when($path);
        }

        $this->labels = EditorText::checked($labels, 'the label of the field '.$path);
        $this->descriptions = EditorText::checked($descriptions, 'the description of the field '.$path);
    }

    /**
     * Whether a reader with the classification access may see the field (PRD 6.2, 12.2): its
     * classification is at most the access, and for an agent its blueprint opens it to agents
     * (PRD 2.31). The same rule as the TypeCatalog's FieldDefinition::readableBy().
     *
     * @param  bool  $agent  whether the reader's credential was issued for an agent
     */
    public function readableBy(ClassificationAccess $access, bool $agent): bool
    {
        return $access->allows($this->classification) && (! $agent || $this->agents);
    }

    /**
     * Whether the field is a group, whose nested fields name it as their parent.
     */
    public function isGroup(): bool
    {
        return $this->base === EditorBase::Group;
    }
}
