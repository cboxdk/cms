<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Validation\ConditionOperator;
use Cbox\Cms\Contracts\Validation\WhenHidden;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\DefaultToken;
use Cbox\Cms\Generators\Schema\Domain\Dto\Capabilities;
use Cbox\Cms\Generators\Schema\Domain\Dto\ConditionReference;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldCondition;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldDefault;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldUi;
use Cbox\Cms\Generators\Schema\Domain\Dto\Layout;
use Cbox\Cms\Generators\Schema\Domain\Dto\LayoutField;
use Cbox\Cms\Generators\Schema\Domain\Dto\LayoutSection;
use Cbox\Cms\Generators\Schema\Domain\Dto\LayoutTab;
use Cbox\Cms\Generators\Schema\Domain\Dto\LocalizedText;
use Cbox\Cms\Generators\Schema\Domain\Dto\ShapedOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\FieldFallback;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\History;
use Cbox\Cms\Generators\Schema\Domain\LayoutWidth;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SelectDisplay;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\Stages;
use Cbox\Cms\Generators\Schema\Domain\TypeId;
use stdClass;

/**
 * Turns a blueprint document that the blueprint schema v1 has accepted into the typed model.
 *
 * The schema is the only source of the rules for a file (blueprint decision 5), so this class
 * checks none of them again. It only has to notice what it cannot map: a key, an enum value or a
 * kind of value that the installed schema allows and this generator does not know. That happens
 * when the schema is a later addition to v1 (decision 2) than the generator of the installed
 * cboxdk/cms was written for, and each such place is reported as generate_schema_unsupported_version
 * at its JSON pointer, so nothing in a file is ever dropped without a word.
 *
 * The `type` of every field is resolved in the FieldTypeRegistry, the core's types included, and
 * the FieldType found there reads the field's options (GUARDRAILS 2.4). A `<namespace>:<handle>`
 * that no contributor registered is generate_unknown_field_type; any other name the schema allowed
 * and the registry lacks is a core field type this generator does not know.
 *
 * Whether agents see a field is read with its classification, because the core, not the blueprint
 * author, enforces the classification (PRD 12.2, GUARDRAILS 6): a field without `agents` is seen
 * when it is public or internal, and a field inside a group as the group is.
 *
 * The classifications `personal` and `sensitive` are not in the model (PRD 12.4, 12.14): a field
 * of either needs a processing record that blueprint v1 cannot declare yet. An installed schema
 * that allows them is a later release, so such a field is generate_schema_unsupported_version at
 * its `classification`, and personal data never reaches the generated code without its record.
 */
#[Internal]
final readonly class BlueprintDocumentReader
{
    public const string PACKAGE = 'cboxdk/cms';

    private const array TYPE_KEYS = ['blueprint', 'kind', 'type_id', 'handle', 'label', 'description', 'version', 'capabilities', 'fields', 'layout'];

    private const array EXTENSION_KEYS = ['blueprint', 'kind', 'extends', 'version', 'fields', 'layout'];

    private const array CAPABILITY_KEYS = ['history', 'stages', 'localization', 'routable'];

    private const array FIELD_KEYS = ['handle', 'label', 'description', 'type', 'required', 'classification', 'filterable', 'sortable', 'translatable', 'fallback', 'agents', 'ui', 'default', 'when', 'when_hidden'];

    private const array LAYOUT_KEYS = ['tabs', 'sidebar'];

    private const array TAB_KEYS = ['handle', 'label', 'sections'];

    private const array SECTION_KEYS = ['handle', 'label', 'instructions', 'collapsible', 'fields'];

    private const array PLACEMENT_KEYS = ['field', 'width'];

    private const array UI_KEYS = ['instructions', 'input_hint', 'read_only', 'hidden', 'display', 'summary'];

    public function __construct(private FieldTypeRegistry $fieldTypes) {}

    /**
     * The problem of a file whose `blueprint` marker is a later version than 1, or null when the
     * marker is not an integer above 1. Such a file is not validated: its rules are not known here.
     */
    public static function laterVersion(mixed $document, string $file): ?GenerationProblem
    {
        $version = $document instanceof stdClass && property_exists($document, 'blueprint') ? $document->blueprint : null;

        if (! is_int($version) || $version <= 1) {
            return null;
        }

        return new GenerationProblem(GenerateErrorCode::SchemaUnsupportedVersion, sprintf(
            '%s: the file is blueprint version %d, and this %s reads version 1. The file needs a newer %s.',
            new SourceLocation($file, '/blueprint')->describe(),
            $version,
            self::PACKAGE,
            self::PACKAGE,
        ));
    }

    /**
     * @param  string  $file  the file as problems name it, relative to its root's base
     *
     * @throws GenerationFailed with every place in the document this generator cannot map
     */
    public function read(stdClass $document, Owner $owner, string $file): TypeBlueprint|ExtensionBlueprint
    {
        $problems = new ReadProblems;
        $values = $this->values($document, new SourceLocation($file, ''), $owner, EnclosingGroup::unknown(), $problems);

        $blueprint = match ($values->value('kind')) {
            'type' => $this->type($values, $owner, $problems),
            'extension' => $this->extension($values, $owner, $problems),
            default => $values->unknownAt('kind'),
        };

        if ($problems->all() !== [] || $blueprint === null) {
            throw GenerationFailed::with($problems->all() === [] ? [DocumentValues::unreadableAt($values->at())] : $problems->all());
        }

        return $blueprint;
    }

    private function type(DocumentValues $document, Owner $owner, ReadProblems $problems): ?TypeBlueprint
    {
        $document->knownKeys(self::TYPE_KEYS);

        $typeId = $document->typeId('type_id');
        $handle = $document->handle('handle');
        $label = $document->text('label');
        $description = $document->optionalText('description');
        $version = $document->int('version');
        $capabilities = $this->capabilities($document);
        $fields = $this->fields($document->value('fields'), $owner, $document->at()->below('fields'), null, $problems);
        $layout = $this->layout($document);

        if (! $typeId instanceof TypeId || ! $handle instanceof Handle || ! $label instanceof LocalizedText || $version === null || ! $capabilities instanceof Capabilities || $fields === null || ($document->has('layout') && ! $layout instanceof Layout)) {
            return null;
        }

        return new TypeBlueprint($typeId, $handle, $label->text, $description?->text, $version, $capabilities, $fields, $owner, $document->at(), $label->byLocale, $description->byLocale ?? [], $layout);
    }

    private function extension(DocumentValues $document, Owner $owner, ReadProblems $problems): ?ExtensionBlueprint
    {
        $document->knownKeys(self::EXTENSION_KEYS);

        $extends = $document->typeId('extends');
        $version = $document->int('version');
        $fields = $this->fields($document->value('fields'), $owner, $document->at()->below('fields'), null, $problems);
        $layout = $this->layout($document);

        if (! $extends instanceof TypeId || $version === null || $fields === null || ($document->has('layout') && ! $layout instanceof Layout)) {
            return null;
        }

        return new ExtensionBlueprint($extends, $version, $fields, $owner, $document->at(), $layout);
    }

    private function capabilities(DocumentValues $document): ?Capabilities
    {
        $value = $document->object('capabilities');

        if (! $value instanceof DocumentValues) {
            return null;
        }

        $value->knownKeys(self::CAPABILITY_KEYS);

        $history = $value->enum(History::class, 'history');
        $stages = $value->enum(Stages::class, 'stages');
        $localization = $value->enum(Localization::class, 'localization');
        $routable = $value->bool('routable', Capabilities::DEFAULT_ROUTABLE);

        if (! $history instanceof History || ! $stages instanceof Stages || ! $localization instanceof Localization) {
            return null;
        }

        return new Capabilities($history, $stages, $localization, $routable);
    }

    /**
     * @param  ?EnclosingGroup  $group  the group the fields are in, or null for a type's or an extension's own fields, which have a classification
     * @return ?list<FieldBlueprint>
     */
    private function fields(mixed $value, Owner $owner, SourceLocation $at, ?EnclosingGroup $group, ReadProblems $problems): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $problems->add(DocumentValues::unreadableAt($at));

            return null;
        }

        $fields = [];

        foreach ($value as $index => $item) {
            $itemAt = $at->below($index);

            if (! $item instanceof stdClass) {
                $problems->add(DocumentValues::unreadableAt($itemAt));

                continue;
            }

            $field = $this->field($this->values($item, $itemAt, $owner, EnclosingGroup::unknown(), $problems), $owner, $group, $problems);

            if ($field instanceof FieldBlueprint) {
                $fields[] = $field;
            }
        }

        return count($fields) === count($value) ? $fields : null;
    }

    private function field(DocumentValues $field, Owner $owner, ?EnclosingGroup $group, ReadProblems $problems): ?FieldBlueprint
    {
        // The classification and agents come first: the default of agents follows the
        // classification, and the fields of a group inherit agents.
        $classification = null;

        if ($field->has('classification')) {
            $classification = $field->enum(Classification::class, 'classification');
        } elseif (! $group instanceof EnclosingGroup) {
            $field->unreadable('classification');
        }

        $agents = $this->agents($field, $classification, $group);
        $field = $field->withNestedFields(fn (mixed $value, SourceLocation $fieldsAt): ?array => $this->fields($value, $owner, $fieldsAt, new EnclosingGroup($agents), $problems));

        $type = $field->value('type');
        $fieldType = is_string($type) ? $this->fieldTypes->find($type) : null;
        $options = null;

        if ($fieldType instanceof FieldType) {
            $field->knownKeys([...self::FIELD_KEYS, ...$fieldType->optionKeys()]);
            $options = $fieldType->options($field);
        } elseif (is_string($type) && str_contains($type, ':')) {
            $field->problem(new GenerationProblem(GenerateErrorCode::UnknownFieldType, sprintf(
                '%s: no field type contributor registers the field type %s, so it cannot be read. The registered field types are %s.',
                $field->at()->below('type')->describe(),
                $type,
                implode(', ', $this->fieldTypes->names()),
            )));
        } else {
            $field->unknownAt('type');
        }

        $handle = $field->handle('handle');
        $label = $field->text('label');
        $description = $field->optionalText('description');
        $ui = $this->ui($field);
        $default = $this->default($field, $options);
        $required = $field->bool('required', FieldBlueprint::DEFAULT_REQUIRED);
        $filterable = $field->bool('filterable', FieldBlueprint::DEFAULT_FILTERABLE);
        $sortable = $field->bool('sortable', FieldBlueprint::DEFAULT_SORTABLE);
        $translatable = $field->bool('translatable', FieldBlueprint::DEFAULT_TRANSLATABLE);
        $fallback = $field->has('fallback') ? $field->enum(FieldFallback::class, 'fallback') : FieldBlueprint::DEFAULT_FALLBACK;
        $when = $field->has('when') ? $this->condition($field->value('when'), $field->at()->below('when'), $field) : null;
        $whenHidden = $field->optionalEnum(WhenHidden::class, 'when_hidden');

        if (! $options instanceof FieldOptions || ! $handle instanceof Handle || ! $label instanceof LocalizedText || ! $fallback instanceof FieldFallback || (! $group instanceof EnclosingGroup && ! $classification instanceof Classification) || ($field->has('ui') && ! $ui instanceof FieldUi)) {
            return null;
        }

        if (($field->has('when') && ! $when instanceof FieldCondition) || ($field->has('when_hidden') && ! $whenHidden instanceof WhenHidden)) {
            return null;
        }

        if (($when instanceof FieldCondition) !== ($whenHidden instanceof WhenHidden)) {
            $field->invalid($when instanceof FieldCondition ? 'when' : 'when_hidden', 'a conditional field declares both when and when_hidden (gap G3), so what happens to its value while it is hidden is never a default.');

            return null;
        }

        return new FieldBlueprint($handle, $label->text, $description?->text, $required, $classification, $filterable, $sortable, $agents, $options, $owner, $field->at(), $translatable, $fallback, $label->byLocale, $description->byLocale ?? [], $ui, $default, $when, $whenHidden);
    }

    /**
     * A field's `when` (gap G3), or null when it could not be read: an object of `field` and one of
     * `eq`, `neq`, `in`, `null` and `not_null`, or of `all` or `any` and a list of conditions.
     */
    private function condition(mixed $value, SourceLocation $at, DocumentValues $field): ?FieldCondition
    {
        if (! $value instanceof stdClass) {
            $field->problem(DocumentValues::unreadableAt($at));

            return null;
        }

        $members = get_object_vars($value);

        foreach ([ConditionOperator::All, ConditionOperator::Any] as $combinator) {
            if (array_key_exists($combinator->value, $members)) {
                $items = $members[$combinator->value];

                if (count($members) !== 1 || ! is_array($items) || ! array_is_list($items) || $items === []) {
                    $field->problem(DocumentValues::unreadableAt($at));

                    return null;
                }

                $conditions = [];

                foreach ($items as $index => $item) {
                    $condition = $this->condition($item, $at->below($combinator->value, $index), $field);

                    if (! $condition instanceof FieldCondition) {
                        return null;
                    }

                    $conditions[] = $condition;
                }

                return new FieldCondition($combinator, null, [], $conditions, $at);
            }
        }

        $reference = is_string($members['field'] ?? null) ? $this->reference($members['field']) : null;
        unset($members['field']);
        $operator = count($members) === 1 ? ConditionOperator::tryFrom((string) array_key_first($members)) : null;
        $argument = $operator instanceof ConditionOperator ? $members[$operator->value] : null;
        $values = match ($operator) {
            ConditionOperator::Eq, ConditionOperator::Neq => $this->conditionValue($argument) ? [$argument] : null,
            ConditionOperator::In => is_array($argument) && array_is_list($argument) && $argument !== [] && array_all($argument, $this->conditionValue(...)) ? $argument : null,
            ConditionOperator::Null, ConditionOperator::NotNull => $argument === true ? [] : null,
            default => null,
        };

        if (! $reference instanceof ConditionReference || ! $operator instanceof ConditionOperator || $values === null) {
            $field->problem(DocumentValues::unreadableAt($at));

            return null;
        }

        /** @var list<string|int|bool> $values */
        return new FieldCondition($operator, $reference, $values, [], $at);
    }

    /**
     * Whether a condition can compare with the value: text, a whole number or a boolean.
     */
    private function conditionValue(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_bool($value);
    }

    /**
     * The field a condition names, from its written form, or null when it has none of the forms.
     */
    private function reference(string $written): ?ConditionReference
    {
        try {
            return $this->parsedReference($written);
        } catch (GenerationFailed) {
            return null;
        }
    }

    /**
     * @throws GenerationFailed for a handle or a namespace that is reserved
     */
    private function parsedReference(string $written): ?ConditionReference
    {
        if (preg_match('/\Aext\.([a-z][a-z0-9]{0,19})\.([a-z][a-z0-9]*(?:_[a-z0-9]+)*)\z/', $written, $named) === 1) {
            return new ConditionReference(0, new Owner($named[1]), new Handle($named[2]));
        }

        if (preg_match('/\A((?:\.\.\/)*)([a-z][a-z0-9]*(?:_[a-z0-9]+)*)\z/', $written, $relative) !== 1 || strlen($relative[2]) > Handle::MAX_LENGTH) {
            return null;
        }

        return new ConditionReference(intdiv(strlen($relative[1]), 3), null, new Handle($relative[2]));
    }

    /**
     * The `ui` of a field (gap G2), or null when the field has none or it could not be read.
     */
    private function ui(DocumentValues $field): ?FieldUi
    {
        if (! $field->has('ui')) {
            return null;
        }

        $values = $field->object('ui');

        if (! $values instanceof DocumentValues) {
            return null;
        }

        $values->knownKeys(self::UI_KEYS);
        $instructions = $values->optionalText('instructions');
        $inputHint = $values->optionalText('input_hint');
        $readOnly = $values->bool('read_only', FieldUi::DEFAULT_READ_ONLY);
        $hidden = $values->bool('hidden', FieldUi::DEFAULT_HIDDEN);
        $display = $values->optionalEnum(SelectDisplay::class, 'display');
        $summary = $values->optionalString('summary');

        if (($values->has('instructions') && ! $instructions instanceof LocalizedText)
            || ($values->has('input_hint') && ! $inputHint instanceof LocalizedText)
            || ($values->has('display') && ! $display instanceof SelectDisplay)
            || ($values->has('summary') && $summary === null)) {
            return null;
        }

        return new FieldUi($instructions, $inputHint, $readOnly, $hidden, $display, $summary);
    }

    /**
     * The `default` of a field (gap G2), or null when the field has none or it could not be read.
     * `today` on a date field and `now` on a datetime field, an addon's field type of that base
     * included, are the tokens the form resolves; BlueprintRules holds every other default to the
     * kind the field type takes.
     */
    private function default(DocumentValues $field, ?FieldOptions $options): ?FieldDefault
    {
        $raw = $field->rawDefault('default');
        $base = $options instanceof ShapedOptions ? $options->base : $options;

        if ($raw === null) {
            return null;
        }

        if (is_array($raw)) {
            return FieldDefault::values($raw);
        }

        if ($base instanceof DateOptions && $raw === DefaultToken::Today->value) {
            return FieldDefault::token(DefaultToken::Today);
        }

        if ($base instanceof DatetimeOptions && $raw === DefaultToken::Now->value) {
            return FieldDefault::token(DefaultToken::Now);
        }

        return FieldDefault::constant($raw);
    }

    /**
     * The `layout` of a type file, or the layout fragment of an extension file (gap G2), or null
     * when the file has none or it could not be read.
     */
    private function layout(DocumentValues $document): ?Layout
    {
        if (! $document->has('layout')) {
            return null;
        }

        $values = $document->object('layout');

        if (! $values instanceof DocumentValues) {
            return null;
        }

        $values->knownKeys(self::LAYOUT_KEYS);
        $tabs = [];

        foreach ($values->has('tabs') ? ($values->objectList('tabs') ?? [null]) : [] as $item) {
            $tab = $item instanceof DocumentValues ? $this->tab($item) : null;

            if (! $tab instanceof LayoutTab) {
                return null;
            }

            $tabs[] = $tab;
        }

        $sidebar = [];

        if ($values->has('sidebar')) {
            $sidebarValues = $values->object('sidebar');

            if (! $sidebarValues instanceof DocumentValues) {
                return null;
            }

            $sidebarValues->knownKeys(['sections']);
            $sidebar = $this->sections($sidebarValues);

            if ($sidebar === null) {
                return null;
            }
        }

        return new Layout($tabs, $sidebar, $values->at());
    }

    private function tab(DocumentValues $values): ?LayoutTab
    {
        $values->knownKeys(self::TAB_KEYS);
        $handle = $values->handle('handle');
        $label = $values->text('label');
        $sections = $this->sections($values);

        if (! $handle instanceof Handle || ! $label instanceof LocalizedText || $sections === null) {
            return null;
        }

        return new LayoutTab($handle, $label, $sections, $values->at());
    }

    /**
     * @return ?list<LayoutSection>
     */
    private function sections(DocumentValues $values): ?array
    {
        $sections = [];

        foreach ($values->objectList('sections') ?? [null] as $item) {
            $section = $item instanceof DocumentValues ? $this->section($item) : null;

            if (! $section instanceof LayoutSection) {
                return null;
            }

            $sections[] = $section;
        }

        return $sections;
    }

    private function section(DocumentValues $values): ?LayoutSection
    {
        $values->knownKeys(self::SECTION_KEYS);
        $handle = $values->handle('handle');
        $label = $values->optionalText('label');
        $instructions = $values->optionalText('instructions');
        $collapsible = $values->bool('collapsible', LayoutSection::DEFAULT_COLLAPSIBLE);
        $fields = [];

        foreach ($values->objectList('fields') ?? [null] as $item) {
            if (! $item instanceof DocumentValues) {
                return null;
            }

            $item->knownKeys(self::PLACEMENT_KEYS);
            $field = $item->string('field');
            $width = $item->optionalString('width') ?? LayoutWidth::DEFAULT->value;

            if ($field === null) {
                return null;
            }

            $fields[] = new LayoutField($field, $width, $item->at());
        }

        if (! $handle instanceof Handle || ($values->has('label') && ! $label instanceof LocalizedText) || ($values->has('instructions') && ! $instructions instanceof LocalizedText)) {
            return null;
        }

        return new LayoutSection($handle, $label, $instructions, $collapsible, $fields, $values->at());
    }

    /**
     * Whether agents see the field: as its `agents` says, or else as its group, or for a top-level
     * field as its classification decides. A field whose classification could not be read is not
     * seen.
     *
     * @param  ?Classification  $classification  the field's own classification, or null inside a group
     */
    private function agents(DocumentValues $field, ?Classification $classification, ?EnclosingGroup $group): bool
    {
        $default = $group instanceof EnclosingGroup ? $group->agents : ($classification?->seenByAgentsByDefault() ?? false);

        return $field->bool('agents', $default);
    }

    /**
     * The values of an object of the document, which read nested fields of the same owner into the
     * same problems, as the fields of the group given. A field replaces it with itself once its
     * classification and agents are read.
     */
    private function values(stdClass $object, SourceLocation $at, Owner $owner, EnclosingGroup $group, ReadProblems $problems): DocumentValues
    {
        return new DocumentValues(
            $object,
            $at,
            $problems,
            fn (mixed $value, SourceLocation $fieldsAt): ?array => $this->fields($value, $owner, $fieldsAt, $group, $problems),
        );
    }
}
