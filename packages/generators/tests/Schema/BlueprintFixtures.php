<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use BackedEnum;
use Cbox\Cms\Generators\Schema\Domain\Classification;
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
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\History;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\Stages;
use Cbox\Cms\Generators\Schema\Domain\TextFormat;
use Cbox\Cms\Generators\Schema\Domain\TypeId;
use LogicException;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds blueprint models for the blueprint tests, and writes a model back as the YAML file it
 * would be read from, so the YAML source and the fake can be given the same blueprints.
 */
final class BlueprintFixtures
{
    /** The fixture types of T40's Codecs tests, in packages/contracts. */
    public const string CONTRACT_FIXTURES = __DIR__.'/../../../contracts/tests/Codecs/Fixtures/Blueprint';

    /**
     * A type with a text, a select and a repeated group field, in a file below the root.
     */
    public static function type(SchemaRoot $root, string $path, string $typeId, string $handle): TypeBlueprint
    {
        $at = new SourceLocation($root->file($path), '');
        $fields = $at->below('fields');

        return new TypeBlueprint(
            TypeId::fromString($typeId),
            new Handle($handle),
            ucfirst(str_replace('_', ' ', $handle)),
            'The '.str_replace('_', ' ', $handle).' of the fixture.',
            1,
            new Capabilities(History::Full, Stages::DraftRelease, Localization::None, true),
            [
                self::field($root, $fields->below(0), 'title', new TextOptions(null, 120, TextFormat::Plain), Classification::Public, required: true),
                self::field($root, $fields->below(1), 'section', new SelectOptions([
                    new SelectOption(new Handle('news'), 'News'),
                    new SelectOption(new Handle('sport'), 'Sport'),
                ], false, null, null), Classification::Public, filterable: true),
                self::field($root, $fields->below(2), 'credits', new GroupOptions([
                    self::field($root, $fields->below(2, 'fields', 0), 'name', new TextOptions(null, 100, TextFormat::Plain), null),
                ], new GroupRepeat(1, 10)), Classification::Personal),
            ],
            $root->owner,
            $at,
        );
    }

    /**
     * An extension that adds one text field to the type with the given id.
     */
    public static function extension(SchemaRoot $root, string $path, string $extends): ExtensionBlueprint
    {
        $at = new SourceLocation($root->file($path), '');

        return new ExtensionBlueprint(
            TypeId::fromString($extends),
            2,
            [self::field($root, $at->below('fields', 0), 'tax_code', new TextOptions(null, 20, TextFormat::Plain), Classification::Internal)],
            $root->owner,
            $at,
        );
    }

    public static function field(
        SchemaRoot $root,
        SourceLocation $at,
        string $handle,
        FieldOptions $options,
        ?Classification $classification,
        bool $required = false,
        bool $filterable = false,
    ): FieldBlueprint {
        return new FieldBlueprint(
            new Handle($handle),
            ucfirst(str_replace('_', ' ', $handle)),
            'The '.str_replace('_', ' ', $handle).'.',
            $required,
            $classification,
            $filterable,
            false,
            true,
            $options,
            $root->owner,
            $at,
        );
    }

    /**
     * The blueprint file a model is read from. Every value is written out, defaults included.
     */
    public static function yaml(TypeBlueprint|ExtensionBlueprint $blueprint): string
    {
        $document = $blueprint instanceof TypeBlueprint
            ? [
                'blueprint' => 1,
                'kind' => 'type',
                'type_id' => $blueprint->typeId->toString(),
                'handle' => $blueprint->handle->value,
                'label' => $blueprint->label,
                ...($blueprint->description === null ? [] : ['description' => $blueprint->description]),
                'version' => $blueprint->version,
                'capabilities' => [
                    'history' => $blueprint->capabilities->history->value,
                    'stages' => $blueprint->capabilities->stages->value,
                    'localization' => $blueprint->capabilities->localization->value,
                    'routable' => $blueprint->capabilities->routable,
                ],
                'fields' => array_map(self::fieldDocument(...), $blueprint->fields),
            ]
            : [
                'blueprint' => 1,
                'kind' => 'extension',
                'extends' => $blueprint->extends->toString(),
                'version' => $blueprint->version,
                'fields' => array_map(self::fieldDocument(...), $blueprint->fields),
            ];

        return Yaml::dump($document, 20, 2, Yaml::DUMP_OBJECT_AS_MAP);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fieldDocument(FieldBlueprint $field): array
    {
        $document = [
            'handle' => $field->handle->value,
            'label' => $field->label,
            ...($field->description === null ? [] : ['description' => $field->description]),
            'type' => $field->options->typeName(),
            'required' => $field->required,
            ...($field->classification instanceof Classification ? ['classification' => $field->classification->value] : []),
            // Only a field that may be filtered or sorted has the keys at all: a group, long text
            // and rich text must leave them out.
            ...($field->filterable ? ['filterable' => true] : []),
            ...($field->sortable ? ['sortable' => true] : []),
            'agents' => $field->agents,
        ];

        return [...$document, ...self::optionsDocument($field->options)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function optionsDocument(FieldOptions $options): array
    {
        $document = match (true) {
            $options instanceof TextOptions => ['min_length' => $options->minLength, 'max_length' => $options->maxLength, 'format' => $options->format->value],
            $options instanceof LongTextOptions => ['min_length' => $options->minLength, 'max_length' => $options->maxLength],
            $options instanceof IntegerOptions => ['min' => $options->min, 'max' => $options->max, 'unit' => $options->unit],
            $options instanceof DecimalOptions => ['precision' => $options->precision, 'scale' => $options->scale, 'min' => $options->min, 'max' => $options->max, 'unit' => $options->unit],
            $options instanceof BooleanOptions => [],
            $options instanceof DateOptions, $options instanceof DatetimeOptions => ['min' => $options->min, 'max' => $options->max],
            $options instanceof SelectOptions => [
                'options' => array_map(static fn (SelectOption $option): array => ['value' => $option->value->value, 'label' => $option->label], $options->options),
                'multiple' => $options->multiple,
                'min_items' => $options->minItems,
                'max_items' => $options->maxItems,
            ],
            $options instanceof RichTextOptions => [
                'styles' => self::values($options->styles),
                'marks' => self::values($options->marks),
                'lists' => self::values($options->lists),
                'links' => self::values($options->links),
            ],
            $options instanceof GroupOptions => [
                'fields' => array_map(self::fieldDocument(...), $options->fields),
                'repeat' => $options->repeat instanceof GroupRepeat ? array_filter(
                    ['min_items' => $options->repeat->minItems, 'max_items' => $options->repeat->maxItems],
                    static fn (?int $value): bool => $value !== null,
                ) : null,
            ],
            $options instanceof AddonOptions => ['options' => $options->optionsJson === null ? null : self::decodeObject($options->optionsJson)],
            default => throw new LogicException('No YAML for '.$options::class.'.'),
        };

        return array_filter($document, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  ?list<BackedEnum>  $cases
     * @return ?list<int|string>
     */
    private static function values(?array $cases): ?array
    {
        return $cases === null ? null : array_map(static fn (BackedEnum $case): int|string => $case->value, $cases);
    }

    private static function decodeObject(string $json): stdClass
    {
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        if (! $decoded instanceof stdClass) {
            throw new LogicException('The options are not a JSON object: '.$json);
        }

        return $decoded;
    }
}
