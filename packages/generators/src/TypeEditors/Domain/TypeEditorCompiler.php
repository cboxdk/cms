<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\TypeEditors\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Editors\EditorBase;
use Cbox\Cms\Contracts\Editors\EditorChoice;
use Cbox\Cms\Contracts\Editors\EditorField;
use Cbox\Cms\Contracts\Editors\EditorLayout;
use Cbox\Cms\Contracts\Editors\EditorPlacement;
use Cbox\Cms\Contracts\Editors\EditorRepeat;
use Cbox\Cms\Contracts\Editors\EditorSection;
use Cbox\Cms\Contracts\Editors\EditorTab;
use Cbox\Cms\Contracts\Editors\EditorText;
use Cbox\Cms\Contracts\Editors\EditorUi;
use Cbox\Cms\Contracts\Editors\EditorWidth;
use Cbox\Cms\Contracts\Editors\InvalidTypeEditor;
use Cbox\Cms\Contracts\Editors\SelectDisplay as EditorDisplay;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\FieldFallback;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\Condition;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Codec\Domain\PhpSource;
use Cbox\Cms\Generators\Codec\Domain\RecordContracts;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldPlacement;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\LayoutDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\SectionDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TabDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\LayoutCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\Dto\ExtensionVersion as ExtenderVersion;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldDefault;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldUi;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\SelectDisplay;
use Closure;

/**
 * Compiles the editor descriptor of a type from its type descriptor (PRD 11.12, 14.1): the
 * TypeEditor the generated TypeEditors holds, which the panel's forms render the type from.
 *
 * The fields come in form order: the owner's top-level fields in the order of the blueprint file,
 * each group followed by the fields below it in the same order, then each extender's fields the
 * same way, by namespace. A field's presence is what its generated validator requires: Required
 * when the validator's first rule is `required`, RequiredOnRelease for an extension field the
 * blueprint requires but the validator does not (PRD 11.12, point 1), else Optional. A leaf
 * field's rule is the ValueRule of the record's TypeScript module for that field, written by the
 * closure the compiler is given (RuleJson in the Boundary, since the domain writes no JSON); a
 * group has none, and its repeat is what the blueprint declares.
 *
 * The title field is the first required text field of the owner in blueprint order, else the
 * owner's first text field, or null when the owner has no text field.
 *
 * A conditional field (gap G3) carries its condition as one JSON document in the form of the
 * runtime's ConditionRule, written by the third closure (RuleJson::condition() in the Boundary),
 * and its when_hidden; its presence holds while it is shown.
 *
 * The presentation keys (gap G2) come from the descriptor as the blueprints gave them: the label
 * and the description per locale as EditorTexts, the composed layout as the EditorLayout, each
 * field's `ui` as its EditorUi, and its `default` as one JSON document written by the second
 * closure (DefaultJson in the Boundary).
 */
#[Internal]
final readonly class TypeEditorCompiler
{
    /**
     * @param  Closure(CodecValue): string  $ruleJson  writes the ValueRule of a leaf field as JSON
     * @param  Closure(FieldDefault): string  $defaultJson  writes the default of a field as JSON
     * @param  Closure(Condition): string  $conditionJson  writes the condition of a field as JSON
     */
    public function __construct(private Closure $ruleJson, private Closure $defaultJson, private Closure $conditionJson) {}

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    public function compile(TypeDescriptor $type): TypeEditor
    {
        $capabilities = $type->capabilities;
        $own = $this->inFileOrder($type->ownFields());
        $fields = [];

        foreach ($own as $field) {
            array_push($fields, ...$this->tree($field, $type, null, null));
        }

        foreach ($type->extensionFields() as $namespace => $extension) {
            foreach ($this->inFileOrder($extension) as $field) {
                array_push($fields, ...$this->tree($field, $type, new FieldNamespace($namespace), null));
            }
        }

        try {
            return new TypeEditor(
                TypeId::fromString($type->typeId->toString()),
                new TypeName($type->name()),
                $type->label,
                $type->description,
                $type->version,
                new TypeCapabilities(
                    History::from($capabilities->history->value),
                    Stages::from($capabilities->stages->value),
                    Localization::from($capabilities->localization->value),
                    $capabilities->routable,
                ),
                $this->titleField($own),
                array_map(
                    static fn (ExtenderVersion $extension): ExtensionVersion => new ExtensionVersion(new FieldNamespace($extension->namespace->value), $extension->version),
                    $type->extensions,
                ),
                $fields,
                $this->layout($type->layout()),
                self::texts($type->labels),
                self::texts($type->descriptions),
            );
        } catch (InvalidTypeEditor $invalid) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('The editor descriptor of %s is invalid: %s', $type->name(), $invalid->getMessage()), $invalid);
        }
    }

    /**
     * The field and, for a group, the fields below it, in form order.
     *
     * @return list<EditorField>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function tree(FieldDescriptor $field, TypeDescriptor $type, ?FieldNamespace $namespace, ?string $parent): array
    {
        $path = ($parent === null ? ($namespace instanceof FieldNamespace ? 'ext.'.$namespace->value.'.' : '') : $parent.'.').$field->handle->value;
        $value = RecordContracts::fieldValue($field, $type);
        $group = $field->base === EditorBase::Group->value;
        $base = EditorBase::tryFrom($field->base) ?? throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf(
            '%s has no mapping for the field type "%s" of %s. Add the field type to its bases.',
            self::class,
            $field->base,
            $field->location->describe(),
        ));

        $fields = [new EditorField(
            path: $path,
            handle: new FieldHandle($field->handle->value),
            namespace: $namespace,
            parent: $parent,
            fieldType: $field->type,
            base: $base,
            label: $field->label,
            description: $field->description,
            presence: $field->presence(),
            classification: ClassificationAccess::from($field->classification->value),
            agents: $field->agents,
            encrypted: $field->encrypted,
            choices: array_map(static fn (SelectOption $option): EditorChoice => new EditorChoice(new FieldHandle($option->value->value), $option->label, self::texts($option->labels)), $field->choices),
            repeat: $group ? $this->repeat($value) : null,
            rule: $group ? null : new JsonDocument(($this->ruleJson)($value)),
            translatable: $field->translatable,
            fallback: FieldFallback::from($field->fallback->value),
            labels: self::texts($field->labels),
            descriptions: self::texts($field->descriptions),
            ui: $field->ui instanceof FieldUi ? $this->ui($field->ui) : new EditorUi,
            defaultValue: $field->default instanceof FieldDefault ? new JsonDocument(($this->defaultJson)($field->default)) : null,
            when: $field->when instanceof Condition ? new JsonDocument(($this->conditionJson)($field->when)) : null,
            whenHidden: $field->whenHidden,
        )];

        foreach ($this->inFileOrder($field->fields) as $nested) {
            array_push($fields, ...$this->tree($nested, $type, $namespace, $path));
        }

        return $fields;
    }

    /**
     * The composed layout as the descriptor carries it.
     */
    private function layout(LayoutDescriptor $layout): EditorLayout
    {
        return new EditorLayout(
            array_map(static fn (TabDescriptor $tab): EditorTab => new EditorTab(new FieldHandle($tab->handle->value), $tab->label->text, self::texts($tab->label->byLocale), self::sections($tab->sections)), $layout->tabs),
            self::sections($layout->sidebar),
        );
    }

    /**
     * @param  list<SectionDescriptor>  $sections
     * @return list<EditorSection>
     */
    private static function sections(array $sections): array
    {
        return array_map(static fn (SectionDescriptor $section): EditorSection => new EditorSection(
            new FieldHandle($section->handle->value),
            $section->label?->text,
            self::texts($section->label->byLocale ?? []),
            $section->instructions?->text,
            self::texts($section->instructions->byLocale ?? []),
            $section->collapsible,
            array_map(static fn (FieldPlacement $placement): EditorPlacement => new EditorPlacement($placement->path, EditorWidth::from($placement->width->value)), $section->fields),
        ), $sections);
    }

    private function ui(FieldUi $ui): EditorUi
    {
        return new EditorUi(
            instructions: $ui->instructions?->text,
            instructionsByLocale: self::texts($ui->instructions->byLocale ?? []),
            inputHint: $ui->inputHint?->text,
            inputHintByLocale: self::texts($ui->inputHint->byLocale ?? []),
            readOnly: $ui->readOnly,
            hidden: $ui->hidden,
            display: $ui->display instanceof SelectDisplay ? EditorDisplay::from($ui->display->value) : null,
            summary: $ui->summary,
        );
    }

    /**
     * @param  array<string, string>  $byLocale
     * @return list<EditorText>
     */
    private static function texts(array $byLocale): array
    {
        $texts = [];

        foreach ($byLocale as $locale => $text) {
            $texts[] = new EditorText(new Locale($locale), $text);
        }

        return $texts;
    }

    /**
     * How a group repeats: the `min_items` and `max_items` of its list, or null for a group that
     * does not repeat.
     */
    private function repeat(CodecValue $value): ?EditorRepeat
    {
        if (! PhpSource::rule($value, ValidationRuleName::List) instanceof ValidationRule && ! $value->item instanceof CodecValue) {
            return null;
        }

        $min = PhpSource::rule($value, ValidationRuleName::MinItems)->arguments[0] ?? null;
        $max = PhpSource::rule($value, ValidationRuleName::MaxItems)->arguments[0] ?? null;

        return new EditorRepeat($min === null ? null : (int) $min, $max === null ? null : (int) $max);
    }

    /**
     * @param  list<FieldDescriptor>  $own  the owner's top-level fields in file order
     */
    private function titleField(array $own): ?string
    {
        $texts = array_values(array_filter($own, static fn (FieldDescriptor $field): bool => $field->base === EditorBase::Text->value));
        $required = array_values(array_filter($texts, static fn (FieldDescriptor $field): bool => $field->required));

        return ($required[0] ?? $texts[0] ?? null)?->handle->value;
    }

    /**
     * The fields in the order of their blueprint file (LayoutCompiler::inFileOrder()).
     *
     * @param  list<FieldDescriptor>  $fields
     * @return list<FieldDescriptor>
     */
    private function inFileOrder(array $fields): array
    {
        return LayoutCompiler::inFileOrder($fields);
    }
}
