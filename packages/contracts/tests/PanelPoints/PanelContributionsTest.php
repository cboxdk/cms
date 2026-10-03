<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Panel;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\DecoratorContribution;
use Cbox\Cms\Contracts\PanelPoints\FlowStep;
use Cbox\Cms\Contracts\PanelPoints\FormCheck;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\LoginNotice;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\ObserverContribution;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointDeprecation;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\ProviderContribution;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Contracts\PanelPoints\ReplacementContribution;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Contracts\PanelPoints\Tighten;

/*
 * What an addon adds to the panel, as its manifest declares it (PRD 13.4): the panel API version
 * it needs, its bundle, the experimental points it accepts, and its contributions, each of a kind
 * with the values that kind holds. A value that breaks its rule refuses the manifest, which
 * cms:build reports as registry_invalid_manifest; what needs the registry is checked by cms:build.
 */

function contributionId(string $id = 'approvals.badge'): ContributionId
{
    return new ContributionId($id);
}

it('gives every kind of contribution its kind, whether it runs code, and the default priority', function (PanelContribution $contribution, PointKind $kind, bool $code): void {
    expect($contribution->kind())->toBe($kind)
        ->and($contribution->runsCode())->toBe($code)
        ->and($contribution->priority())->toBe(PanelContribution::DEFAULT_PRIORITY)
        ->and($contribution->scope()->narrows())->toBeFalse()
        ->and($contribution->id()->value)->toBe('approvals.badge')
        ->and($contribution->point())->toBe('notes.point@1');
})->with([
    'slot fill' => [new SlotFill(contributionId(), 'notes.point@1'), PointKind::Slot, true],
    'action' => [new ActionContribution(contributionId(), 'notes.point@1', 'Acme\Approvals\RequestApproval', 'approvals.request.label'), PointKind::Action, false],
    'nav entry' => [new NavContribution(contributionId(), 'notes.point@1', 'approvals.nav.queue', 'approvals.queue'), PointKind::Nav, false],
    'page' => [new PageContribution(contributionId(), 'notes.point@1', 'queue/pending'), PointKind::Page, true],
    'decorator' => [new DecoratorContribution(contributionId(), 'notes.point@1'), PointKind::Decorator, true],
    'replacement' => [new ReplacementContribution(contributionId(), 'notes.point@1', 'approvals:stars'), PointKind::Replacement, true],
    'form check' => [new FormCheck(contributionId(), 'notes.point@1', 'notes.draft@1', Severity::Info), PointKind::FormCheck, true],
    'flow step' => [new FlowStep(contributionId(), 'notes.point@1', 'notes.draft@1', StepPosition::AfterReceipt), PointKind::FlowStep, true],
    'observer' => [new ObserverContribution(contributionId(), 'notes.point@1'), PointKind::Observer, true],
    'provider' => [new ProviderContribution(contributionId(), 'notes.point@1'), PointKind::Provider, true],
    'login notice' => [new LoginNotice(contributionId(), 'notes.point@1', 'approvals.login.notice'), PointKind::Data, false],
]);

it('keeps an action\'s prefill sorted, a step\'s paths sorted and its timeout, and a class without its leading backslash', function (): void {
    $action = new ActionContribution(contributionId(), 'notes.point@1', '\Acme\Approvals\RequestApproval', 'approvals.request.label', 'key', ['reason' => '/note/title', 'actor' => '/actor']);
    $step = new FlowStep(contributionId(), 'notes.point@1', 'notes.draft@1', StepPosition::BeforeSubmit, ['fields.ext.approvals.reason', 'fields.ext.approvals.approver'], 12);
    $replacement = new ReplacementContribution(contributionId(), 'notes.point@1', '\Acme\Approvals\Reason');

    expect($action->command)->toBe('Acme\Approvals\RequestApproval')
        ->and($action->prefill)->toBe(['actor' => '/actor', 'reason' => '/note/title'])
        ->and($step->patches)->toBe(['fields.ext.approvals.approver', 'fields.ext.approvals.reason'])
        ->and($step->timeoutSeconds)->toBe(12)
        ->and($replacement->key)->toBe('Acme\Approvals\Reason');
});

it('refuses a value of a contribution that breaks its rule', function (callable $build, string $message): void {
    expect($build)->toThrow(InvalidAddonManifest::class, $message);
})->with([
    'a priority below 0' => [static fn (): SlotFill => new SlotFill(contributionId(), 'notes.point@1', priority: -1), 'has the priority -1'],
    'a priority above the highest' => [static fn (): SlotFill => new SlotFill(contributionId(), 'notes.point@1', priority: PanelContribution::MAX_PRIORITY + 1), 'Give a priority from 0 to 1000000'],
    'a label that is no translation key' => [static fn (): ActionContribution => new ActionContribution(contributionId(), 'notes.point@1', 'Acme\R', 'Request'), 'is not a translation key'],
    'an icon that is no kit icon' => [static fn (): NavContribution => new NavContribution(contributionId(), 'notes.point@1', 'approvals.nav', 'approvals.queue', 'Inbox'), 'is not the name of a kit icon'],
    'a command that is no class name' => [static fn (): ActionContribution => new ActionContribution(contributionId(), 'notes.point@1', 'approvals.request', 'approvals.request.label'), 'is not a PHP class name'],
    'a prefill of a property that is no property' => [static fn (): ActionContribution => new ActionContribution(contributionId(), 'notes.point@1', 'Acme\R', 'approvals.request.label', prefill: ['Actor' => '/actor']), 'which is not a property of a command document'],
    'a prefill from a pointer that is no pointer' => [static fn (): ActionContribution => new ActionContribution(contributionId(), 'notes.point@1', 'Acme\R', 'approvals.request.label', prefill: ['actor' => 'actor']), 'which is not a JSON pointer'],
    'a prefill from the whole document' => [static fn (): ActionContribution => new ActionContribution(contributionId(), 'notes.point@1', 'Acme\R', 'approvals.request.label', prefill: ['actor' => '']), 'which is not a JSON pointer'],
    'a page path with a slash at the end' => [static fn (): PageContribution => new PageContribution(contributionId(), 'notes.point@1', 'queue/'), 'which is not segments'],
    'a page path that climbs' => [static fn (): PageContribution => new PageContribution(contributionId(), 'notes.point@1', '../queue'), 'which is not segments'],
    'a data query that is no class name' => [static fn (): SlotFill => new SlotFill(contributionId(), 'notes.point@1', 'approvals.pending'), 'is not a PHP class name'],
    'a prop tightened twice' => [static fn (): DecoratorContribution => new DecoratorContribution(contributionId(), 'notes.point@1', [Tighten::Description, Tighten::Description]), 'tightens description twice'],
    'a replacement key with a space' => [static fn (): ReplacementContribution => new ReplacementContribution(contributionId(), 'notes.point@1', 'approvals stars'), 'which is not a key'],
    'an empty replacement key' => [static fn (): ReplacementContribution => new ReplacementContribution(contributionId(), 'notes.point@1', ''), 'which is not a key'],
    'a step path that is no path' => [static fn (): FlowStep => new FlowStep(contributionId(), 'notes.point@1', 'notes.draft@1', StepPosition::BeforeSubmit, ['fields..reason']), 'which is not a path of a command document'],
    'a step path given twice' => [static fn (): FlowStep => new FlowStep(contributionId(), 'notes.point@1', 'notes.draft@1', StepPosition::BeforeSubmit, ['note', 'note']), 'lists the path note twice'],
    'a step without time' => [static fn (): FlowStep => new FlowStep(contributionId(), 'notes.point@1', 'notes.draft@1', StepPosition::BeforeSubmit, timeoutSeconds: 0), 'has a timeout of 0 seconds'],
    'a step over 30 seconds' => [static fn (): FlowStep => new FlowStep(contributionId(), 'notes.point@1', 'notes.draft@1', StepPosition::BeforeSubmit, timeoutSeconds: 31), 'Give 1 to 30 seconds'],
    'a notice that is no translation key' => [static fn (): LoginNotice => new LoginNotice(contributionId(), 'notes.point@1', 'Maintenance tonight'), 'is not a translation key'],
]);

it('holds the panel contributions to an absolute bundle and each accepted point once, sorted', function (): void {
    $panel = new PanelContributions(new PanelApiVersion(1, 0), '/srv/addons/approvals/dist', ['notes.b@1', 'notes.a@2'], [new SlotFill(contributionId(), 'notes.a@2')]);

    expect($panel->acceptsExperimental)->toBe(['notes.a@2', 'notes.b@1'])
        ->and($panel->accepts(PointId::fromString('notes.a@2')))->toBeTrue()
        ->and($panel->accepts(PointId::fromString('notes.a@1')))->toBeFalse()
        ->and($panel->bundle)->toBe('/srv/addons/approvals/dist')
        ->and(new PanelContributions(PanelApiVersion::current())->bundle)->toBeNull()
        ->and(static fn (): PanelContributions => new PanelContributions(PanelApiVersion::current(), 'dist/panel'))->toThrow(InvalidAddonManifest::class, 'is not an absolute path')
        ->and(static fn (): PanelContributions => new PanelContributions(PanelApiVersion::current(), null, ['notes.a@1', 'notes.a@1']))->toThrow(InvalidAddonManifest::class, 'accept the experimental point "notes.a@1" 2 times');
});

it('reads a panel API version as ^major.minor', function (): void {
    $needs = new PanelApiVersion(1, 2);

    expect($needs->constraint())->toBe('^1.2')
        ->and($needs->satisfiedBy(new PanelApiVersion(1, 3)))->toBeTrue()
        ->and($needs->satisfiedBy(new PanelApiVersion(1, 1)))->toBeFalse()
        ->and($needs->satisfiedBy(new PanelApiVersion(2, 9)))->toBeFalse()
        ->and(PanelApiVersion::current()->toString())->toBe(PanelApiVersion::CURRENT_MAJOR.'.'.PanelApiVersion::CURRENT_MINOR)
        ->and(static fn (): PanelApiVersion => new PanelApiVersion(-1, 0))->toThrow(InvalidAddonManifest::class);
});

it('holds the commands an addon\'s panel UI issues each once, sorted, and compares them without case', function (): void {
    $capabilities = new AddonCapabilities(ClassificationAccess::Internal, ['\Acme\Approvals\RequestApproval', 'Acme\Approvals\ArchiveApproval'], true);

    expect($capabilities->issues)->toBe(['Acme\Approvals\ArchiveApproval', 'Acme\Approvals\RequestApproval'])
        ->and($capabilities->uiTheme)->toBeTrue()
        ->and($capabilities->mayIssue('acme\approvals\requestapproval'))->toBeTrue()
        ->and($capabilities->mayIssue('Acme\Approvals\Other'))->toBeFalse()
        ->and(new AddonCapabilities()->issues)->toBe([])
        ->and(new AddonCapabilities()->uiTheme)->toBeFalse()
        ->and(static fn (): AddonCapabilities => new AddonCapabilities(issues: ['Acme\R', 'acme\r']))->toThrow(InvalidAddonManifest::class, 'is listed twice in the issues')
        ->and(static fn (): AddonCapabilities => new AddonCapabilities(issues: ['approvals.request']))->toThrow(InvalidAddonManifest::class, 'is not a PHP class name');
});

it('declares a deprecated point with the release it goes in and its replacement', function (): void {
    $point = new PanelPoint('notes.legacy', 1, PointKind::Slot, 'notes.detail', '1.0', 'fixture.points.legacy', Region::Aside, deprecated: new PointDeprecation('1.2', '2.0', 'notes.detail.sections@1'));

    expect($point->deprecated?->replacementId()?->toString())->toBe('notes.detail.sections@1')
        ->and(new PointDeprecation('1.2', '1.3')->replacementId())->toBeNull()
        ->and(static fn (): PointDeprecation => new PointDeprecation('1.2', '1.2'))->toThrow(InvalidPanelPoint::class, 'which is not after 1.2')
        ->and(static fn (): PointDeprecation => new PointDeprecation('1.10', '1.9'))->toThrow(InvalidPanelPoint::class, 'which is not after 1.10')
        ->and(static fn (): PointDeprecation => new PointDeprecation('v1', '2.0'))->toThrow(InvalidPanelPoint::class, 'is not a release of the panel API')
        ->and(static fn (): PointDeprecation => new PointDeprecation('1.0', '2.0', 'notes.detail'))->toThrow(InvalidPanelPoint::class, 'is not a panel point id')
        ->and(static fn (): PanelPoint => new PanelPoint('notes.legacy', 1, PointKind::Slot, 'notes.detail', '1.0', 'fixture.points.legacy', Region::Aside, deprecated: new PointDeprecation('1.0', '2.0', 'notes.legacy@1')))->toThrow(InvalidPanelPoint::class, 'names itself as its replacement');
});
