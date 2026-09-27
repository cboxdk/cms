<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\Dto\Capabilities;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\History;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Owner;
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
 * when cboxdk/cms-contracts ships an addition to v1 (decision 2) before cboxdk/cms-generators is
 * updated for it, and each such place is reported as generate_schema_unsupported_version at its
 * JSON pointer, so nothing in a file is ever dropped without a word.
 *
 * The `type` of every field is resolved in the FieldTypeRegistry, the core's types included, and
 * the FieldType found there reads the field's options (GUARDRAILS 2.4). A `<namespace>:<handle>`
 * that no contributor registered is generate_unknown_field_type; any other name the schema allowed
 * and the registry lacks is a core field type this generator does not know.
 */
#[Internal]
final readonly class BlueprintDocumentReader
{
    public const string PACKAGE = 'cboxdk/cms-generators';

    private const array TYPE_KEYS = ['blueprint', 'kind', 'type_id', 'handle', 'label', 'description', 'version', 'capabilities', 'fields'];

    private const array EXTENSION_KEYS = ['blueprint', 'kind', 'extends', 'version', 'fields'];

    private const array CAPABILITY_KEYS = ['history', 'stages', 'localization', 'routable'];

    private const array FIELD_KEYS = ['handle', 'label', 'description', 'type', 'required', 'classification', 'filterable', 'sortable', 'agents'];

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
        $values = $this->values($document, new SourceLocation($file, ''), $owner, $problems);

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
        $label = $document->string('label');
        $description = $document->optionalString('description');
        $version = $document->int('version');
        $capabilities = $this->capabilities($document);
        $fields = $this->fields($document->value('fields'), $owner, $document->at()->below('fields'), true, $problems);

        if (! $typeId instanceof TypeId || ! $handle instanceof Handle || $label === null || $version === null || ! $capabilities instanceof Capabilities || $fields === null) {
            return null;
        }

        return new TypeBlueprint($typeId, $handle, $label, $description, $version, $capabilities, $fields, $owner, $document->at());
    }

    private function extension(DocumentValues $document, Owner $owner, ReadProblems $problems): ?ExtensionBlueprint
    {
        $document->knownKeys(self::EXTENSION_KEYS);

        $extends = $document->typeId('extends');
        $version = $document->int('version');
        $fields = $this->fields($document->value('fields'), $owner, $document->at()->below('fields'), true, $problems);

        if (! $extends instanceof TypeId || $version === null || $fields === null) {
            return null;
        }

        return new ExtensionBlueprint($extends, $version, $fields, $owner, $document->at());
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
     * @param  bool  $topLevel  whether the fields are a type's or an extension's own, which have a classification
     * @return ?list<FieldBlueprint>
     */
    private function fields(mixed $value, Owner $owner, SourceLocation $at, bool $topLevel, ReadProblems $problems): ?array
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

            $field = $this->field($this->values($item, $itemAt, $owner, $problems), $owner, $topLevel);

            if ($field instanceof FieldBlueprint) {
                $fields[] = $field;
            }
        }

        return count($fields) === count($value) ? $fields : null;
    }

    private function field(DocumentValues $field, Owner $owner, bool $topLevel): ?FieldBlueprint
    {
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
        $label = $field->string('label');
        $description = $field->optionalString('description');
        $required = $field->bool('required', FieldBlueprint::DEFAULT_REQUIRED);
        $filterable = $field->bool('filterable', FieldBlueprint::DEFAULT_FILTERABLE);
        $sortable = $field->bool('sortable', FieldBlueprint::DEFAULT_SORTABLE);
        $agents = $field->bool('agents', FieldBlueprint::DEFAULT_AGENTS);
        $classification = null;

        if ($field->has('classification')) {
            $classification = $field->enum(Classification::class, 'classification');
        } elseif ($topLevel) {
            $field->unreadable('classification');
        }

        if (! $options instanceof FieldOptions || ! $handle instanceof Handle || $label === null || ($topLevel && ! $classification instanceof Classification)) {
            return null;
        }

        return new FieldBlueprint($handle, $label, $description, $required, $classification, $filterable, $sortable, $agents, $options, $owner, $field->at());
    }

    /**
     * The values of an object of the document, which read nested fields of the same owner into the
     * same problems.
     */
    private function values(stdClass $object, SourceLocation $at, Owner $owner, ReadProblems $problems): DocumentValues
    {
        return new DocumentValues(
            $object,
            $at,
            $problems,
            fn (mixed $value, SourceLocation $fieldsAt): ?array => $this->fields($value, $owner, $fieldsAt, false, $problems),
        );
    }
}
