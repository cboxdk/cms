<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres\WalkingSkeleton;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Tests\Support\Phpstan;
use PHPUnit\Framework\Assert;
use Workbench\App\Cms\Generated\Boundary\AppFixtureArticleCodecV1;
use Workbench\App\Cms\Generated\Records\AppFixtureArticle\AppFixtureArticle;
use Workbench\FixtureAddon\DeriveSlug;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;
use Workbench\FixtureAddon\RequireSlugOnRelease;

/*
 * MILESTONES M1 point 7 (PRD 6.3, 11.12, 13.1 to 13.3, invariant 36): the workbench's fixture
 * addon, cboxdk/cms-fixture-addon in the namespace fixtureaddon, through the real command pipeline
 * on Postgres with the hooks of the compiled registry, which cms:build compiled from the addon's
 * scan root and manifest.
 *
 * - Its transform hook DeriveSlug changes the plan of entry.create: it derives the extension field
 *   ext.fixtureaddon.fixture_slug from the owner's title, and the type table stores it in its own
 *   column, beside the owner's fixture_slug of version 2 of app:fixture_article, which it never
 *   touches.
 * - Its validate hook RequireSlugOnRelease rejects a variant.release of a revision without the
 *   field, and only the release: the entry is created and revised without it (invariant 36).
 * - The field exists under the namespace everywhere generation puts it: the column
 *   ext__fixtureaddon__fixture_slug, the record's ext->fixtureaddon, the DTO's and the TypeScript
 *   record's ext.fixtureaddon, each beside the owner's fixture_slug.
 */

const ADDON_NAMESPACE = 'fixtureaddon';

afterEach(function (): void {
    EntryWorld::cleanUp();
});

function addonWorld(): EntryWorld
{
    return new EntryWorld(hooks: app(CommandHooks::class));
}

/**
 * An article with the owner's fields, the owner's slug when given, and the addon's slug when given.
 */
function addonArticle(string $title, ?string $slug = null, ?string $ownSlug = null): FieldValues
{
    $fields = EntryFields::article($title, more: $ownSlug === null ? [] : ['fixture_slug' => new TextValue($ownSlug)]);

    return $slug === null
        ? $fields
        : new FieldValues($fields->own, new ExtensionFields(new FieldNamespace(ADDON_NAMESPACE), new FieldMap(new NamedValue(new FieldHandle('fixture_slug'), new TextValue($slug)))));
}

/**
 * An article whose owner's fields have no title.
 */
function untitledArticle(): FieldValues
{
    return new FieldValues(new FieldMap(...array_values(array_filter(
        EntryFields::article()->own->fields,
        static fn (NamedValue $field): bool => $field->handle->value !== 'fixture_title',
    ))));
}

/**
 * The two slugs of the entry's row of the stage in the type table: the owner's and the addon's.
 *
 * @return array{owner: mixed, addon: mixed}|null
 */
function articleSlugs(string $stage): ?array
{
    $row = StorageTables::superuser()->table('app__fixture_article')
        ->where('cms_entry_id', EntryWorld::ENTRY)
        ->where('cms_stage', $stage)
        ->first(['fixture_slug', 'ext__fixtureaddon__fixture_slug']);

    return $row === null ? null : ['owner' => $row->fixture_slug, 'addon' => $row->ext__fixtureaddon__fixture_slug];
}

/**
 * @return list<string>
 */
function addonErrors(WriteResult $result): array
{
    return array_map(
        static fn (CatalogError $error): string => $error->code->value.($error->path instanceof FieldPath ? ' at '.$error->path->toString() : ''),
        $result->errors,
    );
}

it('compiles the fixture addon\'s hooks from its scan root and manifest', function (): void {
    $hooks = array_values(array_filter(
        app(CompiledRegistry::class)->hooks,
        static fn (HookEntry $entry): bool => $entry->package === FixtureAddonServiceProvider::PACKAGE,
    ));

    expect(array_map(static fn (HookEntry $hook): string => $hook->command->value.' '.$hook->phase->value.' '.$hook->class, $hooks))->toEqualCanonicalizing([
        'entry.create transform '.DeriveSlug::class,
        'variant.release validate '.RequireSlugOnRelease::class,
    ]);

    foreach ($hooks as $hook) {
        expect($hook->addon?->value)->toBe(ADDON_NAMESPACE)
            ->and($hook->reads)->toBe(ClassificationAccess::Public);
    }
});

it('changes the plan of entry.create with the transform hook, and stores the addon\'s slug beside the owner\'s', function (): void {
    EntryWorld::seed();
    $world = addonWorld();
    $type = EntryWorld::type(EntryWorld::ARTICLE);

    $dryRun = $world->pipeline()->run(new CommandCall(
        new CreateEntry(EntryWorld::entry(), $type->id, EntryWorld::home(), addonArticle('A quiet week, mostly', ownSlug: 'owners-own')),
        Envelope::external(IssuingSurface::Rest, IssuerKind::Human, $world->actor, new IdempotencyKey('addon-dry-run'), new CorrelationId('addon'), dryRun: true),
        $world->access(),
    ));
    $planned = array_find($dryRun->dryRun?->plan->mutations() ?? [], static fn (Mutation $mutation): bool => $mutation instanceof RevisionCreated);

    Assert::assertInstanceOf(RevisionCreated::class, $planned);

    expect($dryRun->outcome())->toBe(Outcome::DryRun)
        ->and($planned->fields->extension(new FieldNamespace(ADDON_NAMESPACE))?->get(new FieldHandle('fixture_slug')))->toEqual(new TextValue('a-quiet-week-mostly'))
        ->and($planned->fields->own->get(new FieldHandle('fixture_slug')))->toEqual(new TextValue('owners-own'));

    $created = $world->create($type->id, addonArticle('A quiet week, mostly', ownSlug: 'owners-own'), 'addon-create');

    expect($created->outcome())->toBe(Outcome::Committed)
        ->and(articleSlugs('draft'))->toBe(['owner' => 'owners-own', 'addon' => 'a-quiet-week-mostly']);
});

it('keeps a slug the caller sets, and derives none without a title', function (): void {
    EntryWorld::seed();
    $world = addonWorld();
    $type = EntryWorld::type(EntryWorld::ARTICLE);

    expect($world->create($type->id, addonArticle('A quiet week', slug: 'chosen'), 'addon-chosen')->outcome())->toBe(Outcome::Committed)
        ->and(articleSlugs('draft'))->toBe(['owner' => null, 'addon' => 'chosen'])
        ->and($world->revise(1, untitledArticle(), 'addon-untitled')->outcome())->toBe(Outcome::Committed)
        ->and(articleSlugs('draft'))->toBe(['owner' => null, 'addon' => null]);
});

it('rejects a release without the addon\'s field with the validate hook, and only the release', function (): void {
    EntryWorld::seed();
    $world = addonWorld();
    $type = EntryWorld::type(EntryWorld::ARTICLE);

    $created = $world->create($type->id, untitledArticle(), 'addon-no-slug');
    $before = EntryWorld::rows();
    $refused = $world->release(1, 1, 'addon-release-refused');

    expect($created->outcome())->toBe(Outcome::Committed)
        ->and($refused->outcome())->toBe(Outcome::Rejected)
        ->and(addonErrors($refused))->toBe(['validation_failed', 'validation_hook_failed at fields.ext.fixtureaddon.fixture_slug'])
        ->and($refused->errors[1]->message)->toBe('Revision 1 has no slug; save it with ext.fixtureaddon.fixture_slug before it is released.')
        ->and(EntryWorld::rows())->toBe($before)
        ->and(articleSlugs('released'))->toBeNull();

    $revised = $world->revise(1, addonArticle('A quiet week', slug: 'a-quiet-week', ownSlug: 'owners-own'), 'addon-revise');
    $released = $world->release(2, 2, 'addon-release');

    expect($revised->outcome())->toBe(Outcome::Committed)
        ->and($released->outcome())->toBe(Outcome::Committed)
        ->and(articleSlugs('released'))->toBe(['owner' => 'owners-own', 'addon' => 'a-quiet-week']);
});

it('puts the addon\'s field under its namespace in the type table, the record, the DTO and TypeScript, beside the owner\'s field of the same handle', function (): void {
    $columns = StorageTables::superuser()->table('information_schema.columns')
        ->where('table_name', 'app__fixture_article')
        ->whereIn('column_name', ['fixture_slug', 'ext__fixtureaddon__fixture_slug'])
        ->orderBy('column_name')
        ->pluck('data_type', 'column_name')
        ->all();

    $type = EntryWorld::type(EntryWorld::ARTICLE);
    $record = AppFixtureArticle::fromFieldValues(new FieldValues(
        new FieldMap(new NamedValue(new FieldHandle('fixture_featured'), new BooleanValue(true)), new NamedValue(new FieldHandle('fixture_slug'), new TextValue('owners-own'))),
        new ExtensionFields(new FieldNamespace(ADDON_NAMESPACE), new FieldMap(new NamedValue(new FieldHandle('fixture_slug'), new TextValue('addons-own')))),
    ));
    $codec = app(AppFixtureArticleCodecV1::class);
    $json = '{"cms_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","ext":{"fixtureaddon":{"fixture_slug":"addons-own"}},"fixture_featured":true,"fixture_slug":"owners-own"}';
    $dto = $codec->decode($json, ClassificationAccess::Public);
    $typescript = (string) file_get_contents(Phpstan::root().'/workbench/resources/js/cms/generated/records/AppFixtureArticleV1.ts');

    expect($columns)->toBe(['ext__fixtureaddon__fixture_slug' => 'text', 'fixture_slug' => 'text'])
        ->and($type->field(null, new FieldHandle('fixture_slug')))->toBeInstanceOf(FieldDefinition::class)
        ->and($type->field(new FieldNamespace(ADDON_NAMESPACE), new FieldHandle('fixture_slug'))?->address())->toBe('ext.fixtureaddon.fixture_slug')
        ->and($record->fixtureSlug)->toBe('owners-own')
        ->and($record->ext->fixtureaddon->fixtureSlug)->toBe('addons-own')
        ->and($dto->fixtureSlug)->toBe('owners-own')
        ->and($dto->ext->fixtureaddon->fixtureSlug)->toBe('addons-own')
        ->and($codec->encode($dto, ClassificationAccess::Public))->toBe($json)
        ->and($typescript)->toMatch('/export interface AppFixtureArticleV1ExtFixtureaddon \{[^}]*fixture_slug\?: string \| null;/s')
        ->and($typescript)->toMatch('/export interface AppFixtureArticleV1Ext \{[^}]*fixtureaddon: AppFixtureArticleV1ExtFixtureaddon;/s')
        ->and($typescript)->toMatch('/export interface AppFixtureArticleV1 \{.*\n  fixture_slug\?: string \| null;/s');
});
