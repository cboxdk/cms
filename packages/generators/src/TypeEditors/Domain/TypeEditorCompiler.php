<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\TypeEditors\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Editors\EditorBase;
use Cbox\Cms\Contracts\Editors\EditorChoice;
use Cbox\Cms\Contracts\Editors\EditorField;
use Cbox\Cms\Contracts\Editors\EditorRepeat;
use Cbox\Cms\Contracts\Editors\InvalidTypeEditor;
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
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Codec\Domain\PhpSource;
use Cbox\Cms\Generators\Codec\Domain\RecordContracts;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\Dto\ExtensionVersion as ExtenderVersion;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
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
 */
#[Internal]
final readonly class TypeEditorCompiler
{
    /**
     * @param  Closure(CodecValue): string  $ruleJson  writes the ValueRule of a leaf field as JSON
     */
    public function __construct(private Closure $ruleJson) {}

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
            presence: $this->presence($field),
            classification: ClassificationAccess::from($field->classification->value),
            agents: $field->agents,
            encrypted: $field->encrypted,
            choices: array_map(static fn (SelectOption $option): EditorChoice => new EditorChoice(new FieldHandle($option->value->value), $option->label), $field->choices),
            repeat: $group ? $this->repeat($value) : null,
            rule: $group ? null : new JsonDocument(($this->ruleJson)($value)),
            translatable: $field->translatable,
            fallback: FieldFallback::from($field->fallback->value),
        )];

        foreach ($this->inFileOrder($field->fields) as $nested) {
            array_push($fields, ...$this->tree($nested, $type, $namespace, $path));
        }

        return $fields;
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

    private function presence(FieldDescriptor $field): Presence
    {
        $first = $field->validation[0] ?? null;

        return match (true) {
            $first?->name === ValidationRuleName::Required => Presence::Required,
            $field->required => Presence::RequiredOnRelease,
            default => Presence::Optional,
        };
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
     * The fields in the order of their blueprint file: by the index of each field's place among
     * its siblings, the last `/fields/<n>` of its source location, then by handle.
     *
     * @param  list<FieldDescriptor>  $fields
     * @return list<FieldDescriptor>
     */
    private function inFileOrder(array $fields): array
    {
        usort($fields, static function (FieldDescriptor $a, FieldDescriptor $b): int {
            $byIndex = self::index($a) <=> self::index($b);

            return $byIndex !== 0 ? $byIndex : strcmp($a->handle->value, $b->handle->value);
        });

        return $fields;
    }

    private static function index(FieldDescriptor $field): int
    {
        return preg_match('/\/fields\/([0-9]+)\z/', $field->location->pointer, $match) === 1 ? (int) $match[1] : PHP_INT_MAX;
    }
}
