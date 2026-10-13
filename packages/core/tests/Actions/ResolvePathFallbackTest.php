<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldReader;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\FieldFallback;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Actions\ResolvePathAction;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\Dto\FallbackChain;
use Cbox\Cms\Core\Routing\Domain\Dto\FallbackStep;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\SiteHosts;
use Cbox\Cms\Core\Routing\Domain\SiteOrigin;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use Cbox\Cms\Core\Tests\Routing\Fakes\FakeRouteReader;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use DateTimeImmutable;
use LogicException;

/*
 * path.resolve's fallback with fakes (PRD 10.3, GUARDRAILS 9): the site alpen publishes in de-AT,
 * de, en and fr with the fallback chain de-AT, de, en, and an entry of a type with localization
 * variants is placed below its section at /news/hafen in each of them. A locale whose variant has
 * no released row shows the first locale after it in the chain that has one, whole; a field marked
 * fallback: field that the shown variant leaves empty takes its value from a later locale; and a
 * chain with no released row shows nothing. The fields are read in one statement whatever the
 * chain's length, and the explanation's fallback step says what was tried.
 */

const FALLBACK_SITE = '01936f5e-8a2b-7c3d-9e4f-000000003701';

const FALLBACK_ROOT = '01936f5e-8a2b-7c3d-9e4f-000000003711';

const FALLBACK_SECTION = '01936f5e-8a2b-7c3d-9e4f-000000003712';

const FALLBACK_TYPE = '01936f5e-8a2b-7c3d-9e4f-000000003721';

const FALLBACK_ENTRY = '01936f5e-8a2b-7c3d-9e4f-000000003731';

const FALLBACK_PLACEMENT = '01936f5e-8a2b-7c3d-9e4f-000000003741';

const FALLBACK_NOW = '2026-03-10T12:00:00Z';

/**
 * The type: a translatable title that falls back with its variant, a translatable teaser and an
 * extension's translatable summary that fall back on their own, and a shared channel.
 */
function fallbackType(): TypeDefinition
{
    $field = static fn (?string $namespace, string $handle, bool $translatable, FieldFallback $fallback): FieldDefinition => new FieldDefinition(
        $namespace === null ? null : new FieldNamespace($namespace),
        new FieldHandle($handle),
        'text',
        ClassificationAccess::Public,
        true,
        false,
        false,
        false,
        false,
        new ColumnDefinition($namespace === null ? $handle : 'ext__'.$namespace.'__'.$handle, 'text', false, []),
        translatable: $translatable,
        fallback: $fallback,
    );

    return new TypeDefinition(
        TypeId::fromString(FALLBACK_TYPE),
        new TypeName('app:notice'),
        1,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::Variants, true),
        [new ExtensionVersion(new FieldNamespace('reviews'), 1)],
        [
            $field(null, 'channel', false, FieldFallback::Variant),
            $field(null, 'teaser', true, FieldFallback::Field),
            $field(null, 'title', true, FieldFallback::Variant),
            $field('reviews', 'summary', true, FieldFallback::Field),
        ],
    );
}

/**
 * A released row: the shared channel, the title and teaser given (null leaves the teaser out) and
 * the extension's summary when given.
 */
function fallbackRow(string $title, ?string $teaser, ?string $summary = null): FieldValues
{
    $own = [new NamedValue(new FieldHandle('channel'), new TextValue('web')), new NamedValue(new FieldHandle('title'), new TextValue($title))];

    if ($teaser !== null) {
        $own[] = new NamedValue(new FieldHandle('teaser'), new TextValue($teaser));
    }

    $extensions = $summary === null ? [] : [new ExtensionFields(new FieldNamespace('reviews'), new FieldMap(new NamedValue(new FieldHandle('summary'), new TextValue($summary))))];

    return new FieldValues(new FieldMap(...$own), ...$extensions);
}

/**
 * The reader with the site, its section's route in each locale, the placement live in each, and
 * the released rows by locale.
 *
 * @param  array<string, FieldValues>  $rows
 */
function fallbackReader(array $rows): FakeRouteReader
{
    $site = new SiteHandle('alpen');
    $locales = [new Locale('de-AT'), new Locale('de'), new Locale('en'), new Locale('fr')];
    $section = NodeId::fromString(FALLBACK_SECTION);
    $reader = new FakeRouteReader()
        ->withSite($site, SiteId::fromString(FALLBACK_SITE), $locales)
        ->withNode(NodeId::fromString(FALLBACK_ROOT), NodeKind::Site, $site)
        ->withNode($section, NodeKind::Section, $site);

    // The placement shows from an hour before now on, in every locale.
    foreach ($locales as $locale) {
        $reader->withRoute($site, $locale, '/news', $section)->withPlacement($section, $locale, new Slug('hafen'), new PlacementMatch(
            PlacementId::fromString(FALLBACK_PLACEMENT),
            EntryId::fromString(FALLBACK_ENTRY),
            Visibility::Live,
            new TimeWindow(new DateTimeImmutable(FALLBACK_NOW)->modify('-1 hour')),
            true,
            TypeId::fromString(FALLBACK_TYPE),
            EntryLifecycle::Active,
            ReleaseState::Released,
        ));
    }

    foreach ($rows as $locale => $fields) {
        $reader->withReleased(TypeId::fromString(FALLBACK_TYPE), EntryId::fromString(FALLBACK_ENTRY), $fields, new Locale($locale));
    }

    return $reader;
}

function fallbackResolve(FakeRouteReader $reader, string $locale): ResolvedPath
{
    $sites = new SiteHosts([new ConfiguredSite(
        new SiteHandle('alpen'),
        new SiteOrigin('https://alpen.example'),
        [new Locale('de-AT'), new Locale('de'), new Locale('en'), new Locale('fr')],
        fallback: new FallbackChain([new Locale('de-AT'), new Locale('de'), new Locale('en')]),
    )]);
    $action = new ResolvePathAction($reader, $sites, new FakeTypeCatalog(fallbackType()), new FakeClock(new DateTimeImmutable(FALLBACK_NOW)));

    return $action->handle(new ResolvePath(new Host('alpen.example'), new Locale($locale), new RequestPath('/news/hafen')));
}

/**
 * @return list<string>
 */
function fallbackTags(FallbackStep $step): array
{
    return array_map(static fn (Locale $locale): string => $locale->value, $step->tried);
}

it('shows the locale\'s own variant when it is released, with a fallback step that fell back to nothing', function (): void {
    $result = fallbackResolve(fallbackReader(['de-AT' => fallbackRow('Hafen Wien', 'Kurz', 'Gut'), 'de' => fallbackRow('Hafen', 'Lang', 'Besser')]), 'de-AT');
    $step = $result->explanation->fallback;
    $fields = $result->content?->fields;

    expect($result->outcome())->toBe(ResolveOutcome::Resolved)
        ->and($step)->toBeInstanceOf(FallbackStep::class)
        ->and($step?->resolved?->value)->toBe('de-AT')
        ->and($step?->fallback)->toBeFalse()
        ->and($step?->fields)->toBe([])
        ->and($step instanceof FallbackStep ? fallbackTags($step) : null)->toBe(['de-AT', 'de', 'en'])
        ->and($fields instanceof FieldValues ? FieldReader::of($fields->own)->text('teaser') : null)->toBe('Kurz');
});

it('shows the next locale of the chain whole when the locale\'s variant has no release, and says it fell back', function (): void {
    $result = fallbackResolve(fallbackReader(['de' => fallbackRow('Hafen', 'Lang'), 'en' => fallbackRow('Harbour', 'Long')]), 'de-AT');
    $fields = $result->content->fields ?? throw new LogicException('A resolved path holds its fields.');
    $read = FieldReader::of($fields->own);

    expect($result->outcome())->toBe(ResolveOutcome::Resolved)
        ->and($result->explanation->fallback?->resolved?->value)->toBe('de')
        ->and($result->explanation->fallback?->fallback)->toBeTrue()
        ->and($result->explanation->fallback?->fields)->toBe([])
        ->and([$read->text('title'), $read->text('teaser'), $read->text('channel')])->toBe(['Hafen', 'Lang', 'web']);
});

it('takes a field marked fallback: field that the shown variant leaves empty from a later locale, the owner\'s and an extension\'s, and lists them', function (?string $teaser): void {
    $deAt = fallbackRow('Hafen Wien', $teaser);
    $result = fallbackResolve(fallbackReader(['de-AT' => $deAt, 'de' => fallbackRow('Hafen', null), 'en' => fallbackRow('Harbour', 'Long', 'Fine')]), 'de-AT');
    $fields = $result->content->fields ?? throw new LogicException('A resolved path holds its fields.');
    $read = FieldReader::of($fields->own);
    $summary = $fields->extension(new FieldNamespace('reviews'));

    expect($result->explanation->fallback?->resolved?->value)->toBe('de-AT')
        ->and($result->explanation->fallback?->fallback)->toBeFalse()
        ->and($result->explanation->fallback?->fields)->toEqual([FieldPath::fromString('ext.reviews.summary'), FieldPath::fromString('teaser')])
        ->and([$read->text('title'), $read->text('teaser')])->toBe(['Hafen Wien', 'Long'])
        ->and($summary instanceof FieldMap ? FieldReader::of($summary)->text('summary') : null)->toBe('Fine');
})->with([
    'absent' => [null],
    'an empty text' => [''],
]);

it('counts null and an empty list as empty, keeps a field no later locale fills as it is, and never takes a title, which falls back with its variant', function (): void {
    $deAt = new FieldValues(new FieldMap(
        new NamedValue(new FieldHandle('channel'), new TextValue('web')),
        new NamedValue(new FieldHandle('teaser'), new NullValue),
        new NamedValue(new FieldHandle('title'), new TextValue('')),
    ));
    $de = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('teaser'), new ListValue), new NamedValue(new FieldHandle('title'), new TextValue('Hafen'))));
    $result = fallbackResolve(fallbackReader(['de-AT' => $deAt, 'de' => $de]), 'de-AT');
    $fields = $result->content->fields ?? throw new LogicException('A resolved path holds its fields.');

    expect($result->explanation->fallback?->fields)->toBe([])
        ->and($fields->own->get(new FieldHandle('teaser')))->toEqual(new NullValue)
        ->and($fields->own->get(new FieldHandle('title')))->toEqual(new TextValue(''));
});

it('shows nothing when no locale of the chain has a released variant, with the entry for its content keys and no fields', function (): void {
    $reader = fallbackReader(['fr' => fallbackRow('Port', 'Court')]);
    $result = fallbackResolve($reader, 'de-AT');

    expect($result->outcome())->toBe(ResolveOutcome::NotVisible)
        ->and($result->explanation->visibility?->decision)->toBe(VisibilityDecision::NoReleasedRevision)
        ->and($result->explanation->visibility?->validUntil)->toBeNull()
        ->and($result->explanation->canonical)->toBeNull()
        ->and($result->explanation->fallback?->resolved)->toBeNull()
        ->and($result->explanation->fallback?->fallback)->toBeFalse()
        ->and($result->content)->toBeInstanceOf(ReadContent::class)
        ->and($result->content?->fields)->toEqual(new FieldValues);
});

it('tries a locale outside the chain alone, and the last locale of the chain alone', function (string $locale): void {
    $result = fallbackResolve(fallbackReader(['de' => fallbackRow('Hafen', 'Lang')]), $locale);
    $step = $result->explanation->fallback ?? throw new LogicException('A variants type is explained with its fallback.');

    expect($result->outcome())->toBe(ResolveOutcome::NotVisible)
        ->and(fallbackTags($step))->toBe([$locale]);
})->with(['fr', 'en']);

it('reads the released rows of the whole chain in one read, so the cost stays the same', function (): void {
    $reader = fallbackReader(['en' => fallbackRow('Harbour', 'Long')]);
    $result = fallbackResolve($reader, 'de-AT');

    expect($result->outcome())->toBe(ResolveOutcome::Resolved)
        ->and($result->explanation->fallback?->resolved?->value)->toBe('en')
        ->and($reader->reads)->toBeLessThanOrEqual(ResolvePathAction::COST);
});
