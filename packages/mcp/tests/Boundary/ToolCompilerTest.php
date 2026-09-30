<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Boundary;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeCodec;
use Cbox\Cms\Mcp\Boundary\KernelSchemas;
use Cbox\Cms\Mcp\Boundary\ToolCompiler;
use Cbox\Cms\Mcp\Domain\Dto\McpTool;
use Cbox\Cms\Mcp\Domain\Dto\UndescribedTool;
use Cbox\Cms\Mcp\Tests\Support\McpWorld;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/*
 * The tools compiled from the registry (GUARDRAILS 2.1, PRD 14.5): one per action exposed on MCP
 * whose codec is registered, with the generated JSON forms as its input schema, each embedded with
 * an `$id` of its own so its references resolve; a title and description from the schema; and the
 * actions no codec reads named with the reason.
 */

/**
 * The registry with probe.rename's action exposed on the surfaces given.
 *
 * @param  list<Surface>  $surfaces
 */
function compilerRegistry(array $surfaces, string $command = ExposedWorld::COMMAND): CompiledRegistry
{
    return new CompiledRegistry(
        [new CommandEntry(new CommandName($command), 1, RenameProbe::class, 'acme/probe')],
        [],
        [new ActionEntry(RenameProbeAction::class, 'acme/probe', ActionKind::Write, new CommandName($command), 1, RenameProbe::class, $surfaces)],
    );
}

/**
 * The validation errors of the arguments against the tool's input schema, by JSON pointer.
 *
 * @return array<string, list<string>>
 */
function compilerErrors(McpTool $tool, string $arguments): array
{
    $error = new Validator()->validate(json_decode($arguments, false, 512, JSON_THROW_ON_ERROR), $tool->input->json)->error();

    if (! $error instanceof ValidationError) {
        return [];
    }

    /** @var array<string, list<string>> $errors */
    $errors = new ErrorFormatter()->format($error, true);

    return $errors;
}

it('compiles a tool per action exposed on MCP whose codec is registered, and none for the other surfaces', function (): void {
    $tools = McpWorld::tools();
    $rest = new ToolCompiler()->compile(compilerRegistry([Surface::Rest, Surface::Cli]), McpWorld::commandCodecs(), McpWorld::queryCodecs(), KernelSchemas::envelope());

    expect(array_map(static fn (McpTool $tool): string => $tool->name->value, $tools->tools))->toBe([McpWorld::READ_TOOL, McpWorld::WRITE_TOOL])
        ->and($tools->tools[0]->readsOnly())->toBeTrue()
        ->and($tools->tools[1]->command)->toEqual(ExposedWorld::codecs()->for(new CommandName(ExposedWorld::COMMAND), 1))
        ->and($tools->undescribed)->toBe([])
        ->and($rest->tools)->toBe([])
        ->and($rest->undescribed)->toBe([]);
});

it('describes a write\'s arguments as the command\'s document and the envelope, each resolving its own references', function (): void {
    $tool = McpWorld::tools()->find(McpWorld::WRITE_TOOL);
    $entry = '01936f5e-8a2b-7c3d-9e4f-0000000000e1';
    $command = sprintf('{"entry":"%s","fields":{"label":"A"},"home":"%s","type":"%s"}', $entry, $entry, $entry);

    expect($tool)->toBeInstanceOf(McpTool::class);

    if (! $tool instanceof McpTool) {
        return;
    }

    $schema = json_decode($tool->input->json, true, 512, JSON_THROW_ON_ERROR);

    expect(data_get($schema, 'required'))->toBe(['command', 'envelope'])
        ->and(data_get($schema, 'additionalProperties'))->toBeFalse()
        ->and(data_get($schema, 'properties.command.$id'))->toBe('urn:cbox-cms:command:probe.rename:v1')
        ->and(data_get($schema, 'properties.envelope.$id'))->toBe(ToolCompiler::ENVELOPE_ID)
        ->and(data_get($schema, 'properties.envelope.title'))->toBe('Envelope of a request, contract version 1')
        ->and(compilerErrors($tool, sprintf('{"command":%s,"envelope":{"idempotency_key":"k","provenance":{}}}', $command)))->toBe([])
        ->and(compilerErrors($tool, sprintf('{"command":%s,"envelope":{"idempotency_key":"k","provenance":{"model":{"name":""}}}}', $command)))->not->toBe([])
        ->and(compilerErrors($tool, sprintf('{"command":%s,"envelope":{"idempotency_key":"k"}}', str_replace($entry.'","fields', 'x","fields', $command))))->toHaveKey('/command/entry')
        ->and(compilerErrors($tool, sprintf('{"command":%s}', $command)))->not->toBe([])
        ->and($tool->title)->toBe('probe.rename, version 1')
        ->and($tool->description)->toStartWith('Runs version 1 of the command probe.rename through the command pipeline, as the agent of the credential.');
});

it('describes a read\'s arguments as the query\'s document, with the schema\'s description first', function (): void {
    $tool = McpWorld::tools()->find(McpWorld::READ_TOOL);

    expect($tool)->toBeInstanceOf(McpTool::class);

    if (! $tool instanceof McpTool) {
        return;
    }

    expect(data_get(json_decode($tool->input->json, true, 512, JSON_THROW_ON_ERROR), 'required'))->toBe(['query'])
        ->and(compilerErrors($tool, '{"query":{"rows":2}}'))->toBe([])
        ->and(compilerErrors($tool, '{"query":{"rows":0}}'))->toHaveKey('/query/rows')
        ->and($tool->description)->toBe('The cards of the probe library an agent may read. Reads version 1 of the query probe.agent_cards through the query pipeline, as the agent of the credential. Send the query\'s document as query. The answer is the result, with only the fields the blueprints open to agents, or the problem details (problem.v1.json) with the codes of the error catalog.');
});

it('keeps the $id of a schema that has one, and names its action after its command and version without a title', function (): void {
    $schema = new JsonSchema('{"$id":"https://example.com/rename.json","type":"object"}');
    $codecs = new CommandCodecs(new CommandCodec(new CommandName(ExposedWorld::COMMAND), 1, new RenameProbeCodec, $schema));
    $tool = new ToolCompiler()->compile(compilerRegistry([Surface::Mcp]), $codecs, new QueryCodecs, KernelSchemas::envelope())->tools[0];

    expect(data_get(json_decode($tool->input->json, true, 512, JSON_THROW_ON_ERROR), 'properties.command.$id'))->toBe('https://example.com/rename.json')
        ->and($tool->title)->toBe('probe.rename, version 1');
});

it('names an action exposed on MCP without a codec, or with a name too long for a tool, with the reason instead of a tool', function (): void {
    $long = 'probe.'.str_repeat('a', 60);
    $compiler = new ToolCompiler;

    $uncoded = $compiler->compile(compilerRegistry([Surface::Mcp]), new CommandCodecs, new QueryCodecs, KernelSchemas::envelope());
    $tooLong = $compiler->compile(compilerRegistry([Surface::Mcp], $long), new CommandCodecs, new QueryCodecs, KernelSchemas::envelope());
    $noQuery = $compiler->compile(McpWorld::registry(), McpWorld::commandCodecs(), new QueryCodecs, KernelSchemas::envelope());

    expect($uncoded->tools)->toBe([])
        ->and(array_map(static fn (UndescribedTool $tool): string => $tool->reason, $uncoded->undescribed))->toBe([
            'No codec reads version 1 of the command probe.rename, so its arguments cannot be described or read. Register its CommandCodec, with the command\'s JSON Schema, under the container tag cbox-cms.command-codecs.',
        ])
        ->and($uncoded->undescribed[0]->action->class)->toBe(RenameProbeAction::class)
        ->and($tooLong->undescribed[0]->reason)->toContain('longer than 64 characters')
        ->and(array_map(static fn (McpTool $tool): string => $tool->name->value, $noQuery->tools))->toBe([McpWorld::WRITE_TOOL])
        ->and($noQuery->undescribed[0]->reason)->toBe('No codec reads version 1 of the query probe.agent_cards, so its arguments cannot be described or read. Register its QueryCodec, with the JSON Schemas of the query and its result, under the container tag cbox-cms.query-codecs.');
});
