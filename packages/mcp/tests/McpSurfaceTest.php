<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Reads\Probe\AgentCardType;
use Cbox\Cms\Core\Tests\Reads\QueryWorld;
use Cbox\Cms\Mcp\Domain\McpTools;
use Cbox\Cms\Mcp\Tests\Support\McpAnswer;
use Cbox\Cms\Mcp\Tests\Support\McpClient;
use Cbox\Cms\Mcp\Tests\Support\McpWorld;

/*
 * The MCP surface end to end over HTTP (GUARDRAILS 2.1: MCP with tool errors; PRD 2.31, 12.2):
 * JSON-RPC messages to the workbench's MCP endpoint, POST /mcp, which McpRoutes registers (McpClient). The
 * tools are compiled from the registry of the MCP fixtures, the test-only write probe.rename and
 * the test-only query probe.agent_cards; the command pipeline and the query pipeline run with fakes
 * for their ports (GUARDRAILS 9), each call as the agent of the Bearer credential. It covers the
 * listing, the committed receipt, a field error, a version conflict, a dry run and a receipt that
 * did not reach its wait level, a credential that is not an agent's, refused arguments, an unknown
 * tool, a registry cache that cannot be read, and a read that leaves out every field the blueprint
 * does not open to agents.
 */

function mcpWorld(): McpWorld
{
    return new McpWorld()->bind(app());
}

it('lists a tool for each action the registry exposes on MCP, with the generated JSON forms as its input schema', function (): void {
    mcpWorld();

    $answer = McpClient::post(McpClient::message('tools/list'), null);
    $tools = $answer->list('result.tools');

    expect(McpAnswer::names($tools))->toBe([McpWorld::READ_TOOL, McpWorld::WRITE_TOOL])
        ->and(data_get($tools, '0.title'))->toBe('probe.agent_cards, version 1')
        ->and(data_get($tools, '0.description'))->toStartWith('The cards of the probe library an agent may read. Reads version 1 of the query probe.agent_cards')
        ->and(data_get($tools, '0.annotations.readOnlyHint'))->toBeTrue()
        ->and(data_get($tools, '0.inputSchema.required'))->toBe(['query'])
        ->and(data_get($tools, '0.inputSchema.properties.query.properties.rows.type'))->toBe('integer')
        ->and(data_get($tools, '1.title'))->toBe('probe.rename, version 1')
        ->and(data_get($tools, '1.annotations.readOnlyHint'))->toBeFalse()
        ->and(data_get($tools, '1.inputSchema.required'))->toBe(['command', 'envelope'])
        ->and(data_get($tools, '1.inputSchema.properties.command.$id'))->toBe('urn:cbox-cms:command:probe.rename:v1')
        ->and(data_get($tools, '1.inputSchema.properties.envelope.required'))->toBe(['idempotency_key'])
        ->and($answer->raw)->toContain('"$ref":"#\\/$defs\\/provenance","default":{}');
});

it('commits the command as the agent of the credential and answers with the receipt', function (): void {
    $mcp = mcpWorld();

    $answer = McpClient::call(McpWorld::WRITE_TOOL, McpClient::writeArguments($mcp, ['idempotency_key' => 'mcp-success']), $mcp->exposed->credential(IssuerKind::Agent));
    $receipt = $answer->document();
    $pending = $mcp->exposed->world->committer->pending;

    expect($answer->isError())->toBeFalse()
        ->and($receipt['outcome'] ?? null)->toBe('committed')
        ->and($receipt['changeset_id'] ?? null)->toBeString()
        ->and($receipt['wait_level'] ?? null)->toBe('commit')
        ->and($pending)->toHaveCount(1)
        ->and($pending[0]->envelope->idempotencyKey->value)->toBe('mcp-success')
        ->and($pending[0]->envelope->surface)->toBe(IssuingSurface::Mcp)
        ->and($pending[0]->envelope->issuerKind)->toBe(EnvelopeIssuer::Agent)
        ->and($pending[0]->envelope->actor->equals($mcp->exposed->service))->toBeTrue();
});

it('answers a field error as a tool error whose problem names every code and path, below command', function (): void {
    $mcp = mcpWorld();
    $fields = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('colour'), new TextValue('red'))));

    $answer = McpClient::call(McpWorld::WRITE_TOOL, McpClient::writeArguments($mcp, ['idempotency_key' => 'mcp-invalid'], $fields), $mcp->exposed->credential(IssuerKind::Agent));
    $problem = $answer->document();

    expect($answer->isError())->toBeTrue()
        ->and($problem['code'] ?? null)->toBe('validation_failed')
        ->and($problem['status'] ?? null)->toBe(422)
        ->and($answer->problemErrors())->toBe(['validation_failed -', 'validation_required command.fields.label', 'validation_unknown_field command.fields.colour'])
        ->and($mcp->exposed->world->committer->pending)->toBe([]);
});

it('answers a version conflict as a tool error with version_conflict', function (): void {
    $mcp = mcpWorld();
    $mcp->exposed->world->commitWith(new VersionConflict(new StaleRead($mcp->exposed->world->entry(), null, new AggregateVersion(1))));
    app()->instance(RunExposedCommand::class, $mcp->exposed->action());

    $answer = McpClient::call(McpWorld::WRITE_TOOL, McpClient::writeArguments($mcp, ['idempotency_key' => 'mcp-conflict']), $mcp->exposed->credential(IssuerKind::Agent));
    $problem = $answer->document();

    expect($answer->isError())->toBeTrue()
        ->and($problem['code'] ?? null)->toBe('version_conflict')
        ->and($problem['status'] ?? null)->toBe(409)
        ->and($answer->problemErrors())->toBe(['version_conflict -']);
});

it('runs a dry run, which commits nothing, as a tool result with the dry run\'s receipt', function (): void {
    $mcp = mcpWorld();

    $answer = McpClient::call(McpWorld::WRITE_TOOL, McpClient::writeArguments($mcp, ['idempotency_key' => 'mcp-dry-run', 'dry_run' => true]), $mcp->exposed->credential(IssuerKind::Agent));

    expect($answer->isError())->toBeFalse()
        ->and($answer->document()['outcome'] ?? null)->toBe('dry_run')
        ->and($mcp->exposed->world->committer->pending)->toBe([]);
});

it('answers a receipt that did not reach its wait level as a tool result, because the change is committed', function (): void {
    $mcp = mcpWorld();
    $mcp->exposed->world->commitWith(new Committed(Receipt::committedWaitTimeout(
        ChangesetId::fromString(FakeChangesetCommitter::CHANGESET),
        WaitLevel::Origin,
        RetentionClass::Standard,
        new CommitPosition(FakeChangesetCommitter::POSITION),
    )));
    app()->instance(RunExposedCommand::class, $mcp->exposed->action());

    $answer = McpClient::call(McpWorld::WRITE_TOOL, McpClient::writeArguments($mcp, ['idempotency_key' => 'mcp-wait', 'wait_level' => 'origin']), $mcp->exposed->credential(IssuerKind::Agent));
    $receipt = $answer->document();

    expect($answer->isError())->toBeFalse()
        ->and($receipt['outcome'] ?? null)->toBe('committed_wait_timeout')
        ->and($receipt['changeset_id'] ?? null)->toBe(FakeChangesetCommitter::CHANGESET)
        ->and($receipt['wait_level'] ?? null)->toBe('origin');
});

it('refuses a call without an agent\'s credential as unauthorized, and commits nothing', function (?IssuerKind $kind): void {
    $mcp = mcpWorld();
    $credential = $kind instanceof IssuerKind ? $mcp->exposed->credential($kind) : null;

    $answer = McpClient::call(McpWorld::WRITE_TOOL, McpClient::writeArguments($mcp, ['idempotency_key' => 'mcp-not-an-agent']), $credential);

    expect($answer->isError())->toBeTrue()
        ->and($answer->document()['code'] ?? null)->toBe('unauthorized')
        ->and($mcp->exposed->world->committer->pending)->toBe([]);
})->with([
    'a service\'s credential' => [IssuerKind::Service],
    'no credential' => [null],
]);

it('refuses arguments the tool cannot read as json_invalid at the path of the value in the arguments', function (string $arguments, array $errors): void {
    $mcp = mcpWorld();

    $answer = McpClient::call(McpWorld::WRITE_TOOL, $arguments, $mcp->exposed->credential(IssuerKind::Agent));

    expect($answer->isError())->toBeTrue()
        ->and($answer->problemErrors())->toBe($errors)
        ->and($mcp->exposed->world->committer->pending)->toBe([]);
})->with([
    'no envelope' => ['{"command":{}}', ['json_invalid envelope']],
    'an unknown member' => ['{"command":{},"envelope":{"idempotency_key":"a"},"extra":{}}', ['json_invalid -']],
    'a command that is not an object' => ['{"command":[],"envelope":{"idempotency_key":"a"}}', ['json_invalid command']],
    'an envelope value its codec refuses' => ['{"command":{},"envelope":{"idempotency_key":"a","wait_level":"soon"}}', ['json_invalid envelope.wait_level']],
    'a command document its codec refuses' => ['{"command":{"entry":"x","fields":{},"home":"x","type":"x"},"envelope":{"idempotency_key":"a"}}', ['json_invalid command.entry']],
]);

it('reads the empty object of the arguments as an object, not as a list', function (): void {
    $mcp = mcpWorld();

    $answer = McpClient::call(McpWorld::READ_TOOL, '{"query":{}}', $mcp->reads->agentCredential());

    expect($answer->isError())->toBeFalse()
        ->and($answer->document()['cards'] ?? null)->toHaveCount(1);
});

it('answers a call of a tool the surface does not offer with the JSON-RPC error -32602', function (): void {
    $mcp = mcpWorld();

    $answer = McpClient::call('entry-create-v1', '{}', $mcp->exposed->credential(IssuerKind::Agent));

    expect($answer->at('error.code'))->toBe(-32602)
        ->and($answer->at('error.message'))->toContain('Tool [entry-create-v1] not found');
});

it('answers with the JSON-RPC error -32603 and the catalog code when the registry cache cannot be read', function (): void {
    $mcp = mcpWorld();
    app()->bind(McpTools::class, static fn (): McpTools => throw new RegistryCacheMissing('The registry cache bootstrap/cache/cms/actions.php is missing. Run cms:build.'));

    $listed = McpClient::post(McpClient::message('tools/list'), null);
    $called = McpClient::call(McpWorld::WRITE_TOOL, McpClient::writeArguments($mcp, ['idempotency_key' => 'mcp-no-registry']), $mcp->exposed->credential(IssuerKind::Agent));

    expect($listed->at('error.code'))->toBe(-32603)
        ->and($listed->at('error.data.code'))->toBe('registry_cache_missing')
        ->and($called->at('error.code'))->toBe(-32603)
        ->and($called->at('error.data.code'))->toBe('registry_cache_missing')
        ->and($called->at('error.message'))->toContain('Run cms:build');
});

it('reads as the agent and leaves out every field the blueprint does not open to agents: confidential without agents: true, personal and sensitive', function (): void {
    $mcp = mcpWorld();
    $mcp->reads->access->grant($mcp->reads->reader, [], ClassificationAccess::Sensitive);

    $answer = McpClient::call(McpWorld::READ_TOOL, '{"query":{"rows":2}}', $mcp->reads->agentCredential());
    $cards = $answer->list('result.structuredContent.cards');
    $seen = array_map(static fn (array $card): array => [array_keys((array) data_get($card, 'fields')), array_keys((array) data_get($card, 'ext.probe'))], $cards);

    expect($answer->isError())->toBeFalse()
        ->and($answer->document()['cards'] ?? null)->toBe($cards)
        ->and(data_get($cards, '0.entry'))->toBe(QueryWorld::ENTRIES[0])
        ->and($seen)->toBe([
            [['brief', 'label', 'note', 'sources'], ['tag']],
            [['brief', 'label', 'note', 'sources'], ['tag']],
        ])
        ->and(data_get($cards, '0.fields.sources'))->toBe([['title' => 'first title'], ['title' => 'second title']])
        ->and($answer->raw)->not->toContain('memo of')
        ->and($answer->raw)->not->toContain('contact of')
        ->and($answer->raw)->not->toContain('diagnosis of')
        ->and($answer->raw)->not->toContain('aside of')
        ->and($answer->raw)->not->toContain('example.com')
        ->and($mcp->reads->audit->records)->toBe([]);

    foreach (AgentCardType::HIDDEN as $hidden) {
        $path = str_starts_with($hidden, 'ext.') ? '0.ext.probe.'.substr($hidden, 10) : '0.fields.'.$hidden;

        expect(data_get($cards, $path, 'absent'))->toBe('absent', $hidden.' reached the agent.');
    }
});

it('refuses a read without an agent\'s credential as unauthorized, before it reads', function (): void {
    $mcp = mcpWorld();

    $answer = McpClient::call(McpWorld::READ_TOOL, '{"query":{}}', $mcp->reads->credential);

    expect($answer->isError())->toBeTrue()
        ->and($answer->document()['code'] ?? null)->toBe('unauthorized')
        ->and($mcp->reads->library->handled)->toBe([]);
});

it('refuses a query its codec cannot read as json_invalid below query', function (): void {
    $mcp = mcpWorld();

    $answer = McpClient::call(McpWorld::READ_TOOL, '{"query":{"rows":0}}', $mcp->reads->agentCredential());

    expect($answer->isError())->toBeTrue()
        ->and($answer->problemErrors())->toBe(['json_invalid query.rows']);
});
