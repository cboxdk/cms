<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use BackedEnum;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\AddonFieldType;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\CoreFieldType;
use Cbox\Cms\Generators\Schema\Domain\Dto\AddonOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\BooleanOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\Capabilities;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DecimalOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupRepeat;
use Cbox\Cms\Generators\Schema\Domain\Dto\IntegerOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\RichTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\History;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\RichTextLink;
use Cbox\Cms\Generators\Schema\Domain\RichTextList;
use Cbox\Cms\Generators\Schema\Domain\RichTextMark;
use Cbox\Cms\Generators\Schema\Domain\RichTextStyle;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\Stages;
use Cbox\Cms\Generators\Schema\Domain\TextFormat;
use Cbox\Cms\Generators\Schema\Domain\TypeId;
use JsonException;
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
 */
#[Internal]
final readonly class BlueprintDocumentReader
{
    public const string PACKAGE = 'cboxdk/cms-generators';

    private const array TYPE_KEYS = ['blueprint', 'kind', 'type_id', 'handle', 'label', 'description', 'version', 'capabilities', 'fields'];

    private const array EXTENSION_KEYS = ['blueprint', 'kind', 'extends', 'version', 'fields'];

    private const array CAPABILITY_KEYS = ['history', 'stages', 'localization', 'routable'];

    private const array FIELD_KEYS = ['handle', 'label', 'description', 'type', 'required', 'classification', 'filterable', 'sortable', 'agents'];

    /** @var array<string, list<string>> the keys of each core field type, beside FIELD_KEYS */
    private const array OPTION_KEYS = [
        'text' => ['min_length', 'max_length', 'format'],
        'long_text' => ['min_length', 'max_length'],
        'integer' => ['min', 'max', 'unit'],
        'decimal' => ['precision', 'scale', 'min', 'max', 'unit'],
        'boolean' => [],
        'date' => ['min', 'max'],
        'datetime' => ['min', 'max'],
        'select' => ['options', 'multiple', 'min_items', 'max_items'],
        'rich_text' => ['styles', 'marks', 'lists', 'links'],
        'group' => ['fields', 'repeat'],
    ];

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
        $problems = [];
        $at = new SourceLocation($file, '');
        $kind = $document->kind ?? null;

        $blueprint = match ($kind) {
            'type' => $this->type($document, $owner, $at, $problems),
            'extension' => $this->extension($document, $owner, $at, $problems),
            default => $this->unknownValue($kind, $at->below('kind'), $problems),
        };

        if ($problems !== [] || $blueprint === null) {
            throw GenerationFailed::with($problems === [] ? [$this->unreadable($at)] : $problems);
        }

        return $blueprint;
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function type(stdClass $document, Owner $owner, SourceLocation $at, array &$problems): ?TypeBlueprint
    {
        $this->knownKeys($document, self::TYPE_KEYS, $at, $problems);

        $typeId = $this->typeId($document, 'type_id', $at, $problems);
        $handle = $this->handle($document, 'handle', $at, $problems);
        $label = $this->string($document, 'label', $at, $problems);
        $description = $this->optionalString($document, 'description', $at, $problems);
        $version = $this->int($document, 'version', $at, $problems);
        $capabilities = $this->capabilities($document->capabilities ?? null, $at->below('capabilities'), $problems);
        $fields = $this->fields($document->fields ?? null, $owner, $at->below('fields'), true, $problems);

        if (! $typeId instanceof TypeId || ! $handle instanceof Handle || $label === null || $version === null || ! $capabilities instanceof Capabilities || $fields === null) {
            return null;
        }

        return new TypeBlueprint($typeId, $handle, $label, $description, $version, $capabilities, $fields, $owner, $at);
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function extension(stdClass $document, Owner $owner, SourceLocation $at, array &$problems): ?ExtensionBlueprint
    {
        $this->knownKeys($document, self::EXTENSION_KEYS, $at, $problems);

        $extends = $this->typeId($document, 'extends', $at, $problems);
        $version = $this->int($document, 'version', $at, $problems);
        $fields = $this->fields($document->fields ?? null, $owner, $at->below('fields'), true, $problems);

        if (! $extends instanceof TypeId || $version === null || $fields === null) {
            return null;
        }

        return new ExtensionBlueprint($extends, $version, $fields, $owner, $at);
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function capabilities(mixed $value, SourceLocation $at, array &$problems): ?Capabilities
    {
        if (! $value instanceof stdClass) {
            $problems[] = $this->unreadable($at);

            return null;
        }

        $this->knownKeys($value, self::CAPABILITY_KEYS, $at, $problems);

        $history = $this->enum(History::class, $value->history ?? null, $at->below('history'), $problems);
        $stages = $this->enum(Stages::class, $value->stages ?? null, $at->below('stages'), $problems);
        $localization = $this->enum(Localization::class, $value->localization ?? null, $at->below('localization'), $problems);
        $routable = $this->bool($value, 'routable', Capabilities::DEFAULT_ROUTABLE, $at, $problems);

        if (! $history instanceof History || ! $stages instanceof Stages || ! $localization instanceof Localization) {
            return null;
        }

        return new Capabilities($history, $stages, $localization, $routable);
    }

    /**
     * @param  bool  $topLevel  whether the fields are a type's or an extension's own, which have a classification
     * @param  list<GenerationProblem>  $problems
     * @return ?list<FieldBlueprint>
     */
    private function fields(mixed $value, Owner $owner, SourceLocation $at, bool $topLevel, array &$problems): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $problems[] = $this->unreadable($at);

            return null;
        }

        $fields = [];

        foreach ($value as $index => $item) {
            $field = $this->field($item, $owner, $at->below($index), $topLevel, $problems);

            if ($field instanceof FieldBlueprint) {
                $fields[] = $field;
            }
        }

        return count($fields) === count($value) ? $fields : null;
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function field(mixed $value, Owner $owner, SourceLocation $at, bool $topLevel, array &$problems): ?FieldBlueprint
    {
        if (! $value instanceof stdClass) {
            $problems[] = $this->unreadable($at);

            return null;
        }

        $type = $value->type ?? null;
        $core = is_string($type) ? CoreFieldType::tryFrom($type) : null;
        $options = null;

        if ($core instanceof CoreFieldType) {
            $this->knownKeys($value, [...self::FIELD_KEYS, ...self::OPTION_KEYS[$core->value]], $at, $problems);
            $options = $this->coreOptions($core, $value, $owner, $at, $problems);
        } elseif (is_string($type) && str_contains($type, ':')) {
            $this->knownKeys($value, [...self::FIELD_KEYS, 'options'], $at, $problems);
            $options = $this->addonOptions($type, $value, $at, $problems);
        } else {
            $this->unknownValue($type, $at->below('type'), $problems);
        }

        $handle = $this->handle($value, 'handle', $at, $problems);
        $label = $this->string($value, 'label', $at, $problems);
        $description = $this->optionalString($value, 'description', $at, $problems);
        $required = $this->bool($value, 'required', FieldBlueprint::DEFAULT_REQUIRED, $at, $problems);
        $filterable = $this->bool($value, 'filterable', FieldBlueprint::DEFAULT_FILTERABLE, $at, $problems);
        $sortable = $this->bool($value, 'sortable', FieldBlueprint::DEFAULT_SORTABLE, $at, $problems);
        $agents = $this->bool($value, 'agents', FieldBlueprint::DEFAULT_AGENTS, $at, $problems);
        $classification = null;

        if (property_exists($value, 'classification')) {
            $classification = $this->enum(Classification::class, $value->classification, $at->below('classification'), $problems);
        } elseif ($topLevel) {
            $problems[] = $this->unreadable($at->below('classification'));
        }

        if (! $options instanceof FieldOptions || ! $handle instanceof Handle || $label === null || ($topLevel && ! $classification instanceof Classification)) {
            return null;
        }

        return new FieldBlueprint($handle, $label, $description, $required, $classification, $filterable, $sortable, $agents, $options, $owner, $at);
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function coreOptions(CoreFieldType $type, stdClass $field, Owner $owner, SourceLocation $at, array &$problems): ?FieldOptions
    {
        return match ($type) {
            CoreFieldType::Text => new TextOptions(
                $this->optionalInt($field, 'min_length', $at, $problems),
                $this->optionalInt($field, 'max_length', $at, $problems) ?? TextOptions::DEFAULT_MAX_LENGTH,
                property_exists($field, 'format')
                    ? ($this->enum(TextFormat::class, $field->format, $at->below('format'), $problems) ?? TextOptions::DEFAULT_FORMAT)
                    : TextOptions::DEFAULT_FORMAT,
            ),
            CoreFieldType::LongText => new LongTextOptions(
                $this->optionalInt($field, 'min_length', $at, $problems),
                $this->optionalInt($field, 'max_length', $at, $problems) ?? LongTextOptions::DEFAULT_MAX_LENGTH,
            ),
            CoreFieldType::Integer => new IntegerOptions(
                $this->optionalInt($field, 'min', $at, $problems),
                $this->optionalInt($field, 'max', $at, $problems),
                $this->optionalString($field, 'unit', $at, $problems),
            ),
            CoreFieldType::Decimal => $this->decimalOptions($field, $at, $problems),
            CoreFieldType::Boolean => new BooleanOptions,
            CoreFieldType::Date => new DateOptions(
                $this->optionalString($field, 'min', $at, $problems),
                $this->optionalString($field, 'max', $at, $problems),
            ),
            CoreFieldType::Datetime => new DatetimeOptions(
                $this->optionalString($field, 'min', $at, $problems),
                $this->optionalString($field, 'max', $at, $problems),
            ),
            CoreFieldType::Select => $this->selectOptions($field, $at, $problems),
            CoreFieldType::RichText => new RichTextOptions(
                $this->enumList(RichTextStyle::class, $field, 'styles', $at, $problems),
                $this->enumList(RichTextMark::class, $field, 'marks', $at, $problems),
                $this->enumList(RichTextList::class, $field, 'lists', $at, $problems),
                $this->enumList(RichTextLink::class, $field, 'links', $at, $problems),
            ),
            CoreFieldType::Group => $this->groupOptions($field, $owner, $at, $problems),
        };
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function decimalOptions(stdClass $field, SourceLocation $at, array &$problems): ?DecimalOptions
    {
        $precision = $this->int($field, 'precision', $at, $problems);
        $scale = $this->int($field, 'scale', $at, $problems);

        if ($precision === null || $scale === null) {
            return null;
        }

        return new DecimalOptions(
            $precision,
            $scale,
            $this->optionalString($field, 'min', $at, $problems),
            $this->optionalString($field, 'max', $at, $problems),
            $this->optionalString($field, 'unit', $at, $problems),
        );
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function selectOptions(stdClass $field, SourceLocation $at, array &$problems): ?SelectOptions
    {
        $list = $field->options ?? null;
        $listAt = $at->below('options');

        if (! is_array($list) || ! array_is_list($list)) {
            $problems[] = $this->unreadable($listAt);

            return null;
        }

        $options = [];

        foreach ($list as $index => $item) {
            $itemAt = $listAt->below($index);

            if (! $item instanceof stdClass) {
                $problems[] = $this->unreadable($itemAt);

                continue;
            }

            $this->knownKeys($item, ['value', 'label'], $itemAt, $problems);
            $value = $this->handle($item, 'value', $itemAt, $problems);
            $label = $this->string($item, 'label', $itemAt, $problems);

            if ($value instanceof Handle && $label !== null) {
                $options[] = new SelectOption($value, $label);
            }
        }

        if (count($options) !== count($list)) {
            return null;
        }

        return new SelectOptions(
            $options,
            $this->bool($field, 'multiple', SelectOptions::DEFAULT_MULTIPLE, $at, $problems),
            $this->optionalInt($field, 'min_items', $at, $problems),
            $this->optionalInt($field, 'max_items', $at, $problems),
        );
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function groupOptions(stdClass $field, Owner $owner, SourceLocation $at, array &$problems): ?GroupOptions
    {
        $fields = $this->fields($field->fields ?? null, $owner, $at->below('fields'), false, $problems);
        $repeat = null;

        if (property_exists($field, 'repeat')) {
            $repeatAt = $at->below('repeat');

            if (! $field->repeat instanceof stdClass) {
                $problems[] = $this->unreadable($repeatAt);

                return null;
            }

            $this->knownKeys($field->repeat, ['min_items', 'max_items'], $repeatAt, $problems);
            $repeat = new GroupRepeat(
                $this->optionalInt($field->repeat, 'min_items', $repeatAt, $problems),
                $this->optionalInt($field->repeat, 'max_items', $repeatAt, $problems),
            );
        }

        return $fields === null ? null : new GroupOptions($fields, $repeat);
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function addonOptions(string $type, stdClass $field, SourceLocation $at, array &$problems): ?AddonOptions
    {
        try {
            $addonType = new AddonFieldType($type);
        } catch (GenerationFailed) {
            $problems[] = $this->unreadable($at->below('type'));

            return null;
        }

        if (! property_exists($field, 'options')) {
            return new AddonOptions($addonType, null);
        }

        if (! $field->options instanceof stdClass) {
            $problems[] = $this->unreadable($at->below('options'));

            return null;
        }

        try {
            $json = json_encode(self::canonical($field->options), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            $problems[] = $this->unreadable($at->below('options'));

            return null;
        }

        return new AddonOptions($addonType, $json);
    }

    /**
     * A decoded JSON value with the keys of every object sorted, so equal options give equal JSON.
     */
    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $sorted = new stdClass;

            foreach ($properties as $key => $property) {
                $sorted->{$key} = self::canonical($property);
            }

            return $sorted;
        }

        if (is_array($value)) {
            return array_map(self::canonical(...), $value);
        }

        return $value;
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function typeId(stdClass $object, string $key, SourceLocation $at, array &$problems): ?TypeId
    {
        $value = $this->string($object, $key, $at, $problems);

        if ($value === null) {
            return null;
        }

        try {
            return TypeId::fromString($value);
        } catch (InvalidUuid7) {
            $problems[] = $this->unreadable($at->below($key));

            return null;
        }
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function handle(stdClass $object, string $key, SourceLocation $at, array &$problems): ?Handle
    {
        $value = $this->string($object, $key, $at, $problems);

        if ($value === null) {
            return null;
        }

        try {
            return new Handle($value);
        } catch (GenerationFailed) {
            $problems[] = $this->unreadable($at->below($key));

            return null;
        }
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function string(stdClass $object, string $key, SourceLocation $at, array &$problems): ?string
    {
        $value = $object->{$key} ?? null;

        if (! is_string($value)) {
            $problems[] = $this->unreadable($at->below($key));

            return null;
        }

        return $value;
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function optionalString(stdClass $object, string $key, SourceLocation $at, array &$problems): ?string
    {
        return property_exists($object, $key) ? $this->string($object, $key, $at, $problems) : null;
    }

    /**
     * An integer. JSON Schema counts a number with a zero fraction, such as YAML's `3.0`, as an
     * integer, so it is read as one.
     *
     * @param  list<GenerationProblem>  $problems
     */
    private function int(stdClass $object, string $key, SourceLocation $at, array &$problems): ?int
    {
        $value = $object->{$key} ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value && $value >= PHP_INT_MIN && $value < PHP_INT_MAX) {
            return (int) $value;
        }

        $problems[] = $this->unreadable($at->below($key));

        return null;
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function optionalInt(stdClass $object, string $key, SourceLocation $at, array &$problems): ?int
    {
        return property_exists($object, $key) ? $this->int($object, $key, $at, $problems) : null;
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function bool(stdClass $object, string $key, bool $default, SourceLocation $at, array &$problems): bool
    {
        if (! property_exists($object, $key)) {
            return $default;
        }

        if (! is_bool($object->{$key})) {
            $problems[] = $this->unreadable($at->below($key));

            return $default;
        }

        return $object->{$key};
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  list<GenerationProblem>  $problems
     * @return ?T
     */
    private function enum(string $enum, mixed $value, SourceLocation $at, array &$problems): ?BackedEnum
    {
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            $this->unknownValue($value, $at, $problems);
        }

        return $case;
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  list<GenerationProblem>  $problems
     * @return ?list<T>
     */
    private function enumList(string $enum, stdClass $object, string $key, SourceLocation $at, array &$problems): ?array
    {
        if (! property_exists($object, $key)) {
            return null;
        }

        $values = $object->{$key};
        $listAt = $at->below($key);

        if (! is_array($values) || ! array_is_list($values)) {
            $problems[] = $this->unreadable($listAt);

            return null;
        }

        $cases = [];

        foreach ($values as $index => $value) {
            $case = $this->enum($enum, $value, $listAt->below($index), $problems);

            if ($case instanceof BackedEnum) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * Records every key of the object that this generator does not know.
     *
     * @param  list<string>  $known
     * @param  list<GenerationProblem>  $problems
     */
    private function knownKeys(stdClass $object, array $known, SourceLocation $at, array &$problems): void
    {
        foreach (array_keys(get_object_vars($object)) as $key) {
            if (! in_array((string) $key, $known, true)) {
                $problems[] = $this->unsupported($at->below((string) $key), sprintf('the key "%s" is not one this %s knows', $key, self::PACKAGE));
            }
        }
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function unknownValue(mixed $value, SourceLocation $at, array &$problems): null
    {
        $problems[] = is_string($value)
            ? $this->unsupported($at, sprintf('the value "%s" is not one this %s knows', $value, self::PACKAGE))
            : $this->unreadable($at);

        return null;
    }

    /**
     * A value of a kind this generator cannot map, which the installed blueprint schema allowed.
     */
    private function unreadable(SourceLocation $at): GenerationProblem
    {
        return $this->unsupported($at, sprintf('the value is not of a kind this %s can read', self::PACKAGE));
    }

    private function unsupported(SourceLocation $at, string $what): GenerationProblem
    {
        return new GenerationProblem(GenerateErrorCode::SchemaUnsupportedVersion, sprintf(
            '%s: %s. The installed blueprint schema allows it, so the file needs a newer %s.',
            $at->describe(),
            $what,
            self::PACKAGE,
        ));
    }
}
