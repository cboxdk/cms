<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Editors\EditorField;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Pipeline\DescribesTypes;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Codecs\Boundary\Generated\TypeListCodecV1;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\InvalidQueryCall;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Schema\Actions\DescribeTypeAction;
use Cbox\Cms\Core\Schema\Actions\ListTypesAction;
use Cbox\Cms\Core\Schema\Domain\Dto\TypeDescription;
use Cbox\Cms\Core\Schema\Domain\Dto\TypeList;
use Cbox\Cms\Core\Schema\Domain\Queries\DescribeType;
use Cbox\Cms\Core\Schema\Domain\Queries\ListTypes;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryTransaction;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeReadAudit;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Schema\TypeEditorsWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Editors\SampleEditors;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Override;

/*
 * type.list and type.describe through the query pipeline with fakes (GUARDRAILS 9, PRD 11.12,
 * 14.1, 12.2): every actor gets the descriptors, each without the fields above its classification
 * access and, for an agent, without the fields the blueprint closes to agents, the fields below a
 * group only with the group; the anonymous principal is refused; a type the installation does not
 * have is null; a result that keeps a field the pipeline left out is refused; and each costs one.
 */

/**
 * @return list<string>
 */
function typePaths(TypeEditor $editor): array
{
    return array_map(static fn (EditorField $field): string => $field->path, $editor->fields);
}

/**
 * @return list<TypeEditor>
 */
function listedTypes(QueryResult $result): array
{
    expect($result->result)->toBeInstanceOf(TypeList::class);

    return $result->result instanceof TypeList ? $result->result->types : [];
}

function describedType(QueryResult $result): ?TypeEditor
{
    expect($result->result)->toBeInstanceOf(TypeDescription::class);

    return $result->result instanceof TypeDescription ? $result->result->type : null;
}

it('lists every type sorted by name for an actor, with every field its classification access allows', function (): void {
    $world = new TypeEditorsWorld(3);
    $answer = $world->read(new ListTypes, $world->reader(ClassificationAccess::Sensitive));
    $types = listedTypes($answer);

    expect(array_map(static fn (TypeEditor $type): string => $type->name->value, $types))->toBe(['sample:card_1', 'sample:card_2', 'sample:card_3'])
        ->and(array_map(typePaths(...), $types))->toBe(array_fill(0, 3, SampleEditors::PATHS))
        ->and($answer->contentKeys)->toBe([])
        ->and($answer->access)->toBe(ClassificationAccess::Sensitive)
        ->and(new TypeListCodecV1()->encode($answer->result instanceof TypeList ? $answer->result : new TypeList([]), $answer->access))
        ->toContain('"name":"sample:card_1"', '"title_field":"label"', '"path":"ext.probe.tag"', '"rule":{"kind":"text","maxLength":40}');
});

it('leaves out of each descriptor the fields above the reader\'s classification access, and the fields below a group it does not see', function (): void {
    $world = new TypeEditorsWorld(2);
    $public = $world->read(new ListTypes, $world->reader(ClassificationAccess::Public));
    $internal = $world->read(new DescribeType(new TypeName('sample:card_2')), $world->reader(ClassificationAccess::Internal));

    expect(array_map(typePaths(...), listedTypes($public)))->toBe([SampleEditors::PUBLIC_PATHS, SampleEditors::PUBLIC_PATHS])
        ->and(typePaths(describedType($internal) ?? SampleEditors::type(1)))->toBe(['label', 'note', 'mood', 'extras', 'extras.flag', 'extras.count', 'sizes', 'sizes.size', 'ext.probe.tag'])
        ->and(describedType($internal)?->titleField)->toBe('label');
});

it('shows an agent only the fields the blueprint opens to agents, never a personal one, and keeps the title field only while it is seen', function (): void {
    $world = new TypeEditorsWorld(1);
    $agent = $world->read(new DescribeType(new TypeName('sample:card_1')), $world->reader(ClassificationAccess::Sensitive, IssuerKind::Agent));

    expect(typePaths(describedType($agent) ?? SampleEditors::type(2)))->toBe(SampleEditors::AGENT_PATHS)
        ->and($agent->access)->toBe(ClassificationAccess::Confidential)
        ->and(describedType($agent)?->titleField)->toBe('label');
});

it('describes a type the installation does not have as null, and refuses the anonymous principal as unauthorized', function (): void {
    $world = new TypeEditorsWorld(1);
    $missing = $world->read(new DescribeType(new TypeName('sample:card_9')), $world->reader(ClassificationAccess::Public));
    $anonymous = $world->read(new ListTypes, null);

    expect(describedType($missing))->toBeNull()
        ->and($missing->isAnswered())->toBeTrue()
        ->and($anonymous->result)->toBeNull()
        ->and(array_map(static fn (CatalogError $error): ErrorCode => $error->code, $anonymous->errors))->toBe([ErrorCode::Unauthorized]);
});

it('costs one unit for either read, whatever the number of types', function (): void {
    $world = new TypeEditorsWorld(30);

    expect(new ListTypesAction($world->editors)->cost(new ListTypes)->units)->toBe(1)
        ->and(new DescribeTypeAction($world->editors)->cost(new DescribeType(new TypeName('sample:card_30')))->units)->toBe(1)
        ->and(count(listedTypes($world->read(new ListTypes, $world->reader(ClassificationAccess::Public)))))->toBe(30);
});

it('refuses a result that keeps the descriptors the pipeline filtered, and answers nothing', function (): void {
    $world = new TypeEditorsWorld(1);
    $keeping = new readonly class implements DescribesTypes
    {
        #[Override]
        public function editors(): array
        {
            return SampleEditors::many(1);
        }

        #[Override]
        public function withEditors(array $editors): static
        {
            return $this;
        }
    };
    $action = new readonly class($keeping) implements QueryAction
    {
        public function __construct(private Result $answer) {}

        #[Override]
        public function cost(Query $query): QueryCost
        {
            return new QueryCost(1);
        }

        #[Override]
        public function handle(Query $query): Result
        {
            return $this->answer;
        }
    };
    $pipeline = new QueryPipeline(
        new FakeQueryActions([ListTypes::class => ProbeQueryBinding::of($action, 'type.list', 1)]),
        $world->identity,
        $world->access,
        new FakeQueryAuthorizer,
        new QuerySettings(new QueryCost(200), new QueryCost(1000)),
        new ReadableFields(new FakeTypeCatalog),
        new FakeReadAudit,
        new FakeQueryTransaction(sessions: $world->access),
        new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
    );

    expect(static fn (): QueryResult => $pipeline->run(new QueryCall(new ListTypes, $world->reader(ClassificationAccess::Public))))
        ->toThrow(InvalidQueryCall::class, 'holds other type editor descriptors after withEditors()');
});
