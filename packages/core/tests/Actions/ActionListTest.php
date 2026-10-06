<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActionListCodecV1;
use Cbox\Cms\Core\Registry\Actions\ListActionsAction;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionList;
use Cbox\Cms\Core\Registry\Domain\Dto\DisabledContributions;
use Cbox\Cms\Core\Registry\Domain\Dto\ListedAction;
use Cbox\Cms\Core\Registry\Domain\Dto\ListedNavEntry;
use Cbox\Cms\Core\Registry\Domain\ListedActionKind;
use Cbox\Cms\Core\Registry\Domain\Queries\ListActions;
use Cbox\Cms\Core\Tests\Registry\ActionListWorld;

/*
 * action.list through the query pipeline with fakes (GUARDRAILS 8 and 9, PRD 13.2, 13.4): an actor
 * gets the actions exposed on Inertia it may run, a command only when a role of its whose
 * permissions name it reaches some node, a query every actor may run always, each with the title
 * and description of its JSON Schema; an action exposed on no surface or not on Inertia is never
 * listed, however the actor is placed, nor one whose contract no codec reads; the navigation
 * entries are those whose permission the actor holds and whose page it gets; and the anonymous
 * principal is refused as unauthorized.
 */

/**
 * The names and kinds of the actions listed, `name@version kind`.
 *
 * @return list<string>
 */
function listedActions(ActionList $list): array
{
    return array_map(static fn (ListedAction $action): string => sprintf('%s@%d %s', $action->name->value, $action->version, $action->kind->value), $list->actions);
}

/**
 * The ids of the navigation entries listed.
 *
 * @return list<string>
 */
function listedNavigation(ActionList $list): array
{
    return array_map(static fn (ListedNavEntry $entry): string => $entry->id->value, $list->navigation);
}

it('lists a command only when the actor holds its permission on some node, with the title and description of its schema', function (): void {
    $world = new ActionListWorld;
    $answer = $world->read(new ListActions, $world->reader(['role.create', 'grant.list']));
    $list = $answer->result;

    expect($list)->toBeInstanceOf(ActionList::class)
        ->and($list instanceof ActionList ? listedActions($list) : [])->toBe(['action.list@1 query', 'actor.me@1 query', 'role.create@1 command'])
        ->and($list instanceof ActionList ? $list->actions[2]->title : '')->toBe('role.create, contract version 1')
        ->and($list instanceof ActionList ? $list->actions[2]->description : '')->toStartWith('Creates a role (PRD 5.10, 6.4)')
        ->and($list instanceof ActionList ? $list->actions[0]->kind : null)->toBe(ListedActionKind::Query)
        ->and($list instanceof ActionList ? listedNavigation($list) : [])->toBe(['cms.account-me', 'cms.grants'])
        ->and($list instanceof ActionList ? $list->navigation[1]->icon : null)->toBe('shield')
        ->and($list instanceof ActionList ? $list->navigation[1]->label : null)->toBe('panel.nav.grants')
        ->and($list instanceof ActionList ? $list->navigation[1]->page->value : null)->toBe('access.grants')
        ->and($answer->contentKeys)->toBe([])
        ->and($world->access->resolved)->toHaveCount(1);
});

it('never lists an action exposed on no surface or not on Inertia, nor one whose contract no codec reads, however the actor is placed', function (): void {
    $world = new ActionListWorld;
    $list = $world->read(new ListActions, $world->reader(['access.bootstrap', 'actor.register', 'probe.add', 'entry.publish', 'actor.list']))->result;

    expect($list instanceof ActionList ? listedActions($list) : [])->toBe(['action.list@1 query', 'actor.list@1 query', 'actor.me@1 query', 'entry.publish@1 command']);
});

it('lists an actor without grants the queries every actor may run and the entries that require nothing', function (): void {
    $world = new ActionListWorld;
    $list = $world->read(new ListActions, $world->reader([]))->result;

    expect($list instanceof ActionList ? listedActions($list) : [])->toBe(['action.list@1 query', 'actor.me@1 query'])
        ->and($list instanceof ActionList ? listedNavigation($list) : [])->toBe(['cms.account-me']);
});

it('does not list a command the actor is only denied, nor one a grant limited to a locale reaches alone', function (): void {
    $denied = new ActionListWorld;
    $list = $denied->read(new ListActions, $denied->reader(['role.create'], GrantEffect::Deny))->result;

    expect($list instanceof ActionList ? listedActions($list) : [])->toBe(['action.list@1 query', 'actor.me@1 query']);

    $limited = new ActionListWorld;
    $actor = $limited->identity->addActor(ActorClass::Service)->id;
    $limited->grant($actor, ['role.create'], GrantEffect::Allow, ['da']);
    $limitedList = $limited->read(new ListActions, $limited->credential($actor))->result;

    expect($limitedList instanceof ActionList ? listedActions($limitedList) : [])->toBe(['action.list@1 query', 'actor.me@1 query', 'role.create@1 command']);
});

it('lists a nav entry of an addon s page only when the actor gets the page: enabled and its permission held', function (): void {
    $world = new ActionListWorld;
    $list = $world->read(new ListActions, $world->reader(['tally.board', 'tally.secret']))->result;

    expect($list instanceof ActionList ? listedNavigation($list) : [])->toBe(['cms.account-me', 'tally.board-link', 'tally.secret-link']);

    $board = new ActionListWorld;
    $boardList = $board->read(new ListActions, $board->reader(['tally.board']))->result;

    expect($boardList instanceof ActionList ? listedNavigation($boardList) : [])->toBe(['cms.account-me', 'tally.board-link']);
});

it('leaves out a nav entry, and the entries of a page, the installation disabled', function (): void {
    $world = new ActionListWorld;
    $world->activation->set(new DisabledContributions(contributions: [new ContributionId('cms.grants'), new ContributionId('tally.board')]));
    $list = $world->read(new ListActions, $world->reader(['grant.list', 'tally.board']))->result;

    expect($list instanceof ActionList ? listedNavigation($list) : [])->toBe(['cms.account-me']);
});

it('refuses the anonymous principal as unauthorized', function (): void {
    $answer = new ActionListWorld()->read(new ListActions, null);

    expect($answer->result)->toBeNull()
        ->and(array_map(static fn (CatalogError $error): ErrorCode => $error->code, $answer->errors))->toBe([ErrorCode::Unauthorized]);
});

it('costs one unit and writes the same document at every classification access', function (): void {
    $world = new ActionListWorld;
    $answer = $world->read(new ListActions, $world->reader(['role.create']));
    $list = $answer->result;
    $codec = new ActionListCodecV1;
    $document = $list instanceof ActionList ? $codec->encode($list, ClassificationAccess::Public) : '';

    expect($world->action()->cost(new ListActions)->units)->toBe(ListActionsAction::COST)
        ->and($document)->toContain('"actions":[{"description":"', '"kind":"command","name":"role.create","title":"role.create, contract version 1","version":1}', '"navigation":[{"icon":null,"id":"cms.account-me","label":"panel.nav.account_me","page":"account.me"}]')
        ->and($list instanceof ActionList ? $codec->encode($list, ClassificationAccess::Sensitive) : 'sensitive')->toBe($document)
        ->and($codec->decode($document, ClassificationAccess::Public))->toEqual($list);
});
