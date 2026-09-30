<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Generators;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\FieldTypes\FieldBase;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Editor\Domain\RelativePath;
use Cbox\Cms\Generators\Generation\Domain\Dto\ExtensionVersion as ExtenderVersion;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generator;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Override;

/**
 * The type catalog of the type chain (PRD 11.12, GUARDRAILS 2.4), in the PHP directory:
 *
 * - `GeneratedTypeCatalog`, the implementation of Cbox\Cms\Contracts\Schema\TypeCatalog with a
 *   TypeDefinition per type, sorted by name: its id, name, the owner's version and each extender's,
 *   its capabilities and its top-level fields with their columns, classification and agents flag,
 *   all from the type's descriptor. The kernel knows the types only through it, at run time.
 * - `GeneratedTypeValidators`, the implementation of Cbox\Cms\Contracts\Validation\TypeValidators
 *   with the generated validator of every type (PhpTypeValidators), sorted by type id.
 * - `GeneratedTypesServiceProvider`, a Laravel service provider that binds TypeCatalog to the
 *   catalog, TypeValidators to the validators and each type's record factory interface to the
 *   composite record's factory (PhpRecords), so the owner's code gets the composite record
 *   without knowing the extenders, and registers the migrations directory, where
 *   TypeTableMigrations writes the migrations of the type tables, with the migrator, by its path
 *   relative to the provider. The application registers it once.
 *
 * The output is formatted the way Pint, Rector and PHPStan level 10 accept it unchanged, and it uses
 * only the public API of cboxdk/cms.
 */
#[Internal]
final readonly class PhpTypeCatalog implements Generator
{
    public const string CATALOG = 'GeneratedTypeCatalog';

    public const string PROVIDER = 'GeneratedTypesServiceProvider';

    public const string VALIDATORS = 'GeneratedTypeValidators';

    /**
     * What the catalog holds for each core field type of the blueprint schema v1: its name as the
     * field's fieldType. A field type without a mapping here is refused as invalid output. The
     * generator-coverage test holds the keys to the field types of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array FIELD_TYPES = [
        'boolean' => 'boolean',
        'date' => 'date',
        'datetime' => 'datetime',
        'decimal' => 'decimal',
        'group' => 'group',
        'integer' => 'integer',
        'long_text' => 'long_text',
        'rich_text' => 'rich_text',
        'select' => 'select',
        'text' => 'text',
    ];

    /**
     * What the catalog holds for a blueprint file of each kind. The generator-coverage test holds
     * the keys to the kinds of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array KINDS = [
        'extension' => 'its version in the extensions of the type it extends, and its fields among the type\'s fields under its namespace',
        'type' => 'a TypeDefinition with the type\'s id, name, version, capabilities and own fields, and a record factory binding',
    ];

    /**
     * @param  string  $serviceProvider  the class the generated service provider extends, Laravel's
     *                                   ServiceProvider, given by the generators' service provider
     *                                   because the domain does not use the framework
     */
    public function __construct(private string $serviceProvider) {}

    #[Override]
    public function directory(GenerationTarget $target): string
    {
        return $target->phpDirectory;
    }

    #[Override]
    public function generate(CompiledSchema $schema, GenerationTarget $target): array
    {
        PhpTypeHandleEnum::assertCaseNames($schema);

        $types = [];

        foreach ($schema->types as $type) {
            $types = [...$types, ...$this->type($type)];
        }

        return [
            PhpSource::file($target->phpDirectory.'/'.self::CATALOG.'.php', $target->phpNamespace, [
                ClassificationAccess::class,
                ColumnDefinition::class,
                ExtensionVersion::class,
                ...($this->hasContributedField($schema) ? [FieldBase::class] : []),
                FieldDefinition::class,
                FieldHandle::class,
                FieldNamespace::class,
                History::class,
                Localization::class,
                Override::class,
                Stages::class,
                TypeCapabilities::class,
                TypeCatalog::class,
                TypeDefinition::class,
                TypeId::class,
                TypeName::class,
            ], [
                '/**',
                ' * The types of the schema roots, as the kernel reads them at run time through the TypeCatalog',
                ' * contract (PRD 11.12, GUARDRAILS 2.4): each type\'s id, name, versions, capabilities and fields.',
                ' *',
                ...array_map(static fn (string $line): string => ' * '.$line, PhpSource::NOTICE),
                ' *',
                ' * Schema roots, by owner:',
                ...GeneratedLines::schemaRoots($target, ' *   '),
                ' */',
                'final readonly class '.self::CATALOG.' implements TypeCatalog',
                '{',
                '    /** @var list<TypeDefinition> */',
                '    private array $types;',
                '',
                '    public function __construct()',
                '    {',
                ...($types === [] ? ['        $this->types = [];'] : ['        $this->types = [', ...$types, '        ];']),
                '    }',
                '',
                '    #[Override]',
                '    public function all(): array',
                '    {',
                '        return $this->types;',
                '    }',
                '',
                '    #[Override]',
                '    public function find(TypeId $id): ?TypeDefinition',
                '    {',
                '        return array_find($this->types, static fn (TypeDefinition $type): bool => $type->id->equals($id));',
                '    }',
                '',
                '    #[Override]',
                '    public function named(TypeName $name): ?TypeDefinition',
                '    {',
                '        return array_find($this->types, static fn (TypeDefinition $type): bool => $type->name->equals($name));',
                '    }',
                '}',
            ]),
            $this->validators($schema, $target),
            $this->provider($schema, $target),
        ];
    }

    /**
     * The TypeValidators of the schema: the generated validator of every type, sorted by type id.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function validators(CompiledSchema $schema, GenerationTarget $target): GeneratedFile
    {
        $types = $schema->types;
        usort($types, static fn (TypeDescriptor $one, TypeDescriptor $other): int => strcmp($one->typeId->toString(), $other->typeId->toString()));
        $imports = [Override::class, TypeId::class, TypeValidator::class, TypeValidators::class];
        $validators = [];

        foreach ($types as $type) {
            $imports[] = $target->phpNamespace.'\\'.PhpTypeValidators::DIRECTORY.'\\'.PhpTypeValidators::className($type);
            $validators[] = '            new '.PhpTypeValidators::className($type).',';
        }

        return PhpSource::file($target->phpDirectory.'/'.self::VALIDATORS.'.php', $target->phpNamespace, $imports, [
            ...PhpSource::docblock([
                'The runtime validators of the schema roots\' types, by type (PRD 11.8, 11.12): the generated validator of every type of the TypeCatalog, which the kernel asks for the rules of a type through the TypeValidators contract.',
            ]),
            'final readonly class '.self::VALIDATORS.' implements TypeValidators',
            '{',
            '    /** @var list<TypeValidator> */',
            '    private array $validators;',
            '',
            '    public function __construct()',
            '    {',
            ...($validators === [] ? ['        $this->validators = [];'] : ['        $this->validators = [', ...$validators, '        ];']),
            '    }',
            '',
            '    #[Override]',
            '    public function all(): array',
            '    {',
            '        return $this->validators;',
            '    }',
            '',
            '    #[Override]',
            '    public function find(TypeId $id): ?TypeValidator',
            '    {',
            '        return array_find($this->validators, static fn (TypeValidator $validator): bool => $validator->type()->equals($id));',
            '    }',
            '}',
        ]);
    }

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function provider(CompiledSchema $schema, GenerationTarget $target): GeneratedFile
    {
        $imports = [$this->serviceProvider, TypeCatalog::class, TypeValidators::class, Override::class];
        $bindings = [];

        foreach ($schema->types as $type) {
            $namespace = PhpRecords::namespaceOf($type, $target);
            $imports[] = $namespace.'\\'.PhpRecords::factoryInterfaceOf($type);
            $imports[] = $namespace.'\\'.PhpRecords::factoryOf($type);
            $bindings[] = sprintf('        $this->app->singleton(%s::class, %s::class);', PhpRecords::factoryInterfaceOf($type), PhpRecords::factoryOf($type));
        }

        return PhpSource::file($target->phpDirectory.'/'.self::PROVIDER.'.php', $target->phpNamespace, $imports, [
            ...PhpSource::docblock([
                'Binds what the generated code gives the kernel and the owners\' code (PRD 11.12): the TypeCatalog contract to the generated catalog, the TypeValidators contract to the generated validators, and each type\'s record factory interface to the factory of its composite record, with every extender\'s fields. Registers the generated migrations of the type tables with the migrator; they run with migrate, never on their own. Register it once in the application.',
            ]),
            'final class '.self::PROVIDER.' extends '.PhpSource::shortName($this->serviceProvider),
            '{',
            '    #[Override]',
            '    public function register(): void',
            '    {',
            '        $this->app->singleton(TypeCatalog::class, '.self::CATALOG.'::class);',
            '        $this->app->singleton(TypeValidators::class, '.self::VALIDATORS.'::class);',
            ...$bindings,
            '    }',
            '',
            '    public function boot(): void',
            '    {',
            sprintf('        $this->loadMigrationsFrom(__DIR__.%s);', PhpSource::literal('/'.RelativePath::between('/'.$target->phpDirectory, '/'.$target->migrationsDirectory))),
            '    }',
            '}',
        ]);
    }

    /**
     * @return list<string>
     */
    private function type(TypeDescriptor $type): array
    {
        $capabilities = $type->capabilities;
        $extensions = array_map(
            static fn (ExtenderVersion $extension): string => sprintf(
                '                    new ExtensionVersion(new FieldNamespace(%s), %d),',
                PhpSource::literal($extension->namespace->value),
                $extension->version,
            ),
            $type->extensions,
        );
        $fields = [];

        foreach ($type->fields as $field) {
            $fields = [...$fields, ...$this->field($field, '                    ')];
        }

        return [
            '            new TypeDefinition(',
            sprintf('                TypeId::fromString(%s),', PhpSource::literal($type->typeId->value->value)),
            sprintf('                new TypeName(%s),', PhpSource::literal($type->name())),
            sprintf('                %d,', $type->version),
            sprintf(
                '                new TypeCapabilities(History::%s, Stages::%s, Localization::%s, %s),',
                $capabilities->history->name,
                $capabilities->stages->name,
                $capabilities->localization->name,
                $capabilities->routable ? 'true' : 'false',
            ),
            ...($extensions === [] ? ['                [],'] : ['                [', ...$extensions, '                ],']),
            '                [',
            ...$fields,
            '                ],',
            '            ),',
        ];
    }

    /**
     * @return list<string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function field(FieldDescriptor $field, string $indent): array
    {
        $fieldType = GeneratedLines::typeName(self::class, self::FIELD_TYPES, $field);
        $nested = [];

        foreach ($field->fields as $inner) {
            $nested = [...$nested, ...$this->field($inner, $indent.'        ')];
        }

        $flag = static fn (bool $value): string => $value ? 'true' : 'false';

        return [
            $indent.'new FieldDefinition(',
            $indent.'    namespace: '.($field->namespace instanceof Owner ? 'new FieldNamespace('.PhpSource::literal($field->namespace->value).')' : 'null').',',
            $indent.'    handle: new FieldHandle('.PhpSource::literal($field->handle->value).'),',
            $indent.'    fieldType: '.PhpSource::literal($fieldType).',',
            $indent.'    classification: ClassificationAccess::'.$this->classification($field->classification).',',
            $indent.'    agents: '.$flag($field->agents).',',
            $indent.'    encrypted: '.$flag($field->encrypted).',',
            $indent.'    required: '.$flag($field->required).',',
            $indent.'    filterable: '.$flag($field->filterable).',',
            $indent.'    sortable: '.$flag($field->sortable).',',
            $indent.'    column: '.$this->column($field->column).',',
            ...($nested === [] ? [] : [$indent.'    fields: [', ...$nested, $indent.'    ],']),
            ...($field->base === $field->type ? [] : [$indent.'    base: FieldBase::'.FieldBase::from($field->base)->name.',']),
            $indent.'),',
        ];
    }

    /**
     * Whether a field of the schema is of an addon's field type, whose definition names its base.
     */
    private function hasContributedField(CompiledSchema $schema): bool
    {
        return array_any($schema->types, fn (TypeDescriptor $type): bool => $this->anyContributed($type->fields));
    }

    /**
     * @param  list<FieldDescriptor>  $fields
     */
    private function anyContributed(array $fields): bool
    {
        return array_any($fields, fn (FieldDescriptor $field): bool => $field->base !== $field->type || $this->anyContributed($field->fields));
    }

    private function column(?ColumnDescriptor $column): string
    {
        if (! $column instanceof ColumnDescriptor) {
            return 'null';
        }

        return sprintf(
            'new ColumnDefinition(%s, %s, %s, [%s])',
            PhpSource::literal($column->name),
            PhpSource::literal($column->type),
            $column->notNull ? 'true' : 'false',
            implode(', ', array_map(PhpSource::literal(...), $column->checks)),
        );
    }

    private function classification(Classification $classification): string
    {
        return match ($classification) {
            Classification::Public => 'Public',
            Classification::Internal => 'Internal',
            Classification::Confidential => 'Confidential',
        };
    }
}
