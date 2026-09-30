<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Results;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Results\ReadContent;

/*
 * How a read ended (PRD 6.2): an answer carries its result, its content keys each once and sorted,
 * and its position, and no error; a rejection carries its errors and nothing else. A read entry
 * gives its content keys e-{entry} and n-{node}, and changes only its fields.
 */

const QUERY_ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000000e1';

const QUERY_NODE = '01936f5e-8a2b-7c3d-9e4f-0000000000a1';

it('answers with the result, the content keys each once and sorted, and the position', function (): void {
    $result = new readonly class implements Result {};
    $entry = DependencyKey::entry(EntryId::fromString(QUERY_ENTRY));
    $node = DependencyKey::node(NodeId::fromString(QUERY_NODE));

    $answered = QueryResult::answered($result, [$node, $entry, $node, DependencyKey::entry(EntryId::fromString(QUERY_ENTRY))], new CommitPosition('4827'));

    expect($answered->isAnswered())->toBeTrue()
        ->and($answered->result)->toBe($result)
        ->and(array_map(static fn (DependencyKey $key): string => $key->toString(), $answered->contentKeys))->toBe(['e-'.QUERY_ENTRY, 'n-'.QUERY_NODE])
        ->and($answered->position?->value)->toBe('4827')
        ->and($answered->errors)->toBe([]);
});

it('rejects with its errors in order and nothing else', function (): void {
    $first = new CatalogError(ErrorCode::Unauthorized, null, 'No role reads this.');
    $second = new CatalogError(ErrorCode::QueryOverBudget, null, 'The read costs too much.');

    $rejected = QueryResult::rejected($first, $second);

    expect($rejected->isAnswered())->toBeFalse()
        ->and($rejected->errors)->toBe([$first, $second])
        ->and($rejected->result)->toBeNull()
        ->and($rejected->contentKeys)->toBe([])
        ->and($rejected->position)->toBeNull()
        ->and($rejected->access)->toBe(ClassificationAccess::Public);
});

it('carries the classification access of the read\'s principal, which a surface writes the result with, and public unless it is given', function (): void {
    $result = new readonly class implements Result {};

    expect(QueryResult::answered($result, [], new CommitPosition('1'), ClassificationAccess::Confidential)->access)->toBe(ClassificationAccess::Confidential)
        ->and(QueryResult::answered($result, [], new CommitPosition('1'))->access)->toBe(ClassificationAccess::Public);
});

it('gives a read entry\'s content keys and keeps all but its fields when they change', function (): void {
    $content = new ReadContent(EntryId::fromString(QUERY_ENTRY), NodeId::fromString(QUERY_NODE), TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000d7'));
    $fields = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('label'), new TextValue('A'))));

    $changed = $content->withFields($fields);

    expect(array_map(static fn (DependencyKey $key): string => $key->toString(), $content->contentKeys()))->toBe(['e-'.QUERY_ENTRY, 'n-'.QUERY_NODE])
        ->and($content->fields->equals(new FieldValues))->toBeTrue()
        ->and($changed->fields)->toBe($fields)
        ->and($changed->entry)->toBe($content->entry)
        ->and($changed->node)->toBe($content->node)
        ->and($changed->type)->toBe($content->type);
});
