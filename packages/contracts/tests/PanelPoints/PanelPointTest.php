<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Panel;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\Ownership;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\PointName;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Contracts\PanelPoints\ReplacementKey;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Contracts\Schema\TypeName;

/*
 * The panel's extension points as #[PanelPoint] declares them (PRD 13.4): the forms of a point's
 * name, page and id, the rules that tie its kind to a region, a multiplicity, an ownership and
 * tightening props, and the scope a contribution narrows itself to.
 */

it('reads and writes a point id as <name>@<version>', function (): void {
    $id = PointId::fromString('grants.list.row-actions@2');

    expect($id->name->value)->toBe('grants.list.row-actions')
        ->and($id->version)->toBe(2)
        ->and($id->toString())->toBe('grants.list.row-actions@2')
        ->and($id->equals(new PointId(new PointName('grants.list.row-actions'), 2)))->toBeTrue()
        ->and($id->equals(new PointId(new PointName('grants.list.row-actions'), 1)))->toBeFalse();
});

it('refuses a point id, name or page that does not have its form', function (callable $make, string $message): void {
    expect($make)->toThrow(InvalidPanelPoint::class, $message);
})->with([
    'an id without a version' => [static fn (): PointId => PointId::fromString('account.me.sections'), '"account.me.sections" is not a panel point id'],
    'an id of version 0' => [static fn (): PointId => PointId::fromString('account.me.sections@0'), '"account.me.sections@0" is not a panel point id'],
    'a version below 1' => [static fn (): PointId => new PointId(new PointName('account.me.sections'), 0), 'Versions start at 1'],
    'a name of one segment' => [static fn (): PointName => new PointName('sections'), 'The panel point name "sections" is not'],
    'a name in capitals' => [static fn (): PointName => new PointName('Account.me'), 'The panel point name "Account.me" is not'],
    'a name with a double hyphen' => [static fn (): PointName => new PointName('grants.list.row--actions'), 'is not at least two'],
    'a name of 65 characters' => [static fn (): PointName => new PointName('a.'.str_repeat('b', 63)), 'of at most 64 characters'],
    'a page with an underscore' => [static fn (): PageName => new PageName('account_me'), 'The panel page name "account_me" is not'],
]);

it('takes a page of one segment', function (): void {
    expect(new PageName('shell')->value)->toBe('shell')
        ->and(new PageName('account.me')->equals(new PageName('account.me')))->toBeTrue();
});

it('declares a slot in its region, which decides whether its fills render markup', function (): void {
    $point = new PanelPoint('account.me.sections', 1, PointKind::Slot, 'account.me', '1.0', 'panel.points.account_me_sections', Region::Sections);

    expect($point->id()->toString())->toBe('account.me.sections@1')
        ->and($point->pageName()->value)->toBe('account.me')
        ->and($point->multiplicity)->toBe(Multiplicity::Many)
        ->and($point->tightens)->toBe([])
        ->and(Region::Sections->takesMarkup())->toBeTrue()
        ->and(Region::Aside->takesMarkup())->toBeTrue()
        ->and(Region::Toolbar->takesMarkup())->toBeFalse()
        ->and(Region::Columns->takesMarkup())->toBeFalse()
        ->and(Region::Tabs->takesMarkup())->toBeFalse();
});

it('declares a bounded slot, a decorator with its tightening props and a replacement with its ownership', function (): void {
    $toolbar = new PanelPoint('shell.header.end', 1, PointKind::Slot, 'shell', '1.0', 'panel.points.shell_header_end', Region::Toolbar, Multiplicity::Max, 3);
    $submit = new PanelPoint('command.form.submit', 1, PointKind::Decorator, 'command.form', '1.0', 'panel.points.command_form_submit', tightens: [Tighten::DisabledReason, Tighten::Description, Tighten::ToneTowardsDanger]);
    $field = new PanelPoint('command.form.field', 1, PointKind::Replacement, 'command.form', '1.0', 'panel.points.command_form_field', multiplicity: Multiplicity::Exclusive, ownership: Ownership::Own, keyedBy: ReplacementKey::FieldType);

    expect($toolbar->max)->toBe(3)
        ->and($submit->tightens)->toBe([Tighten::DisabledReason, Tighten::Description, Tighten::ToneTowardsDanger])
        ->and($field->ownership)->toBe(Ownership::Own)
        ->and($field->keyedBy)->toBe(ReplacementKey::FieldType);
});

it('refuses a declaration whose parts do not fit together', function (callable $declare, string $message): void {
    expect($declare)->toThrow(InvalidPanelPoint::class, $message);
})->with([
    'a slot without a region' => [static fn (): PanelPoint => new PanelPoint('shell.banner', 1, PointKind::Slot, 'shell', '1.0', 'panel.points.shell_banner'), 'The slot shell.banner@1 has no region'],
    'an action with a region' => [static fn (): PanelPoint => new PanelPoint('shell.user-menu', 1, PointKind::Action, 'shell', '1.0', 'panel.points.user_menu', Region::Sections), 'is of kind action and has the region sections. Only a slot has a region'],
    'max without Multiplicity::Max' => [static fn (): PanelPoint => new PanelPoint('shell.banner', 1, PointKind::Slot, 'shell', '1.0', 'panel.points.shell_banner', Region::Sections, max: 1), 'gives max 1, and its multiplicity is many'],
    'Multiplicity::Max without max' => [static fn (): PanelPoint => new PanelPoint('shell.banner', 1, PointKind::Slot, 'shell', '1.0', 'panel.points.shell_banner', Region::Sections, Multiplicity::Max), 'renders at most max contributions and gives no max'],
    'a max of 0' => [static fn (): PanelPoint => new PanelPoint('shell.banner', 1, PointKind::Slot, 'shell', '1.0', 'panel.points.shell_banner', Region::Sections, Multiplicity::Max, 0), 'renders at most 0 contributions'],
    'an exclusive slot' => [static fn (): PanelPoint => new PanelPoint('shell.banner', 1, PointKind::Slot, 'shell', '1.0', 'panel.points.shell_banner', Region::Sections, Multiplicity::Exclusive), 'Only a replacement is exclusive'],
    'a replacement that renders many' => [static fn (): PanelPoint => new PanelPoint('command.form.field', 1, PointKind::Replacement, 'command.form', '1.0', 'panel.points.field', ownership: Ownership::Own, keyedBy: ReplacementKey::FieldType), 'Exactly one replacement wins'],
    'a replacement without its key' => [static fn (): PanelPoint => new PanelPoint('command.form.field', 1, PointKind::Replacement, 'command.form', '1.0', 'panel.points.field', multiplicity: Multiplicity::Exclusive, ownership: Ownership::Own), 'needs both ownership and keyedBy'],
    'ownership on a slot' => [static fn (): PanelPoint => new PanelPoint('shell.banner', 1, PointKind::Slot, 'shell', '1.0', 'panel.points.shell_banner', Region::Sections, ownership: Ownership::Any), 'Only a replacement has them'],
    'tightening props on an action' => [static fn (): PanelPoint => new PanelPoint('shell.user-menu', 1, PointKind::Action, 'shell', '1.0', 'panel.points.user_menu', tightens: [Tighten::Description]), 'Only a decorator tightens'],
    'a tightening prop twice' => [static fn (): PanelPoint => new PanelPoint('command.form.submit', 1, PointKind::Decorator, 'command.form', '1.0', 'panel.points.submit', tightens: [Tighten::Description, Tighten::Description]), 'lists description twice'],
    'a release that is not <major>.<minor>' => [static fn (): PanelPoint => new PanelPoint('shell.nav', 1, PointKind::Nav, 'shell', 'v1', 'panel.points.shell_nav'), 'says it arrived in "v1"'],
    'a label that is not a translation key' => [static fn (): PanelPoint => new PanelPoint('shell.nav', 1, PointKind::Nav, 'shell', '1.0', 'Navigation'), '"Navigation", is not a translation key'],
    'a page that is not a page name' => [static fn (): PanelPoint => new PanelPoint('shell.nav', 1, PointKind::Nav, 'Shell', '1.0', 'panel.points.shell_nav'), 'The panel page name "Shell" is not'],
    'a version below 1' => [static fn (): PanelPoint => new PanelPoint('shell.nav', 0, PointKind::Nav, 'shell', '1.0', 'panel.points.shell_nav'), 'Versions start at 1'],
]);

it('names a contribution by its addon\'s namespace and a local id', function (): void {
    $id = new ContributionId('fixtureaddon.slug-hint');

    expect($id->namespace())->toEqual(new AddonNamespace('fixtureaddon'))
        ->and(new ContributionId('cms.profile')->namespace()->value)->toBe('cms')
        ->and(static fn (): ContributionId => new ContributionId('approvals'))->toThrow(InvalidPanelPoint::class, 'The contribution id "approvals" is not')
        ->and(static fn (): ContributionId => new ContributionId('Approvals.badge'))->toThrow(InvalidPanelPoint::class, 'The contribution id "Approvals.badge" is not');
});

it('reads and writes a command and version as <name>@<version>', function (): void {
    expect(CommandRef::fromString('grant.assign@1')->toString())->toBe('grant.assign@1')
        ->and(CommandRef::fromString('grant.assign@1')->name)->toEqual(new CommandName('grant.assign'))
        ->and(static fn (): CommandRef => CommandRef::fromString('grant.assign'))->toThrow(InvalidPanelPoint::class, '"grant.assign" is not a command and version')
        ->and(static fn (): CommandRef => CommandRef::fromString('Grant@1'))->toThrow(InvalidPanelPoint::class, 'A command name is dot-separated snake_case segments')
        ->and(static fn (): CommandRef => new CommandRef(new CommandName('grant.assign'), 0))->toThrow(InvalidPanelPoint::class, 'Versions start at 1');
});

it('keeps a scope\'s lists sorted, so two scopes that say the same are equal, and says whether it narrows', function (): void {
    $scope = new Scope(
        [new PageName('roles.list'), new PageName('account.me')],
        [new CommandRef(new CommandName('role.create'), 1), new CommandRef(new CommandName('grant.assign'), 1)],
        [new TypeName('app:note'), new TypeName('acme:card')],
        ['text', 'acme:stars'],
        new CommandName('grant.list'),
    );

    expect(array_map(static fn (PageName $page): string => $page->value, $scope->pages))->toBe(['account.me', 'roles.list'])
        ->and(array_map(static fn (CommandRef $command): string => $command->toString(), $scope->commands))->toBe(['grant.assign@1', 'role.create@1'])
        ->and(array_map(static fn (TypeName $type): string => $type->value, $scope->types))->toBe(['acme:card', 'app:note'])
        ->and($scope->fieldTypes)->toBe(['acme:stars', 'text'])
        ->and($scope->narrows())->toBeTrue()
        ->and(new Scope(requires: new CommandName('grant.list'))->narrows())->toBeTrue()
        ->and(Scope::everywhere()->narrows())->toBeFalse()
        ->and(new Scope([new PageName('b'), new PageName('a')]))->toEqual(new Scope([new PageName('a'), new PageName('b')]));
});

it('refuses a scope that names a value twice or a field type that is not one', function (callable $make, string $message): void {
    expect($make)->toThrow(InvalidPanelPoint::class, $message);
})->with([
    'a page twice' => [static fn (): Scope => new Scope([new PageName('shell'), new PageName('shell')]), 'The scope names the page "shell" twice'],
    'a command twice' => [static fn (): Scope => new Scope(commands: [CommandRef::fromString('a.b@1'), CommandRef::fromString('a.b@1')]), 'The scope names the command "a.b@1" twice'],
    'a type twice' => [static fn (): Scope => new Scope(types: [new TypeName('app:note'), new TypeName('app:note')]), 'The scope names the type "app:note" twice'],
    'a field type twice' => [static fn (): Scope => new Scope(fieldTypes: ['text', 'text']), 'The scope names the field type "text" twice'],
    'a field type that is not one' => [static fn (): Scope => new Scope(fieldTypes: ['Rich Text']), 'The scope names the field type "Rich Text", which is not'],
]);
