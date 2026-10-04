<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Actions;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointName;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Core\Registry\Domain\Dto\ContributionOverride;
use Cbox\Cms\Core\Registry\Domain\Dto\DisabledContributions;
use Cbox\Cms\Core\Registry\Domain\PointDowncastRefused;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Panel\Contributions\Actions\ResolveContributions;
use Cbox\Cms\Panel\Contributions\Domain\ContributionTelemetry;
use Cbox\Cms\Panel\Contributions\Domain\CoreContributions;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveContributions;
use Cbox\Cms\Panel\Contributions\Domain\Dto\AddonRegistration;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Contributions\Domain\Dto\RenderedPoint;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ViewSubject;
use Cbox\Cms\Panel\Contributions\Domain\Registrations;
use Cbox\Cms\Panel\Contributions\Domain\Withheld;
use Cbox\Cms\Panel\Shell\Domain\Dto\ViewerSummaryV1;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk\DeskAsideV1;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk\DeskCardsV1;
use Cbox\Cms\Panel\Tests\Contributions\ResolveWorld;

/*
 * ResolveContributions (PRD 13.4) called directly with ContributionWorld's registry and fakes of
 * its ports (GUARDRAILS 9): per page and viewer it lists the contributions to the points the page
 * renders that are enabled and in scope and whose required permission the viewer holds, in render
 * order, each handed the point's props at the lower of the viewer's access and the addon's reads;
 * the shell's points among them on every page, where an action needs its command's permission, a
 * nav entry a page the viewer gets, and a page's data runs only on the page itself; it withholds
 * everything, and says so in telemetry, when the registry or the activation state cannot be read,
 * and reads nothing for a page that renders no point.
 */

/**
 * The reasons of the withheld counters, in the order they were recorded.
 *
 * @return list<string|int|float|bool|null>
 */
function withheldReasons(ResolveWorld $world): array
{
    return array_values(array_map(
        static fn (CounterRecord $counter): string|int|float|bool|null => $counter->attributes->get(ContributionTelemetry::REASON),
        array_filter($world->telemetry->counters(), static fn (CounterRecord $counter): bool => $counter->name->value === ContributionTelemetry::WITHHELD),
    ));
}

it('lists the contributions to the points the page renders in render order, at the lower of the viewer s access and the addon s reads', function (): void {
    $world = new ResolveWorld;
    $active = $world->resolve(ResolveWorld::AUDITOR, aside: true);

    expect(ResolveWorld::listed($active))->toBe([
        'desk.cards@1' => [ContributionWorld::AUDIT, ContributionWorld::COUNT, ContributionWorld::HEAVY],
        'desk.aside@1' => [ContributionWorld::ASIDE],
    ])
        ->and($active->page->value)->toBe(ContributionWorld::PAGE)
        ->and($active->points[0]->fills[0]->access)->toBe(ClassificationAccess::Internal)
        ->and($active->points[0]->fills[0]->props)->toBeInstanceOf(DeskCardsV1::class)
        ->and($active->points[1]->fills[0]->props)->toBeInstanceOf(DeskAsideV1::class)
        ->and(array_keys($active->withData()))->toBe(['tally'])
        ->and(array_map(static fn ($fill): string => $fill->fill->contribution->value, $active->withData()['tally']))->toBe([ContributionWorld::COUNT, ContributionWorld::HEAVY]);
});

it('hands a contribution the viewer s access when the addon reads more', function (): void {
    $world = new ResolveWorld(ContributionWorld::registry(ContributionWorld::manifest(reads: ClassificationAccess::Sensitive)));

    expect($world->resolve(ResolveWorld::VIEWER)->points[0]->fills[0]->access)->toBe(ClassificationAccess::Confidential);
});

it('never lists a contribution whose required permission the viewer does not hold, and asks for the permissions once', function (): void {
    $world = new ResolveWorld;
    $active = $world->resolve(ResolveWorld::VIEWER);

    expect(ResolveWorld::listed($active))->toBe(['desk.cards@1' => [ContributionWorld::COUNT, ContributionWorld::HEAVY]])
        ->and($world->permissions->asked)->toHaveCount(1)
        ->and(array_map(static fn (CommandName $name): string => $name->value, $world->permissions->asked[0][1]))->toBe([ContributionWorld::AUDIT_PERMISSION]);
});

it('resolves the shell s points on every page: the nav entry, the page and the action a viewer who holds their permissions gets, and the page s data only on the page itself', function (): void {
    $world = new ResolveWorld;
    $home = $world->resolveShell(ResolveWorld::AUDITOR);

    expect(ResolveWorld::listed($home))->toBe([
        'desk.cards@1' => [ContributionWorld::AUDIT, ContributionWorld::COUNT, ContributionWorld::HEAVY],
        'shell.nav@1' => [ContributionWorld::BOARD_LINK],
        'shell.page@1' => [ContributionWorld::BOARD],
        'shell.user-menu@1' => [ContributionWorld::ADD],
    ])
        ->and($home->page(new ContributionId(ContributionWorld::BOARD))?->data())->toBeNull()
        ->and(array_map(static fn ($fill): string => $fill->fill->contribution->value, $home->withData()['tally']))->toBe([ContributionWorld::COUNT, ContributionWorld::HEAVY])
        ->and($home->points[3]->fills[0]->props)->toBeInstanceOf(ViewerSummaryV1::class);

    $board = $world->resolveShell(ResolveWorld::AUDITOR, ContributionWorld::BOARD);

    expect($board->page(new ContributionId(ContributionWorld::BOARD))?->data()?->toString())->toBe('tally.board@1')
        ->and(array_map(static fn ($fill): string => $fill->fill->contribution->value, $board->withData()['tally']))->toBe([ContributionWorld::BOARD])
        ->and(ResolveWorld::listed($board))->toHaveKey('shell.nav@1');
});

it('hides an action whose command the viewer may not run, a page whose permission the viewer lacks, and the nav entry that leads to it', function (): void {
    $world = new ResolveWorld;
    $active = $world->resolveShell(ResolveWorld::VIEWER);

    expect(ResolveWorld::listed($active))->toBe(['desk.cards@1' => [ContributionWorld::COUNT, ContributionWorld::HEAVY]])
        ->and($active->page(new ContributionId(ContributionWorld::BOARD)))->toBeNull()
        ->and($active->pages())->toBe([])
        ->and(array_map(static fn (CommandName $name): string => $name->value, $world->permissions->asked[0][1]))->toBe([ContributionWorld::AUDIT_PERMISSION, ContributionWorld::BOARD_PERMISSION, ContributionWorld::ADD_PERMISSION]);
});

it('keeps the core s nav entry to one of the panel s own pages for a viewer who holds no permission at all', function (): void {
    $world = new ResolveWorld(ContributionWorld::registry(core: CoreContributions::all()));
    $active = $world->resolveShell(ResolveWorld::VIEWER);
    $nav = $active->points[1]->fills[0] ?? null;

    expect(ResolveWorld::listed($active))->toBe(['desk.cards@1' => [ContributionWorld::COUNT, ContributionWorld::HEAVY], 'shell.nav@1' => [CoreContributions::ACCOUNT_ME_NAV]])
        ->and($nav?->fill->declaration)->toBeInstanceOf(NavContribution::class)
        ->and($nav?->fill->declaration instanceof NavContribution ? $nav->fill->declaration->page : null)->toBe(OwnPage::AccountMe->value)
        ->and($nav?->access)->toBe(ClassificationAccess::Confidential);
});

it('hides the nav entry to a page the activation state disables, although the viewer holds the page s permission', function (): void {
    $world = new ResolveWorld;
    $world->activation->set(new DisabledContributions(contributions: [new ContributionId(ContributionWorld::BOARD)]));

    expect(array_keys(ResolveWorld::listed($world->resolveShell(ResolveWorld::AUDITOR))))->toBe(['desk.cards@1', 'shell.user-menu@1']);
});

it('lists only the points the page renders', function (): void {
    expect(array_keys(ResolveWorld::listed(new ResolveWorld()->resolve(ResolveWorld::AUDITOR))))->toBe(['desk.cards@1']);
});

it('leaves out what the activation state disables, a whole addon or one contribution, at the next request', function (): void {
    $world = new ResolveWorld;
    $world->activation->set(new DisabledContributions(contributions: [new ContributionId(ContributionWorld::COUNT)]));

    expect(ResolveWorld::listed($world->resolve(ResolveWorld::AUDITOR)))->toBe(['desk.cards@1' => [ContributionWorld::AUDIT, ContributionWorld::HEAVY]]);

    $world->activation->set(new DisabledContributions([new AddonNamespace('tally')]));

    expect($world->resolve(ResolveWorld::AUDITOR)->points)->toBe([]);
});

it('keeps the order and the enabled state the installation compiled', function (): void {
    $world = new ResolveWorld(ContributionWorld::registry(overrides: [
        new ContributionOverride(new PointId(new PointName('desk.cards'), 1), new ContributionId(ContributionWorld::HEAVY), priority: 5),
        new ContributionOverride(new PointId(new PointName('desk.cards'), 1), new ContributionId(ContributionWorld::AUDIT), enabled: false),
    ]));

    expect(ResolveWorld::listed($world->resolve(ResolveWorld::AUDITOR)))->toBe(['desk.cards@1' => [ContributionWorld::HEAVY, ContributionWorld::COUNT]]);
});

it('lists a contribution only on the pages and for the commands, types and field types its scope names', function (Scope $scope, ViewSubject $subject, bool $listed): void {
    $contributions = [new SlotFill(new ContributionId('tally.scoped'), 'desk.cards@1', scope: $scope)];
    $world = new ResolveWorld(ContributionWorld::registry(ContributionWorld::manifest($contributions)));

    expect(ResolveWorld::listed($world->resolve(ResolveWorld::AUDITOR, subject: $subject)) === ['desk.cards@1' => ['tally.scoped']])->toBe($listed);
})->with([
    'every page' => [new Scope, new ViewSubject, true],
    'its page' => [new Scope([new PageName(ContributionWorld::PAGE)]), new ViewSubject, true],
    'another page' => [new Scope([new PageName('desk.archive')]), new ViewSubject, false],
    'a field type on the page' => [new Scope(fieldTypes: ['text']), new ViewSubject(fieldTypes: ['date', 'text']), true],
    'a field type not on the page' => [new Scope(fieldTypes: ['text']), new ViewSubject(fieldTypes: ['date']), false],
]);

it('withholds every contribution and says why when the registry or the activation state cannot be read', function (): void {
    $malformed = new ResolveWorld;
    $malformed->cache->damage('broken');
    $missing = new ResolveWorld;
    $broken = new ResolveWorld;
    $broken->activation->breakWith('cbox-cms.panel.disabled is not a map');
    $view = new PanelView(new PageName(ContributionWorld::PAGE), ResolveWorld::principal(ResolveWorld::AUDITOR), [new RenderedPoint(new PointName('desk.cards'), new DeskCardsV1('n', 'm'))]);

    expect($malformed->resolve(ResolveWorld::AUDITOR)->points)->toBe([])
        ->and(withheldReasons($malformed))->toBe([Withheld::RegistryMalformed->value])
        ->and(new ResolveContributions(new FakeRegistryCache, $missing->activation, $missing->permissions, new ContributionTelemetry($missing->telemetry))->resolve($view)->points)->toBe([])
        ->and(withheldReasons($missing))->toBe([Withheld::RegistryMissing->value])
        ->and($broken->resolve(ResolveWorld::AUDITOR)->points)->toBe([])
        ->and(withheldReasons($broken))->toBe([Withheld::ActivationInvalid->value])
        ->and($malformed->permissions->asked)->toBe([]);
});

it('reads nothing for a page that renders no point', function (): void {
    $world = new ResolveWorld;
    $world->cache->damage('never read');

    expect($world->action()->resolve(new PanelView(new PageName(ContributionWorld::PAGE), ResolveWorld::principal(ResolveWorld::AUDITOR), []))->points)->toBe([])
        ->and($world->permissions->asked)->toBe([])
        ->and($world->telemetry->counters())->toBe([]);
});

it('refuses props of a point that are not the newest version s, a bug of the page', function (): void {
    expect(fn (): ActiveContributions => new ResolveWorld()->resolve(ResolveWorld::AUDITOR, cards: new DeskAsideV1('wrong')))->toThrow(PointDowncastRefused::class);
});

it('hands the core s own contributions the viewer s access, and gives the host the registration of each addon whose code runs on the page', function (): void {
    $world = new ResolveWorld(ContributionWorld::registry(core: [new SlotFill(new ContributionId('cms.notes'), 'desk.cards@1', priority: 100)]));
    $active = $world->resolve(ResolveWorld::AUDITOR);
    $core = $active->points[0]->fills[3] ?? null;

    expect(ResolveWorld::listed($active))->toBe(['desk.cards@1' => [ContributionWorld::AUDIT, ContributionWorld::COUNT, ContributionWorld::HEAVY, 'cms.notes']])
        ->and($core?->access)->toBe(ClassificationAccess::Confidential)
        ->and($active->points[0]->fills[0]->access)->toBe(ClassificationAccess::Internal)
        ->and($active->points[0]->declaration->name)->toBe('desk.cards')
        ->and($active->details)->toBeTrue()
        ->and(array_map(static fn (AddonRegistration $registration): array => [$registration->addon->value, $registration->digest, $registration->anyCommand], $active->registrations))->toBe([
            ['cms', Registrations::digest(['cms.notes']), true],
            ['tally', Registrations::digest([ContributionWorld::ASIDE, ContributionWorld::AUDIT, ContributionWorld::BOARD, ContributionWorld::COUNT, ContributionWorld::HEAVY]), false],
        ]);
});
