<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Generators\Descriptor\Boundary\TypeDescriptorJson;
use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeCatalog;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeQueries;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Migrations\Boundary\TypeTableLockJson;
use Cbox\Cms\Generators\Migrations\Domain\MigrationSource;
use Cbox\Cms\Generators\Migrations\Domain\TableChanges;
use Cbox\Cms\Generators\Migrations\Domain\TypeTableDdl;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Illuminate\Support\ServiceProvider;
use LogicException;

/*
 * The attributes and sort keys of relations in the blueprints (PRD 5.11, B2-T75): a relation whose
 * targets carry attributes takes the form of a group of its entry and the attributes, its
 * filterable attribute has a partial index on the kernel's relations table, and a filterable
 * relation names its sort_by field, a sortable field of the owner that the same variant holds, or
 * cms:generate refuses it with generate_relation_sort_by_invalid at sort_by, exit 65. A filterable
 * relation gives the owner's query builder a filter by the target, with the filterable attribute,
 * and the order by its sort key.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/** The fields of the story: a sortable day, a translatable title, a byline with a role, and related stories. */
const STORY_FIELDS = <<<'YAML'
  - handle: published_on
    label: Published on
    description: The day the story was published.
    type: date
    classification: public
    sortable: true
  - handle: rank
    label: Rank
    description: The story's rank, not sortable.
    type: integer
    classification: public
  - handle: byline
    label: Byline
    description: The stories the story credits, each with the role it had.
    type: relation
    classification: public
    multiple: true
    max_items: 20
    types: [app:story]
    filterable: true
    sort_by: published_on
    meta:
      - handle: role
        label: Role
        description: What the credited story did.
        type: select
        required: true
        filterable: true
        options:
          - value: author
            label: Author
          - value: photo
            label: Photo
      - handle: credit
        label: Credit
        description: How the credit reads.
        type: text
  - handle: related
    label: Related
    description: The stories the story points to.
    type: relation
    classification: public
    multiple: true
    types: [app:story]
    filterable: true
    sort_by: published_on

YAML;

function storyType(string $fields = STORY_FIELDS, string $localization = 'none'): string
{
    return <<<YAML
        blueprint: 1
        kind: type
        type_id: 0192a3b4-c5d6-7e8f-9a0b-000000000075
        handle: story
        label: Story
        description: A type for the attributes of relations.
        version: 1
        capabilities:
          history: full
          stages: draft-release
          localization: {$localization}
        fields:
        {$fields}
        YAML;
}

/**
 * The roots with the app's files and, when given, acme's.
 *
 * @param  array<string, string>  $files
 * @param  array<string, string>  $extension
 * @return list<SchemaRoot>
 */
function storyRoots(array $files, array $extension = []): array
{
    $base = SchemaFixtures::scratch();
    $roots = [];

    foreach (['app' => $files, 'acme' => $extension] as $owner => $contents) {
        if ($contents === []) {
            continue;
        }

        $root = new SchemaRoot(new Owner($owner), $base, $owner);
        mkdir($root->path(), 0o775, true);

        foreach ($contents as $path => $text) {
            SchemaFixtures::write($root->path().'/'.$path, $text);
        }

        $roots[] = $root;
    }

    return $roots;
}

/**
 * @param  array<string, string>  $extension
 */
function storySchema(string $type, array $extension = []): CompiledSchema
{
    return DescriptorCompiler::compile(SchemaResolver::resolve(ComprehensiveExample::source()->read(storyRoots(['story.yaml' => $type], $extension))));
}

/**
 * The problems of reading the files, which must fail: each as its code and the place it names.
 *
 * @param  array<string, string>  $extension
 * @return list<string>
 */
function storyProblems(string $type, array $extension = []): array
{
    try {
        ComprehensiveExample::source()->read(storyRoots(['story.yaml' => $type], $extension));
    } catch (GenerationFailed $failed) {
        return array_map(static fn (GenerationProblem $problem): string => $problem->code->value.' '.strstr($problem->message, ': ', true), $failed->problems);
    }

    throw new LogicException('The blueprints were read.');
}

/**
 * The top-level descriptor field with the column.
 */
function storyField(CompiledSchema $schema, string $column): FieldDescriptor
{
    foreach ($schema->types[0]->fields as $field) {
        if ($field->column?->name === $column) {
            return $field;
        }
    }

    throw new LogicException('No field '.$column.'.');
}

it('compiles a relation whose targets carry attributes into the form of a group of its entry and the attributes', function (): void {
    $schema = storySchema(storyType());
    $byline = storyField($schema, 'byline');
    $related = storyField($schema, 'related');

    expect([$byline->type, $byline->base, $byline->relation?->meta, $byline->relation?->sortBy?->value])->toBe(['relation', 'group', true, 'published_on'])
        ->and(array_map(static fn (FieldDescriptor $field): array => [$field->handle->value, $field->base, $field->required, $field->filterable], $byline->fields))->toBe([
            ['credit', 'text', false, false],
            ['entry', 'relation', true, false],
            ['role', 'select', true, true],
        ])
        ->and(array_map(static fn (FieldDescriptor $field): string => $field->handle->value, $byline->relationMeta()))->toBe(['credit', 'role'])
        ->and($byline->column?->type)->toBe('jsonb')
        ->and($byline->php->doc)->toBe('list<array{credit?: string|null, entry: \Cbox\Cms\Contracts\Ids\EntryId, role: \'author\'|\'photo\'}>')
        ->and([$related->type, $related->base, $related->relation?->meta, $related->relation?->sortBy?->value])->toBe(['relation', 'relation', false, 'published_on'])
        ->and(TypeDescriptorJson::encode($schema->types[0]))->toContain("\"relation\": {\n                \"meta\": true,\n                \"sort_by\": \"published_on\"\n            },");
});

it('gives a filterable attribute a partial index on relations, and a filterable relation no index of its own column', function (): void {
    $lock = TableChanges::next(storySchema(storyType()), [])[0];
    $byline = $lock->column('byline');
    $related = $lock->column('related');
    $create = TypeTableDdl::create($lock);
    $down = MigrationSource::source($lock, 1);

    expect([$byline?->indexed, $byline?->relationIndexes, $related?->indexed, $related?->relationIndexes])->toBe([false, ['role'], false, []])
        ->and($create)->toContain("create index \"app__story__byline__role\" on relations (target_entry_id, filter_key, sort_key, source_entry_id)\n    where field = 'byline' and stage = 'released'")
        ->and(implode("\n", $create))->not->toContain('"app__story__related"')
        ->and(TypeTableDdl::add($lock, 1))->toContain('create index concurrently "app__story__byline__role" on relations (target_entry_id, filter_key, sort_key, source_entry_id)'."\n    where field = 'byline' and stage = 'released'")
        ->and(TypeTableDdl::dropRelationIndexes($lock, 1))->toBe(['drop index if exists "app__story__byline__role"'])
        ->and((int) strpos($down, 'drop index if exists "app__story__byline__role"'))->toBeLessThan((int) strpos($down, 'drop table "app__story"'))
        ->and(TypeTableLockJson::decode('database/migrations/cms/app__story.lock', TypeTableLockJson::encode($lock)))->toEqual($lock);
});

it('writes the relation definitions into the catalog and the relation filters into the owner\'s query builder', function (): void {
    $schema = storySchema(storyType());
    $files = [];

    foreach ([...new PhpTypeCatalog(ServiceProvider::class)->generate($schema, SchemaFixtures::target()), ...new PhpTypeQueries()->generate($schema, SchemaFixtures::target())] as $file) {
        $files[basename($file->path)] = $file->contents;
    }

    expect($files['GeneratedTypeCatalog.php'])->toContain("relation: new RelationDefinition(meta: true, sortBy: new FieldHandle('published_on')),")
        ->and($files['GeneratedTypeCatalog.php'])->toContain("relation: new RelationDefinition(meta: false, sortBy: new FieldHandle('published_on')),")
        ->and($files['AppStoryQuery.php'])->toContain('public function whereByline(EntryId $target, ?BylineRoleChoice $role = null): self')
        ->and($files['AppStoryQuery.php'])->toContain("\$attributes[] = new AttributeFilter(new FieldHandle('role'), new TextValue(\$role->value));")
        ->and($files['AppStoryQuery.php'])->toContain('public function orderByByline(SortDirection $direction = SortDirection::Descending): self')
        ->and($files['AppStoryQuery.php'])->toContain('return $this->orderBy(AppStorySortField::PublishedOn, $direction);')
        ->and($files['AppStoryQuery.php'])->toContain('public function whereRelated(EntryId $target): self')
        ->and($files['AppStoryFilterField.php'])->not->toContain('Byline')
        ->and($files['AppStoryFilterField.php'])->not->toContain('Related');
});

it('refuses a sort_by that names no field, a field that is not sortable, or a field another variant holds', function (): void {
    expect(storyProblems(storyType(str_replace('sort_by: published_on', 'sort_by: printed_on', STORY_FIELDS))))->toBe([
        'generate_relation_sort_by_invalid app/story.yaml, /fields/2/sort_by',
        'generate_relation_sort_by_invalid app/story.yaml, /fields/3/sort_by',
    ])
        ->and(storyProblems(storyType(preg_replace('/sort_by: published_on/', 'sort_by: rank', STORY_FIELDS, 1) ?? '')))->toBe(['generate_relation_sort_by_invalid app/story.yaml, /fields/2/sort_by'])
        ->and(storyProblems(storyType(str_replace("    type: relation\n    classification: public\n    multiple: true\n    types: [app:story]", "    type: relation\n    classification: public\n    translatable: true\n    multiple: true\n    types: [app:story]", STORY_FIELDS), 'variants')))->toBe(['generate_relation_sort_by_invalid app/story.yaml, /fields/3/sort_by']);

    $message = '';

    try {
        ComprehensiveExample::source()->read(storyRoots(['story.yaml' => storyType(preg_replace('/sort_by: published_on/', 'sort_by: rank', STORY_FIELDS, 1) ?? '')]));
    } catch (GenerationFailed $failed) {
        $message = $failed->problems[0]->message;
    }

    expect($message)->toContain('the relation byline sorts its edges by rank, but the field rank is not sortable')
        ->and(ErrorCode::from(GenerateErrorCode::RelationSortByInvalid->value)->entry()->exit->value)->toBe(65);
});

it('holds the sort_by of an extension\'s relation to the fields of the type it extends', function (): void {
    $extension = static fn (string $sortBy): string => <<<YAML
        blueprint: 1
        kind: extension
        extends: 0192a3b4-c5d6-7e8f-9a0b-000000000075
        version: 1
        fields:
          - handle: sources
            label: Sources
            description: The stories acme says the story draws on.
            type: relation
            classification: public
            multiple: true
            types: [app:story]
            filterable: true
            sort_by: {$sortBy}
        YAML;

    expect(storyField(storySchema(storyType(), ['story.yaml' => $extension('published_on')]), 'ext__acme__sources')->relation?->sortBy?->value)->toBe('published_on')
        ->and(storyProblems(storyType(), ['story.yaml' => $extension('rank')]))->toBe(['generate_relation_sort_by_invalid acme/story.yaml, /fields/0/sort_by']);
});

it('names an unknown type of a relation whose targets carry attributes once, at the relation\'s types', function (): void {
    expect(storyProblems(storyType(str_replace("    max_items: 20\n    types: [app:story]", "    max_items: 20\n    types: [app:person]", STORY_FIELDS))))->toBe([
        'generate_unknown_relation_type app/story.yaml, /fields/2/types/0',
    ]);
});
