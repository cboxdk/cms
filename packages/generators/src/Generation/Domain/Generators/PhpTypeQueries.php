<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Generators;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\TypeTables\AttributeFilter;
use Cbox\Cms\Contracts\TypeTables\ColumnFilter;
use Cbox\Cms\Contracts\TypeTables\ColumnOrder;
use Cbox\Cms\Contracts\TypeTables\EntryRecord;
use Cbox\Cms\Contracts\TypeTables\FilterOperator;
use Cbox\Cms\Contracts\TypeTables\InvalidTypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\RecordPage;
use Cbox\Cms\Contracts\TypeTables\RelationFilter;
use Cbox\Cms\Contracts\TypeTables\SortDirection;
use Cbox\Cms\Contracts\TypeTables\TypeTableCursor;
use Cbox\Cms\Contracts\TypeTables\TypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Contracts\TypeTables\TypeTableRow;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\RelationDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\Dto\PhpClass;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generator;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Closure;
use DateTimeImmutable;
use Override;

/**
 * The typed query builder of each type (PRD 8.8, 11.12, 5.10), in `QueryBuilders/<Type>` below
 * the PHP directory, one namespace per type, named by its TypeHandle case (`ShopProduct` for
 * `shop:product`). PRD 11.12 names the directory `Queries`, but a `Queries` namespace segment holds
 * query DTOs below Domain (GUARDRAILS 2.5), as `Dto` does, so the builders take another name:
 *
 * - `<Type>FilterField`, a string-backed enum of the owner's filterable fields, and
 *   `<Type>SortField`, one of its sortable fields, each case valued by the field's column. A filter
 *   takes only the first and an order only the second, so a field without an index is a type
 *   error, not a query that scans the table.
 * - `<Type>Query`, a final readonly builder: `where()` with a filterable field, an operator and
 *   field values, a typed `where<Field>()` per filterable field, `orderBy()` with a sortable field,
 *   for a filterable relation (PRD 5.11) `where<Field>()` with the target's EntryId and its
 *   filterable attributes, and `orderBy<Field>()`, the target's reverse listing by the sort key on
 *   the relation's edges, newest first by default,
 *   `after()` with the cursor of the page before, `limit()`, and `page()`, which asks the kernel's
 *   TypeTableReader for the page, with the caller's AccessContext, and hydrates each row through
 *   the owner's record factory from the container, so the page holds the owner's interface
 *   `<Type>Record`.
 *
 * The builder of a type with localization variants (PRD 10.1) has `locale()`, because its table
 * holds a row per language, and page() refuses to read without one; the builder of a type without
 * localization reads the shared variant and has no `locale()`.
 *
 * The builder is the owner's (PRD 11.12, point 4): it knows only the owner's fields, and its code
 * compiles against the owner's interfaces. The kernel adds the access predicate and the keyset
 * pagination by the order and then the entry id, and refuses what the blueprint does not allow.
 * A field of a type that has no comparable order, rich text, a group or a select that allows
 * several options, is refused with generate_field_not_queryable when its blueprint declares it
 * filterable or sortable. Two filterable fields whose methods PHP would take for one, because it
 * compares method names without case, are refused with generate_name_collision.
 *
 * The output uses only the public API of cboxdk/cms and is formatted the way Pint, Rector and
 * PHPStan level 10 accept it unchanged.
 */
#[Internal]
final readonly class PhpTypeQueries implements Generator
{
    /** The directory of the query builders below the PHP directory, and the namespace below the PHP namespace. */
    public const string DIRECTORY = 'QueryBuilders';

    /**
     * What a typed filter method of the builder takes for each core field type of the blueprint
     * schema v1, and how it compares. The generator-coverage test holds the keys to the field
     * types of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array FIELD_TYPES = [
        'boolean' => 'bool, compared as false before true',
        'date' => 'DateTimeImmutable, compared by its day',
        'datetime' => 'DateTimeImmutable, compared as an instant',
        'decimal' => 'numeric-string, compared as a number',
        'group' => 'not queryable: the blueprint schema refuses a filterable or sortable group, and so does the generator, with generate_field_not_queryable',
        'integer' => 'int, compared as a number',
        'link' => 'not queryable: the blueprint schema refuses a filterable or sortable link, and so does the generator, with generate_field_not_queryable',
        'long_text' => 'string, compared by the column\'s collation; the blueprint schema refuses a filterable or sortable long text',
        'relation' => 'EntryId, the target, through the kernel\'s relations table, with the target\'s filterable attributes compared by equality; the blueprint schema refuses a sortable relation, and so does the generator, with generate_field_not_queryable',
        'rich_text' => 'not queryable: the blueprint schema refuses a filterable or sortable rich text, and so does the generator, with generate_field_not_queryable',
        'select' => 'the <Field>Choice case, compared by its value; a filterable or sortable select that allows several options is refused with generate_field_not_queryable',
        'text' => 'string, compared by the column\'s collation',
    ];

    /**
     * What the query builders hold for a blueprint file of each kind. The generator-coverage test
     * holds the keys to the kinds of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array KINDS = [
        'extension' => 'nothing: the builder is the owner\'s and filters and sorts on the owner\'s fields only',
        'type' => 'the enums <Type>FilterField and <Type>SortField and the builder <Type>Query',
    ];

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
     * @param  list<GenerationProblem>  $problems
     * @return list<GeneratedFile>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function type(TypeDescriptor $type, GenerationTarget $target, array &$problems): array
    {
        $case = PhpTypeHandleEnum::caseName($type);
        $filterable = [];
        $relations = [];
        $sortable = [];
        $methods = [];
        $typeProblems = [];

        foreach ($type->ownFields() as $field) {
            GeneratedLines::fieldType(self::class, self::FIELD_TYPES, $field);

            if (! $field->filterable && ! $field->sortable) {
                continue;
            }

            if ($field->relation instanceof RelationDescriptor && $field->filterable && ! $field->sortable) {
                $method = 'where'.PhpSource::studly($field->handle->value);
                $key = strtolower($method);

                if (isset($methods[$key])) {
                    $typeProblems[] = $this->collision($type, $field, $method, $methods[$key]);

                    continue;
                }

                $methods[$key] = $field->handle->value;
                $relations[] = $field;

                continue;
            }

            if (! $this->queryable($field)) {
                $typeProblems[] = new GenerationProblem(GenerateErrorCode::FieldNotQueryable, sprintf(
                    '%s: the field %s of %s is declared %s, but a field of the type %s has no order the query builder can compare. Remove filterable and sortable from it.',
                    $field->location->describe(),
                    $field->handle->value,
                    $type->name(),
                    $field->filterable ? 'filterable' : 'sortable',
                    $this->unqueryable($field),
                ));

                continue;
            }

            if ($field->filterable) {
                $method = 'where'.PhpSource::studly($field->handle->value);
                $key = strtolower($method);

                if (isset($methods[$key])) {
                    $typeProblems[] = $this->collision($type, $field, $method, $methods[$key]);

                    continue;
                }

                $methods[$key] = $field->handle->value;
                $filterable[] = $field;
            }

            if ($field->sortable) {
                $sortable[] = $field;
            }
        }

        if ($typeProblems !== []) {
            array_push($problems, ...$typeProblems);

            return [];
        }

        $namespace = $target->phpNamespace.'\\'.self::DIRECTORY.'\\'.$case;
        $directory = $target->phpDirectory.'/'.self::DIRECTORY.'/'.$case;
        $classes = [
            $case.'FilterField' => $this->fieldEnum($case.'FilterField', $filterable, sprintf('The filterable fields of %s, each valued by its column in the type table (PRD 8.8). A filter takes only these, so filtering on a field without an index is a type error.', $type->name())),
            $case.'Query' => $this->builder($type, $case, $target, $filterable, $relations),
            $case.'SortField' => $this->fieldEnum($case.'SortField', $sortable, sprintf('The sortable fields of %s, each valued by its column in the type table (PRD 8.8). An order takes only these, so sorting on a field without an index is a type error.', $type->name())),
        ];
        $files = [];

        foreach ($classes as $class => $source) {
            $files[] = PhpSource::file($directory.'/'.$class.'.php', $namespace, $source->imports, $source->lines);
        }

        return $files;
    }

    private function collision(TypeDescriptor $type, FieldDescriptor $field, string $method, string $other): GenerationProblem
    {
        return new GenerationProblem(GenerateErrorCode::NameCollision, sprintf(
            '%s: the field %s of %s would get the method %s, which the field %s has already. Choose handles that differ in more than underscores and case.',
            $field->location->describe(),
            $field->handle->value,
            $type->name(),
            $method,
            $other,
        ));
    }

    /**
     * Whether the builder can compare the field: a scalar field type other than long text, or a
     * select with one option, by the field's base. Blueprint v1 refuses a filterable or sortable
     * long text, group, rich text, relation or link of the core; an addon's field type is held to its base here.
     */
    private function queryable(FieldDescriptor $field): bool
    {
        return match ($field->base) {
            'group', 'link', 'long_text', 'relation', 'rich_text' => false,
            'select' => $field->php->native !== 'array',
            default => true,
        };
    }

    /**
     * The field type a problem names for a field the builder cannot compare, with its base when it
     * is an addon's field type.
     */
    private function unqueryable(FieldDescriptor $field): string
    {
        $form = $field->base === 'select' ? 'select that allows several options' : $field->base;

        return $field->type === $field->base ? $form : sprintf('%s, in the form of a %s,', $field->type, $form);
    }

    /**
     * @param  list<FieldDescriptor>  $fields
     */
    private function fieldEnum(string $enum, array $fields, string $summary): PhpClass
    {
        return new PhpClass([], [
            ...PhpSource::docblock([$summary]),
            'enum '.$enum.': string',
            '{',
            ...array_map(
                static fn (FieldDescriptor $field): string => sprintf('    case %s = %s;', PhpSource::studly($field->handle->value), PhpSource::literal($field->handle->value)),
                $fields,
            ),
            '}',
        ]);
    }

    /**
     * @param  list<FieldDescriptor>  $filterable
     * @param  list<FieldDescriptor>  $relations  the filterable relations
     */
    private function builder(TypeDescriptor $type, string $case, GenerationTarget $target, array $filterable, array $relations): PhpClass
    {
        $records = PhpRecords::namespaceOf($type, $target);
        $imports = [
            VariantKey::class, FieldValue::class, AccessContext::class, TypeName::class, ColumnFilter::class,
            ColumnOrder::class, EntryRecord::class, FilterOperator::class, RecordPage::class, SortDirection::class,
            TypeTableCursor::class, TypeTableQuery::class, TypeTableReader::class, TypeTableRow::class,
            $records.'\\'.$case.'Record', $records.'\\'.PhpRecords::factoryInterfaceOf($type),
        ];
        $typed = [];

        $factory = PhpRecords::factoryInterfaceOf($type);
        $localized = $type->capabilities->localization === Localization::Variants;
        $with = static fn (string $filters, string $order, string $after, string $limit, string $locale = '$this->locale', string $relations = '$this->relations'): string => sprintf(
            '        return new self($this->reader, $this->records, %s, %s, %s, %s, %s%s);',
            $filters,
            $order,
            $after,
            $limit,
            $relations,
            $localized ? ', '.$locale : '',
        );

        foreach ($filterable as $field) {
            $filter = $this->typedFilter($case, $field, $records);
            $typed = [...$typed, '', ...$filter->lines];
            $imports = [...$imports, ...$filter->imports];
        }

        foreach ($relations as $field) {
            $filter = $this->relationFilter($case, $field, $records, $with);
            $typed = [...$typed, '', ...$filter->lines];
            $imports = [...$imports, ...$filter->imports];
        }

        $imports[] = RelationFilter::class;

        if ($localized) {
            $imports = [...$imports, Locale::class, InvalidTypeTableQuery::class];
        }

        return new PhpClass($imports, [
            ...PhpSource::docblock([
                sprintf('The typed query builder of %s (PRD 8.8, 11.12): filters on its filterable fields, an order by its sortable fields and keyset pagination, read through the kernel\'s TypeTableReader and hydrated through the owner\'s record factory. The kernel adds the explicit access predicate of the caller\'s AccessContext and sorts by the entry id last, so every page continues exactly after the one before.', $type->name()),
                'Ask the container for it, and call page() inside the read transaction whose actor context the query pipeline has set. Each method returns a new builder; the builder itself never changes.',
            ]),
            'final readonly class '.$case.'Query',
            '{',
            '    /** The type the builder reads. */',
            '    public const string TYPE = '.PhpSource::literal($type->name()).';',
            '',
            '    /**',
            '     * @param  list<ColumnFilter>  $filters',
            '     * @param  list<ColumnOrder>  $order',
            '     * @param  list<RelationFilter>  $relations',
            '     */',
            '    public function __construct(',
            '        private TypeTableReader $reader,',
            '        private '.$factory.' $records,',
            '        private array $filters = [],',
            '        private array $order = [],',
            '        private ?TypeTableCursor $after = null,',
            '        private int $limit = TypeTableQuery::DEFAULT_LIMIT,',
            '        private array $relations = [],',
            ...($localized ? ['        private ?Locale $locale = null,'] : []),
            '    ) {}',
            ...($localized ? [
                '',
                '    /**',
                '     * Reads the rows of the language: the type has localization variants, so its table holds a',
                '     * row per language, with the shared fields in each (PRD 10.1, 11.6).',
                '     */',
                '    public function locale(Locale $locale): self',
                '    {',
                $with('$this->filters', '$this->order', '$this->after', '$this->limit', '$locale'),
                '    }',
            ] : []),
            '',
            '    /**',
            '     * Keeps the rows whose field compares with the values as the operator says. A null field',
            '     * compares with no value; test it with FilterOperator::IsNull and IsNotNull.',
            '     */',
            '    public function where('.$case.'FilterField $field, FilterOperator $operator, FieldValue ...$values): self',
            '    {',
            $with('[...$this->filters, new ColumnFilter($field->value, $operator, ...$values)]', '$this->order', '$this->after', '$this->limit'),
            '    }',
            ...$typed,
            '',
            '    /**',
            '     * Sorts by the field after the keys before it. Null sorts as the greatest value.',
            '     */',
            '    public function orderBy('.$case.'SortField $field, SortDirection $direction = SortDirection::Ascending): self',
            '    {',
            $with('$this->filters', '[...$this->order, new ColumnOrder($field->value, $direction)]', '$this->after', '$this->limit'),
            '    }',
            '',
            '    /**',
            '     * Starts the page after the row the cursor of the page before names, in the same order.',
            '     */',
            '    public function after(TypeTableCursor $cursor): self',
            '    {',
            $with('$this->filters', '$this->order', '$cursor', '$this->limit'),
            '    }',
            '',
            '    /**',
            '     * How many records the page holds, 1 to TypeTableQuery::MAX_LIMIT.',
            '     */',
            '    public function limit(int $limit): self',
            '    {',
            $with('$this->filters', '$this->order', '$this->after', '$limit'),
            '    }',
            '',
            '    /**',
            '     * The page as the context may read it, each record as the owner\'s interface.',
            '     *',
            '     * @return RecordPage<'.$case.'Record>',
            ...($localized ? ['     *', '     * @throws InvalidTypeTableQuery without a locale'] : []),
            '     */',
            '    public function page(AccessContext $access): RecordPage',
            '    {',
            ...($localized ? [
                '        $variant = VariantKey::of($this->locale ?? throw InvalidTypeTableQuery::localeRequired(new TypeName(self::TYPE)));',
                '        $page = $this->reader->page(new TypeTableQuery(new TypeName(self::TYPE), $variant, $this->filters, $this->order, $this->after, $this->limit, $this->relations), $access);',
            ] : [
                '        $page = $this->reader->page(new TypeTableQuery(new TypeName(self::TYPE), VariantKey::shared(), $this->filters, $this->order, $this->after, $this->limit, $this->relations), $access);',
            ]),
            '',
            '        return new RecordPage(array_map(',
            '            fn (TypeTableRow $row): EntryRecord => new EntryRecord($row->entry, $this->records->fromFieldValues($row->fields)),',
            '            $page->rows,',
            '        ), $page->next);',
            '    }',
            '}',
        ]);
    }

    /**
     * The typed filter method of a filterable field, and the classes it uses.
     */
    private function typedFilter(string $case, FieldDescriptor $field, string $records): PhpClass
    {
        $choice = PhpSource::studly($field->handle->value).'Choice';

        [$native, $doc, $value, $imports] = match ($field->base) {
            'integer' => ['int', null, 'new IntegerValue($value)', [IntegerValue::class]],
            'decimal' => ['string', 'numeric-string', 'new DecimalValue($value)', [DecimalValue::class]],
            'boolean' => ['bool', null, 'new BooleanValue($value)', [BooleanValue::class]],
            'date' => ['DateTimeImmutable', null, 'new DateValue($value->format(\'Y-m-d\'))', [DateTimeImmutable::class, DateValue::class]],
            'datetime' => ['DateTimeImmutable', null, 'new DateTimeValue($value)', [DateTimeImmutable::class, DateTimeValue::class]],
            'select' => [$choice, null, 'new TextValue($value->value)', [$records.'\\'.$choice, TextValue::class]],
            default => ['string', null, 'new TextValue($value)', [TextValue::class]],
        };

        $summary = PhpSource::comment($field->label.($field->description === null ? '' : ': '.$field->description));
        $studly = PhpSource::studly($field->handle->value);

        return new PhpClass($imports, [
            '    /**',
            '     * '.$summary,
            ...($doc === null ? [] : ['     *', '     * @param  '.$doc.'  ...$values']),
            '     */',
            '    public function where'.$studly.'(FilterOperator $operator, '.$native.' ...$values): self',
            '    {',
            sprintf(
                '        return $this->where(%sFilterField::%s, $operator, ...array_map(static fn (%s $value): FieldValue => %s, $values));',
                $case,
                $studly,
                $native,
                $value,
            ),
            '    }',
        ]);
    }

    /**
     * The filter and the order of a filterable relation (PRD 5.11), and the classes they use:
     * `where<Field>()` with the target and an optional value per filterable attribute of its
     * targets, and `orderBy<Field>()`, the order by its sort_by field, which the kernel reads from
     * the sort key on the relation's edges.
     *
     * @param  Closure(string, string, string, string, string=, string=): string  $with
     */
    private function relationFilter(string $case, FieldDescriptor $field, string $records, Closure $with): PhpClass
    {
        $studly = PhpSource::studly($field->handle->value);
        $sortBy = $field->relation?->sortBy;
        $parameters = ['EntryId $target'];
        $attributes = [];
        $imports = [EntryId::class];

        foreach ($field->relationMeta() as $attribute) {
            if (! $attribute->filterable) {
                continue;
            }

            $name = PhpSource::camel($attribute->handle->value);
            $choice = $studly.PhpSource::studly($attribute->handle->value).'Choice';

            [$native, $value, $valueImports] = match ($attribute->base) {
                'integer' => ['int', 'new IntegerValue($%s)', [IntegerValue::class]],
                'boolean' => ['bool', 'new BooleanValue($%s)', [BooleanValue::class]],
                'date' => ['DateTimeImmutable', 'new DateValue($%s->format(\'Y-m-d\'))', [DateTimeImmutable::class, DateValue::class]],
                'select' => [$choice, 'new TextValue($%s->value)', [$records.'\\'.$choice, TextValue::class]],
                default => ['string', 'new TextValue($%s)', [TextValue::class]],
            };

            $parameters[] = '?'.$native.' $'.$name.' = null';
            $attributes[] = in_array($native, ['int', 'bool', 'string'], true)
                ? sprintf('        if ($%s !== null) {', $name)
                : sprintf('        if ($%s instanceof %s) {', $name, $native);
            $attributes[] = sprintf('            $attributes[] = new AttributeFilter(new FieldHandle(%s), %s);', PhpSource::literal($attribute->handle->value), sprintf($value, $name));
            $attributes[] = '        }';
            $attributes[] = '';
            $imports = [...$imports, ...$valueImports, AttributeFilter::class, FieldHandle::class];
        }

        $summary = PhpSource::comment($field->label.($field->description === null ? '' : ': '.$field->description));
        $filter = sprintf('[...$this->relations, new RelationFilter(%s, $target%s)]', PhpSource::literal($field->handle->value), $attributes === [] ? '' : ', ...$attributes');
        $order = [];

        if ($sortBy instanceof Handle) {
            $order = [
                '',
                '    /**',
                ...$this->docLines(sprintf('Orders by %s, the sort key the kernel writes onto the released edges of %s: after where%s(), the newest sources of the target first, read from the index on the edges.', $sortBy->value, $field->handle->value, $studly)),
                '     */',
                '    public function orderBy'.$studly.'(SortDirection $direction = SortDirection::Descending): self',
                '    {',
                sprintf('        return $this->orderBy(%sSortField::%s, $direction);', $case, PhpSource::studly($sortBy->value)),
                '    }',
            ];
        }

        return new PhpClass($imports, [
            '    /**',
            ...$this->docLines($summary),
            '     *',
            ...$this->docLines('Keeps the records whose released relation points to the target, through the kernel\'s relations table'.($attributes === [] ? '.' : ', on an edge with the attributes given.')),
            '     */',
            '    public function where'.$studly.'('.implode(', ', $parameters).'): self',
            '    {',
            ...($attributes === [] ? [] : ['        $attributes = [];', '', ...$attributes]),
            $with('$this->filters', '$this->order', '$this->after', '$this->limit', '$this->locale', $filter),
            '    }',
            ...$order,
        ]);
    }

    /**
     * The text as the lines of a method's docblock, wrapped at 96 characters.
     *
     * @return list<string>
     */
    private function docLines(string $text): array
    {
        return array_map(static fn (string $line): string => '     * '.$line, explode("\n", wordwrap(PhpSource::comment($text), 89)));
    }
}
