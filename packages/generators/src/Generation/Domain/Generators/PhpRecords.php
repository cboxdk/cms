<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Generators;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldReader;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\FieldWriter;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\Dto\PhpClass;
use Cbox\Cms\Generators\Generation\Domain\Dto\RecordProperty;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generator;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use DateTimeImmutable;
use Override;

/**
 * The PHP records of the type chain (PRD 11.12, point 4), in `Records/<Type>` below the PHP
 * directory, one namespace per type, named by its TypeHandle case (`ShopProduct` for
 * `shop:product`):
 *
 * - `<Type>Record`, the owner's interface: a get-only property per field of the owner, camelCase,
 *   and `toFieldValues()`. The owner's code knows its type only through it.
 * - `<Type>RecordFactory`, the owner's interface of the record factory in the container, which the
 *   owner's query builder hydrates through.
 * - For each extender's namespace, such as `app`: `<Type>AppExtension` with the property `ext`,
 *   `<Type>AppExt` with the property `app`, and the final readonly class `<Type>AppFields` with the
 *   namespace's fields, so PHP reads an extension field as `$record->ext->app->taxCode`.
 * - The composite record `<Type>`, a final readonly class that implements the owner's interface and
 *   every extender's, with `<Type>Ext` holding each namespace's fields, and `<Type>Factory`, the
 *   record factory the generated service provider binds to `<Type>RecordFactory`.
 * - A string-backed enum per select field, `<Field>Choice`, with a case per option; a final
 *   readonly class per group field, `<Field>Group`, or per item of a repeated group,
 *   `<Field>Item`. The name of a nested field's class starts with its group's, and an extension
 *   field's with its namespace, such as `AppColourChoice`.
 *
 * A record converts to and from the kernel's generic field values (Cbox\Cms\Contracts\Fields)
 * through FieldReader and FieldWriter, so the kernel reads and writes the fields of any type
 * without knowing it (GUARDRAILS 2.4). Rich text is held as the kernel's ListValue of Portable Text
 * (PRD 11.10). An owner's required field is not nullable; every other field is, as its descriptor
 * says.
 *
 * Two fields, options or namespaces of one type that would get the same PHP name, compared as PHP
 * compares class names, without case, are refused with generate_name_collision, and so is a name
 * PHP reserves. The output is formatted the way Pint, Rector and PHPStan level 10 accept it
 * unchanged, and it uses only the public API of cboxdk/cms, never an #[Internal] class.
 */
#[Internal]
final readonly class PhpRecords implements Generator
{
    /** The directory of the records below the PHP directory, and the namespace below the PHP namespace. */
    public const string DIRECTORY = 'Records';

    /**
     * What a record holds for each core field type of the blueprint schema v1. A field type
     * without a mapping here is refused as invalid output. The generator-coverage test holds the
     * keys to the field types of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array FIELD_TYPES = [
        'boolean' => 'bool',
        'date' => 'DateTimeImmutable at midnight UTC',
        'datetime' => 'DateTimeImmutable in UTC',
        'decimal' => 'numeric-string in canonical form',
        'group' => 'a <Field>Group, or a list of <Field>Item when the group repeats',
        'integer' => 'int',
        'long_text' => 'string',
        'rich_text' => 'the kernel\'s ListValue of Portable Text blocks',
        'select' => 'a case of the enum <Field>Choice, or a list of them when the field allows several',
        'text' => 'string',
    ];

    /**
     * What the records hold for a blueprint file of each kind. The generator-coverage test holds
     * the keys to the kinds of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array KINDS = [
        'extension' => 'the extender\'s interface <Type><Namespace>Extension and its fields in <Type><Namespace>Fields, which the composite record implements',
        'type' => 'the owner\'s interface <Type>Record, the factory interface <Type>RecordFactory, the composite record <Type> and its factory',
    ];

    /**
     * Names PHP does not take as a class, an enum case or a promoted property.
     *
     * @var list<string>
     */
    private const array RESERVED = ['class', 'this'];

    #[Override]
    public function directory(GenerationTarget $target): string
    {
        return $target->phpDirectory;
    }

    #[Override]
    public function generate(CompiledSchema $schema, GenerationTarget $target): array
    {
        PhpTypeHandleEnum::assertCaseNames($schema);

        $files = [];
        $problems = [];

        foreach ($schema->types as $type) {
            $files = [...$files, ...$this->type($type, $target, $problems)];
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        return $files;
    }

    /**
     * The namespace of a type's records, such as `App\Cms\Generated\Records\ShopProduct`.
     */
    public static function namespaceOf(TypeDescriptor $type, GenerationTarget $target): string
    {
        return $target->phpNamespace.'\\'.self::DIRECTORY.'\\'.PhpTypeHandleEnum::caseName($type);
    }

    /**
     * The owner's interface of the record factory of a type, such as `ShopProductRecordFactory`.
     */
    public static function factoryInterfaceOf(TypeDescriptor $type): string
    {
        return PhpTypeHandleEnum::caseName($type).'RecordFactory';
    }

    /**
     * The record factory of the composite record of a type, such as `ShopProductFactory`.
     */
    public static function factoryOf(TypeDescriptor $type): string
    {
        return PhpTypeHandleEnum::caseName($type).'Factory';
    }

    /**
     * @param  list<GenerationProblem>  $problems
     * @return list<GeneratedFile>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function type(TypeDescriptor $type, GenerationTarget $target, array &$problems): array
    {
        $case = PhpTypeHandleEnum::caseName($type);
        $namespace = self::namespaceOf($type, $target);
        $directory = $target->phpDirectory.'/'.self::DIRECTORY.'/'.$case;
        $names = [];
        $classes = [];
        $typeProblems = [];

        foreach ([$case, $case.'Record', $case.'RecordFactory', $case.'Factory'] as $name) {
            $this->claim($type, $name, 'the type', $names, $typeProblems);
        }

        $own = $this->properties($type, $type->ownFields(), '', $classes, $names, $typeProblems);
        $extensions = [];

        foreach ($type->extensionFields() as $extender => $fields) {
            $studly = PhpSource::studly($extender);

            foreach (['Extension', 'Ext', 'Fields'] as $suffix) {
                $this->claim($type, $case.$studly.$suffix, 'the namespace '.$extender, $names, $typeProblems);
            }

            $extensions[$extender] = $this->properties($type, $fields, $studly, $classes, $names, $typeProblems);
        }

        if ($extensions !== []) {
            $this->claim($type, $case.'Ext', 'the extensions', $names, $typeProblems);
        }

        if ($typeProblems !== []) {
            array_push($problems, ...$typeProblems);

            return [];
        }

        $classes[$case.'Record'] = $this->ownerInterface($type, $case, $own);
        $classes[$case.'RecordFactory'] = $this->factoryInterface($type, $case);
        $classes[$case] = $this->composite($type, $case, $own, $extensions);
        $classes[$case.'Factory'] = $this->factory($type, $case);

        foreach ($extensions as $extender => $properties) {
            $studly = PhpSource::studly($extender);
            $classes[$case.$studly.'Extension'] = $this->extensionInterface($type, $case, $extender);
            $classes[$case.$studly.'Ext'] = $this->namespaceInterface($type, $case, $extender);
            $classes[$case.$studly.'Fields'] = $this->fieldsClass(
                $case.$studly.'Fields',
                [sprintf('The fields that %s adds to %s, which code addresses as ext.%s.<handle>.', $extender, $type->name(), $extender)],
                $properties,
                false,
            );
        }

        if ($extensions !== []) {
            $classes[$case.'Ext'] = $this->extClass($type, $case, array_keys($extensions));
        }

        ksort($classes, SORT_STRING);
        $files = [];

        foreach ($classes as $class => $source) {
            $files[] = PhpSource::file($directory.'/'.$class.'.php', $namespace, $source->imports, $source->lines);
        }

        return $files;
    }

    /**
     * The properties of the fields, sorted by handle, and the classes their values need.
     *
     * @param  list<FieldDescriptor>  $fields
     * @param  string  $prefix  the start of the names of the fields' classes: the group's, or the namespace's
     * @param  array<string, PhpClass>  $classes
     * @param  array<string, string>  $names
     * @param  list<GenerationProblem>  $problems
     * @return list<RecordProperty>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function properties(TypeDescriptor $type, array $fields, string $prefix, array &$classes, array &$names, array &$problems): array
    {
        usort($fields, static fn (FieldDescriptor $a, FieldDescriptor $b): int => strcmp($a->handle->value, $b->handle->value));
        $properties = [];
        $propertyNames = [];

        foreach ($fields as $field) {
            $property = $this->property($type, $field, $prefix, $classes, $names, $problems);
            $key = $property->name;

            if (isset($propertyNames[$key]) || in_array(strtolower($key), self::RESERVED, true)) {
                $problems[] = $this->collision($type, $field, sprintf('the property $%s', $key), $propertyNames[$key] ?? 'PHP');
            }

            $propertyNames[$key] = 'the field '.$this->address($field);
            $properties[] = $property;
        }

        return $properties;
    }

    /**
     * @param  array<string, PhpClass>  $classes
     * @param  array<string, string>  $names
     * @param  list<GenerationProblem>  $problems
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function property(TypeDescriptor $type, FieldDescriptor $field, string $prefix, array &$classes, array &$names, array &$problems): RecordProperty
    {
        GeneratedLines::fieldType(self::class, self::FIELD_TYPES, $field);

        $handle = $field->handle->value;
        $name = PhpSource::camel($handle);
        $or = $field->php->nullable ? 'OrNull' : '';
        $quoted = PhpSource::literal($handle);
        $class = $prefix.PhpSource::studly($handle);
        $repeated = $this->hasRule($field, ValidationRuleName::List);

        return match ($field->base) {
            'text', 'long_text' => $this->scalar($field, 'string', null, 'text'),
            'integer' => $this->scalar($field, 'int', null, 'integer'),
            'decimal' => $this->scalar($field, 'string', 'numeric-string', 'decimal'),
            'boolean' => $this->scalar($field, 'bool', null, 'boolean'),
            'date' => $this->scalar($field, 'DateTimeImmutable', null, 'date', DateTimeImmutable::class),
            'datetime' => $this->scalar($field, 'DateTimeImmutable', null, 'dateTime', DateTimeImmutable::class),
            'rich_text' => $this->scalar($field, 'ListValue', null, 'list', ListValue::class),
            'select' => $this->select($type, $field, $name, $class.'Choice', $or, $quoted, $repeated, $classes, $names, $problems),
            default => $this->group($type, $field, $name, $class.($repeated ? 'Item' : 'Group'), $repeated, $classes, $names, $problems),
        };
    }

    /**
     * A field whose value FieldReader and FieldWriter read and write with one method each, such as
     * `text()` and `textOrNull()`.
     */
    private function scalar(FieldDescriptor $field, string $native, ?string $doc, string $method, string ...$imports): RecordProperty
    {
        $name = PhpSource::camel($field->handle->value);

        return new RecordProperty(
            $field,
            $name,
            $native,
            $doc,
            sprintf('$fields->%s%s(%s)', $method, $field->php->nullable ? 'OrNull' : '', PhpSource::literal($field->handle->value)),
            sprintf('FieldWriter::%s($this->%s)', $method, $name),
            array_values($imports),
        );
    }

    /**
     * @param  array<string, PhpClass>  $classes
     * @param  array<string, string>  $names
     * @param  list<GenerationProblem>  $problems
     */
    private function select(TypeDescriptor $type, FieldDescriptor $field, string $name, string $enum, string $or, string $quoted, bool $multiple, array &$classes, array &$names, array &$problems): RecordProperty
    {
        $this->claim($type, $enum, 'the field '.$this->address($field), $names, $problems);
        $classes[$enum] = $this->enum($type, $field, $enum, $problems);

        return $multiple
            ? new RecordProperty($field, $name, 'array', 'list<'.$enum.'>', sprintf('$fields->choices%s(%s, %s::class)', $or, $quoted, $enum), sprintf('FieldWriter::choices($this->%s)', $name), [])
            : new RecordProperty($field, $name, $enum, null, sprintf('$fields->choice%s(%s, %s::class)', $or, $quoted, $enum), sprintf('FieldWriter::choice($this->%s)', $name), []);
    }

    /**
     * @param  array<string, PhpClass>  $classes
     * @param  array<string, string>  $names
     * @param  list<GenerationProblem>  $problems
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function group(TypeDescriptor $type, FieldDescriptor $field, string $name, string $class, bool $repeated, array &$classes, array &$names, array &$problems): RecordProperty
    {
        $this->claim($type, $class, 'the field '.$this->address($field), $names, $problems);
        $nested = $this->properties($type, $field->fields, substr($class, 0, -strlen($repeated ? 'Item' : 'Group')), $classes, $names, $problems);
        $summary = PhpSource::comment($field->label.($field->description === null ? '' : ': '.$field->description));
        $classes[$class] = $this->fieldsClass(
            $class,
            [$repeated
                ? sprintf('An item of the repeated group %s of %s. %s', $this->address($field), $type->name(), $summary)
                : sprintf('The group %s of %s. %s', $this->address($field), $type->name(), $summary)],
            $nested,
            $repeated,
        );
        $quoted = PhpSource::literal($field->handle->value);
        $nullable = $field->php->nullable;

        if ($repeated) {
            return new RecordProperty(
                $field,
                $name,
                'array',
                'list<'.$class.'>',
                sprintf('%s::fromList%s($fields->groups%s(%s))', $class, $nullable ? 'OrNull' : '', $nullable ? 'OrNull' : '', $quoted),
                $nullable
                    ? sprintf('FieldWriter::groups($this->%s === null ? null : %s::toFieldMaps($this->%s))', $name, $class, $name)
                    : sprintf('FieldWriter::groups(%s::toFieldMaps($this->%s))', $class, $name),
                [],
            );
        }

        return new RecordProperty(
            $field,
            $name,
            $class,
            null,
            sprintf('%s::fromFields%s($fields->group%s(%s))', $class, $nullable ? 'OrNull' : '', $nullable ? 'OrNull' : '', $quoted),
            sprintf('FieldWriter::group($this->%s%stoFieldMap())', $name, $nullable ? '?->' : '->'),
            [],
        );
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function enum(TypeDescriptor $type, FieldDescriptor $field, string $enum, array &$problems): PhpClass
    {
        $cases = [];
        $lines = [];

        foreach ($field->choices as $choice) {
            $case = PhpSource::studly($choice->value->value);
            $key = strtolower($case);

            if (isset($cases[$key]) || in_array($key, self::RESERVED, true)) {
                $problems[] = $this->collision($type, $field, sprintf('the enum case %s::%s', $enum, $case), $cases[$key] ?? 'PHP');
            }

            $cases[$key] = 'the option '.$choice->value->value;
            $lines[] = $this->caseLine($case, $choice);
        }

        return new PhpClass([], [
            ...PhpSource::docblock([
                sprintf('The options of the select field %s of %s, by value.', $this->address($field), $type->name()),
            ]),
            'enum '.$enum.': string',
            '{',
            ...$lines,
            '}',
        ]);
    }

    private function caseLine(string $case, SelectOption $choice): string
    {
        return sprintf('    case %s = %s;', $case, PhpSource::literal($choice->value->value));
    }

    /**
     * @param  list<RecordProperty>  $properties
     */
    private function ownerInterface(TypeDescriptor $type, string $case, array $properties): PhpClass
    {
        $lines = [];

        foreach ($properties as $property) {
            $lines = [...$lines, ...$this->hookedProperty($property), ''];
        }

        return new PhpClass(
            [FieldValues::class, ...$this->imports($properties)],
            [
                ...PhpSource::docblock([
                    sprintf('The fields of %s that its owner, %s, declares (PRD 11.12). The owner\'s code knows the type only through this interface; extension fields are on the extenders\' interfaces.', $type->name(), $type->owner->value),
                ]),
                'interface '.$case.'Record',
                '{',
                ...$lines,
                '    /**',
                '     * The fields as the kernel\'s generic field values, the extension fields included.',
                '     */',
                '    public function toFieldValues(): FieldValues;',
                '}',
            ],
        );
    }

    private function factoryInterface(TypeDescriptor $type, string $case): PhpClass
    {
        return new PhpClass(
            [FieldValues::class],
            [
                ...PhpSource::docblock([
                    sprintf('Builds the record of %s from the kernel\'s generic field values. The owner\'s code asks the container for it, and the generated service provider binds the composite record\'s factory.', $type->name()),
                ]),
                'interface '.$case.'RecordFactory',
                '{',
                '    public function fromFieldValues(FieldValues $values): '.$case.'Record;',
                '}',
            ],
        );
    }

    private function factory(TypeDescriptor $type, string $case): PhpClass
    {
        return new PhpClass(
            [FieldValues::class, Override::class],
            [
                ...PhpSource::docblock([
                    sprintf('The record factory of %s: builds the composite record, with every extender\'s fields.', $type->name()),
                ]),
                'final readonly class '.$case.'Factory implements '.$case.'RecordFactory',
                '{',
                '    #[Override]',
                '    public function fromFieldValues(FieldValues $values): '.$case,
                '    {',
                '        return '.$case.'::fromFieldValues($values);',
                '    }',
                '}',
            ],
        );
    }

    private function extensionInterface(TypeDescriptor $type, string $case, string $extender): PhpClass
    {
        $studly = PhpSource::studly($extender);

        return new PhpClass(
            [],
            [
                ...PhpSource::docblock([
                    sprintf('The fields that %s adds to %s, as its code reads them: $record->ext->%s.', $extender, $type->name(), $extender),
                ]),
                'interface '.$case.$studly.'Extension',
                '{',
                '    public '.$case.$studly.'Ext $ext { get; }',
                '}',
            ],
        );
    }

    private function namespaceInterface(TypeDescriptor $type, string $case, string $extender): PhpClass
    {
        $studly = PhpSource::studly($extender);

        return new PhpClass(
            [],
            [
                ...PhpSource::docblock([
                    sprintf('The extension fields of %s under the namespace %s.', $type->name(), $extender),
                ]),
                'interface '.$case.$studly.'Ext',
                '{',
                '    public '.$case.$studly.'Fields $'.$extender.' { get; }',
                '}',
            ],
        );
    }

    /**
     * @param  list<string>  $extenders
     */
    private function extClass(TypeDescriptor $type, string $case, array $extenders): PhpClass
    {
        $interfaces = array_map(static fn (string $extender): string => $case.PhpSource::studly($extender).'Ext', $extenders);
        $parameters = array_map(
            static fn (string $extender): string => '        public '.$case.PhpSource::studly($extender).'Fields $'.$extender.',',
            $extenders,
        );

        return new PhpClass(
            [],
            [
                ...PhpSource::docblock([
                    sprintf('The extension fields of %s, by the namespace of each extender.', $type->name()),
                ]),
                'final readonly class '.$case.'Ext implements '.implode(', ', $this->sorted($interfaces)),
                '{',
                '    public function __construct(',
                ...$parameters,
                '    ) {}',
                '}',
            ],
        );
    }

    /**
     * @param  list<RecordProperty>  $own
     * @param  array<string, list<RecordProperty>>  $extensions
     */
    private function composite(TypeDescriptor $type, string $case, array $own, array $extensions): PhpClass
    {
        $interfaces = [$case.'Record'];
        $build = [];
        $write = [];

        foreach (array_keys($extensions) as $extender) {
            $studly = PhpSource::studly($extender);
            $interfaces[] = $case.$studly.'Extension';
            $build[] = sprintf(
                '                %s: %s%sFields::fromFields(FieldReader::of($values->extension(new FieldNamespace(%s)))),',
                $extender,
                $case,
                $studly,
                PhpSource::literal($extender),
            );
            $write[] = sprintf(
                '            new ExtensionFields(new FieldNamespace(%s), $this->ext->%s->toFieldMap()),',
                PhpSource::literal($extender),
                $extender,
            );
        }

        $parameters = [];
        $arguments = [];

        foreach ($own as $property) {
            $parameters[] = '        public '.$property->declaration().' $'.$property->name.',';
            $arguments[] = '            '.$property->name.': '.$property->read.',';
        }

        if ($extensions !== []) {
            $parameters[] = '        public '.$case.'Ext $ext,';
            $arguments = [...$arguments, '            ext: new '.$case.'Ext(', ...$build, '            ),'];
        }

        $hasExtensions = $extensions !== [];

        return new PhpClass(
            [
                FieldHandle::class,
                FieldMap::class,
                FieldReader::class,
                FieldValues::class,
                FieldWriter::class,
                NamedValue::class,
                Override::class,
                ...($hasExtensions ? [ExtensionFields::class, FieldNamespace::class] : []),
                ...$this->imports($own),
            ],
            [
                ...PhpSource::docblock([
                    sprintf('The record of %s: the owner\'s fields and every extender\'s (PRD 11.12). It implements the owner\'s interface and each extender\'s, and converts to and from the kernel\'s generic field values.', $type->name()),
                ]),
                'final readonly class '.$case.' implements '.implode(', ', $this->sorted($interfaces)),
                '{',
                ...$this->constructor($own, $parameters),
                '',
                '    public static function fromFieldValues(FieldValues $values): self',
                '    {',
                '        $fields = FieldReader::of($values->own);',
                '',
                '        return new self(',
                ...$arguments,
                '        );',
                '    }',
                '',
                '    #[Override]',
                '    public function toFieldValues(): FieldValues',
                '    {',
                '        return new FieldValues(',
                '            new FieldMap(',
                ...array_map(
                    static fn (RecordProperty $property): string => sprintf('                new NamedValue(new FieldHandle(%s), %s),', PhpSource::literal($property->field->handle->value), $property->write),
                    $own,
                ),
                '            ),',
                ...$write,
                '        );',
                '    }',
                '}',
            ],
        );
    }

    /**
     * A final readonly class of fields that reads itself from a FieldReader and writes a FieldMap:
     * an extender's fields, a group or an item of a repeated group.
     *
     * @param  list<string>  $summary
     * @param  list<RecordProperty>  $properties
     */
    private function fieldsClass(string $class, array $summary, array $properties, bool $item): PhpClass
    {
        $parameters = array_map(
            static fn (RecordProperty $property): string => '        public '.$property->declaration().' $'.$property->name.',',
            $properties,
        );
        $arguments = array_map(
            static fn (RecordProperty $property): string => '            '.$property->name.': '.$property->read.',',
            $properties,
        );
        $entries = array_map(
            static fn (RecordProperty $property): string => sprintf('            new NamedValue(new FieldHandle(%s), %s),', PhpSource::literal($property->field->handle->value), $property->write),
            $properties,
        );

        return new PhpClass(
            [FieldHandle::class, FieldMap::class, FieldReader::class, FieldWriter::class, NamedValue::class, ...$this->imports($properties)],
            [
                ...PhpSource::docblock($summary),
                'final readonly class '.$class,
                '{',
                ...$this->constructor($properties, $parameters),
                '',
                '    public static function fromFields(FieldReader $fields): self',
                '    {',
                ...($arguments === [] ? ['        return new self;'] : ['        return new self(', ...$arguments, '        );']),
                '    }',
                '',
                ...($item ? $this->listMethods() : $this->orNullMethod()),
                '    public function toFieldMap(): FieldMap',
                '    {',
                ...($entries === [] ? ['        return new FieldMap;'] : ['        return new FieldMap(', ...$entries, '        );']),
                '    }',
                '}',
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function orNullMethod(): array
    {
        return [
            '    public static function fromFieldsOrNull(?FieldReader $fields): ?self',
            '    {',
            '        return $fields instanceof FieldReader ? self::fromFields($fields) : null;',
            '    }',
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function listMethods(): array
    {
        return [
            '    /**',
            '     * @param  list<FieldReader>  $items',
            '     * @return list<self>',
            '     */',
            '    public static function fromList(array $items): array',
            '    {',
            '        return array_map(self::fromFields(...), $items);',
            '    }',
            '',
            '    /**',
            '     * @param  list<FieldReader>|null  $items',
            '     * @return list<self>|null',
            '     */',
            '    public static function fromListOrNull(?array $items): ?array',
            '    {',
            '        return $items === null ? null : self::fromList($items);',
            '    }',
            '',
            '    /**',
            '     * @param  list<self>  $items',
            '     * @return list<FieldMap>',
            '     */',
            '    public static function toFieldMaps(array $items): array',
            '    {',
            '        return array_map(static fn (self $item): FieldMap => $item->toFieldMap(), $items);',
            '    }',
            '',
        ];
    }

    /**
     * The constructor with promoted properties, and the PHPDoc of the ones whose native type says
     * less than their PHPDoc type.
     *
     * @param  list<RecordProperty>  $properties
     * @param  list<string>  $parameters
     * @return list<string>
     */
    private function constructor(array $properties, array $parameters): array
    {
        if ($parameters === []) {
            return ['    public function __construct() {}'];
        }

        $docs = [];

        foreach ($properties as $property) {
            if ($property->docType() !== null) {
                $docs[] = sprintf('     * @param  %s  $%s', $property->docType(), $property->name);
            }
        }

        return [
            ...($docs === [] ? [] : ['    /**', ...$docs, '     */']),
            '    public function __construct(',
            ...$parameters,
            '    ) {}',
        ];
    }

    /**
     * A get-only property of an interface, with the field's label and description.
     *
     * @return list<string>
     */
    private function hookedProperty(RecordProperty $property): array
    {
        $field = $property->field;
        $docType = $property->docType();

        return [
            '    /**',
            '     * '.PhpSource::comment($field->label.($field->description === null ? '.' : ': '.$field->description)),
            ...($docType === null ? [] : ['     *', '     * @var '.$docType]),
            '     */',
            '    public '.$property->declaration().' $'.$property->name.' { get; }',
        ];
    }

    /**
     * @param  list<RecordProperty>  $properties
     * @return list<string>
     */
    private function imports(array $properties): array
    {
        $imports = [];

        foreach ($properties as $property) {
            $imports = [...$imports, ...$property->imports];
        }

        return $imports;
    }

    /**
     * Takes a class name for a type, or reports that another part of the type has it.
     *
     * @param  array<string, string>  $names  lowercase name to what has it
     * @param  list<GenerationProblem>  $problems
     */
    private function claim(TypeDescriptor $type, string $name, string $what, array &$names, array &$problems): void
    {
        $key = strtolower($name);

        if (isset($names[$key])) {
            $problems[] = new GenerationProblem(GenerateErrorCode::NameCollision, sprintf(
                '%s: %s of %s would get the PHP class name %s, which %s has already. Choose handles that differ in more than underscores and case.',
                $type->location->describe(),
                $what,
                $type->name(),
                $name,
                $names[$key],
            ));

            return;
        }

        $names[$key] = $what;
    }

    private function collision(TypeDescriptor $type, FieldDescriptor $field, string $what, string $other): GenerationProblem
    {
        return new GenerationProblem(GenerateErrorCode::NameCollision, sprintf(
            '%s: the field %s of %s would get %s, which %s has already. Choose handles that differ in more than underscores.',
            $field->location->describe(),
            $this->address($field),
            $type->name(),
            $what,
            $other,
        ));
    }

    /**
     * Interface names in the order Pint puts them.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        usort($names, static fn (string $a, string $b): int => strcmp(strtolower($a), strtolower($b)));

        return $names;
    }

    private function address(FieldDescriptor $field): string
    {
        return $field->namespace instanceof Owner ? 'ext.'.$field->namespace->value.'.'.$field->handle->value : $field->handle->value;
    }

    private function hasRule(FieldDescriptor $field, ValidationRuleName $name): bool
    {
        return array_any($field->validation, static fn (ValidationRule $rule): bool => $rule->name === $name);
    }
}
