<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Boundary;

use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EnvelopeCodecV1;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Mcp\Boundary\ToolInput;
use Cbox\Cms\Mcp\Domain\Dto\McpTool;
use Cbox\Cms\Mcp\Domain\Dto\ToolCall;
use Cbox\Cms\Mcp\Domain\McpTools;
use Cbox\Cms\Mcp\Domain\ToolCallRefused;
use Cbox\Cms\Mcp\Domain\ToolName;
use Cbox\Cms\Mcp\Tests\Support\AgentCardsCodec;
use Cbox\Cms\Mcp\Tests\Support\PathlessRefusalCodec;
use Closure;
use Illuminate\Container\Container;
use LogicException;
use Throwable;

/*
 * The MCP surface's reading of a call (ToolInput) apart from the kernel: the registry cache that
 * cannot be read is told apart by its kind, and a member's document refused without a path is
 * refused at the member itself. McpSurfaceTest runs the surface end to end.
 */

/**
 * The reading of calls with a container whose McpTools is made by $tools.
 *
 * @param  Closure(): McpTools  $tools
 */
function toolInputWith(Closure $tools): ToolInput
{
    $container = new Container;
    $container->bind(McpTools::class, $tools);

    return new ToolInput($container, new EnvelopeCodecV1);
}

/**
 * The refusal $read throws, or null when it throws none.
 *
 * @param  Closure(): mixed  $read
 */
function toolRefusal(Closure $read): ?Throwable
{
    try {
        $read();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    return null;
}

it('refuses with the catalog code of the registry cache that cannot be read, by its kind, and lets any other failure through', function (): void {
    $missing = RegistryCacheMissing::at('/srv/cache/actions.php');
    $malformed = MalformedRegistryCache::at('/srv/cache/actions.php', '', 'not an array');
    $other = new LogicException('another failure');

    $refusedMissing = toolRefusal(static fn (): McpTools => toolInputWith(static fn (): never => throw $missing)->catalog());
    $refusedMalformed = toolRefusal(static fn (): McpTools => toolInputWith(static fn (): never => throw $malformed)->catalog());
    $passed = toolRefusal(static fn (): McpTools => toolInputWith(static fn (): never => throw $other)->catalog());

    expect($refusedMissing)->toBeInstanceOf(ToolCallRefused::class)
        ->and($refusedMissing instanceof ToolCallRefused ? [$refusedMissing->errorCode, $refusedMissing->path, $refusedMissing->getPrevious()] : null)->toBe([ErrorCode::RegistryCacheMissing, null, $missing])
        ->and($refusedMalformed instanceof ToolCallRefused ? [$refusedMalformed->errorCode, $refusedMalformed->path, $refusedMalformed->getPrevious()] : null)->toBe([ErrorCode::RegistryCacheMalformed, null, $malformed])
        ->and($passed)->toBe($other);
});

it('refuses a member\'s document that its codec refuses without a path at the member itself', function (): void {
    $schema = new JsonSchema('{"type":"object"}');
    $codec = new QueryCodec(new CommandName('probe.agent_cards'), 1, new PathlessRefusalCodec, $schema, new AgentCardsCodec, $schema);
    $tool = new McpTool(new ToolName('probe-agent-cards-v1'), 'Agent cards', 'The probe cards.', $schema, null, $codec);
    $input = toolInputWith(static fn (): McpTools => new McpTools([$tool]));

    $refusal = toolRefusal(static fn (): mixed => $input->read(new ToolCall('probe-agent-cards-v1', '{"query":{}}', null)));

    expect($refusal)->toBeInstanceOf(ToolCallRefused::class)
        ->and($refusal instanceof ToolCallRefused ? [$refusal->errorCode, $refusal->path?->toString(), $refusal->getMessage()] : null)
        ->toBe([ErrorCode::JsonInvalid, 'query', 'refused as a whole']);
});

it('carries the catalog code and path of a refusal in properties of its own, with the exception code 0', function (): void {
    $cause = new LogicException('cause');
    $refused = ToolCallRefused::catalog(ErrorCode::JsonInvalid, null, 'refused', $cause);

    expect([$refused->errorCode, $refused->getCode(), $refused->getPrevious(), $refused->getMessage()])->toBe([ErrorCode::JsonInvalid, 0, $cause, 'refused'])
        ->and(ToolCallRefused::unknownTool('nosuch')->getCode())->toBe(0)
        ->and(ToolCallRefused::unknownTool('nosuch')->errorCode)->toBeNull();
});
