<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ViewSubject;

/*
 * Whether a contribution's Scope fits what a panel page is about (PRD 13.4): each list it narrows
 * by must name the page's command, type or one of its field types; a page about none of them fits
 * no scope that narrows by them. Pages and the required permission are decided by
 * ResolveContributions.
 */

it('fits a scope by the page s command, type and field types', function (Scope $scope, ViewSubject $subject, bool $fits): void {
    expect($subject->fits($scope))->toBe($fits);
})->with([
    'no narrowing' => [new Scope, new ViewSubject, true],
    'pages and requires only' => [new Scope([new PageName('desk.overview')], requires: new CommandName('tally.audit')), new ViewSubject, true],
    'the command' => [new Scope(commands: [new CommandRef(new CommandName('entry.create'), 1)]), new ViewSubject(new CommandRef(new CommandName('entry.create'), 1)), true],
    'another version' => [new Scope(commands: [new CommandRef(new CommandName('entry.create'), 1)]), new ViewSubject(new CommandRef(new CommandName('entry.create'), 2)), false],
    'no command' => [new Scope(commands: [new CommandRef(new CommandName('entry.create'), 1)]), new ViewSubject, false],
    'the type' => [new Scope(types: [new TypeName('app:article')]), new ViewSubject(type: new TypeName('app:article')), true],
    'another type' => [new Scope(types: [new TypeName('app:article')]), new ViewSubject(type: new TypeName('app:page')), false],
    'no type' => [new Scope(types: [new TypeName('app:article')]), new ViewSubject, false],
    'a field type' => [new Scope(fieldTypes: ['acme:stars', 'text']), new ViewSubject(fieldTypes: ['text', 'text']), true],
    'no field type' => [new Scope(fieldTypes: ['acme:stars']), new ViewSubject(fieldTypes: ['text']), false],
]);

it('keeps each field type once', function (): void {
    expect(new ViewSubject(fieldTypes: ['text', 'date', 'text'])->fieldTypes)->toBe(['text', 'date']);
});
