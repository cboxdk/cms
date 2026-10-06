<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActionListCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ListActionsCodecV1;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionList;
use Cbox\Cms\Core\Registry\Domain\Dto\ListedAction;
use Cbox\Cms\Core\Registry\Domain\Dto\ListedNavEntry;
use Cbox\Cms\Core\Registry\Domain\ListedActionKind;
use Cbox\Cms\Core\Registry\Domain\Queries\ListActions;

// The command palette is built from action.list, a query every actor may run without a permission
// (ActorQuery): the actions of the compiled registry exposed on Inertia whose permission the actor
// holds on some node, each with its kind and the title and description of its JSON Schema, and the
// navigation entries the actor may open. The result is the same document on REST and in the
// panel's prop `palette`, written by the generated codec whatever the reader's classification
// access, because it holds no content: names, kinds and texts of the registry.

const EXAMPLE_LIST = '{"actions":[{"description":"Creates a role (PRD 5.10, 6.4) in one changeset.","kind":"command","name":"role.create","title":"role.create, contract version 1","version":1}],'
    .'"navigation":[{"icon":null,"id":"cms.account-me","label":"panel.nav.account_me","page":"account.me"}]}';

it('lists actions without input, as a query every actor may run', function (): void {
    $query = new ListActionsCodecV1()->decode('{}', ClassificationAccess::Public);

    expect($query)->toBeInstanceOf(ListActions::class)
        ->and($query)->toBeInstanceOf(ActorQuery::class)
        ->and(ListedActionKind::of(ActionKind::Write))->toBe(ListedActionKind::Command)
        ->and(ListedActionKind::of(ActionKind::Query))->toBe(ListedActionKind::Query);
});

it('writes the actions and the navigation entries the same at every access', function (): void {
    $codec = new ActionListCodecV1;
    $list = $codec->decode(EXAMPLE_LIST, ClassificationAccess::Public);

    $action = $list->actions[0] ?? null;
    $entry = $list->navigation[0] ?? null;

    expect($list)->toBeInstanceOf(ActionList::class)
        ->and($action)->toBeInstanceOf(ListedAction::class)
        ->and($action instanceof ListedAction ? $action->name->value : null)->toBe('role.create')
        ->and($action instanceof ListedAction ? $action->kind : null)->toBe(ListedActionKind::Command)
        ->and($entry)->toBeInstanceOf(ListedNavEntry::class)
        ->and($entry instanceof ListedNavEntry ? $entry->page->value : null)->toBe('account.me')
        ->and($codec->encode($list, ClassificationAccess::Public))->toBe(EXAMPLE_LIST)
        ->and($codec->encode($list, ClassificationAccess::Sensitive))->toBe(EXAMPLE_LIST);
});
