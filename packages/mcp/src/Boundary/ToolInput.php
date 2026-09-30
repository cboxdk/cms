<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EnvelopeCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ExposedCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Mcp\Domain\Dto\McpTool;
use Cbox\Cms\Mcp\Domain\Dto\ReadCall;
use Cbox\Cms\Mcp\Domain\Dto\ToolCall;
use Cbox\Cms\Mcp\Domain\McpTools;
use Cbox\Cms\Mcp\Domain\ToolCallRefused;
use Illuminate\Contracts\Container\Container;
use LogicException;
use Throwable;

/**
 * Reads an MCP tool call into what the kernel runs (GUARDRAILS 2.1, 2.2):
 *
 * 1. The tool must be one the surface offers (McpTools), or the call is the JSON-RPC error -32602.
 *    A registry cache that cannot be read refuses it with registry_cache_missing or
 *    registry_cache_malformed.
 * 2. The arguments are split into their members (ToolArguments).
 * 3. A write's envelope is read through the envelope's generated codec, by the same rules as every
 *    other surface; a value it refuses is json_invalid at `envelope.<field>`. Its command's
 *    document is read later, by the command's generated codec, at the classification access of the
 *    verified agent (RunExposedCommand), and the surface is Surface::Mcp, so the kernel refuses a
 *    credential that was not issued for an agent.
 * 4. A read's query is read through the query's generated codec; a query holds no classified
 *    content, so it is read with public access, and a value it refuses is json_invalid at
 *    `query.<field>`.
 * 5. The credential is the one the transport carried, never an argument.
 */
#[Internal]
final readonly class ToolInput
{
    public function __construct(
        private Container $container,
        private EnvelopeCodecV1 $envelopes,
    ) {}

    /**
     * The tools the surface offers.
     *
     * @throws ToolCallRefused when the registry cache cannot be read
     */
    public function catalog(): McpTools
    {
        // The container declares no exceptions, so the registry cache's are told apart here.
        try {
            return $this->container->make(McpTools::class);
        } catch (Throwable $failed) {
            throw match (true) {
                $failed instanceof RegistryCacheMissing => ToolCallRefused::catalog(ErrorCode::RegistryCacheMissing, null, $failed->getMessage(), $failed),
                $failed instanceof MalformedRegistryCache => ToolCallRefused::catalog(ErrorCode::RegistryCacheMalformed, null, $failed->getMessage(), $failed),
                default => $failed,
            };
        }
    }

    /**
     * @throws ToolCallRefused when the call cannot be run as it is
     */
    public function read(ToolCall $call): ExposedCall|ReadCall
    {
        $tool = $this->catalog()->find($call->name) ?? throw ToolCallRefused::unknownTool($call->name);

        return $tool->command instanceof CommandCodec
            ? $this->write($tool->command, $call)
            : $this->query($this->queryCodec($tool), $call);
    }

    private function write(CommandCodec $codec, ToolCall $call): ExposedCall
    {
        $members = $this->members($call, [ToolCompiler::COMMAND, ToolCompiler::ENVELOPE]);

        try {
            $envelope = $this->envelopes->decode($members[ToolCompiler::ENVELOPE], ClassificationAccess::Public);
        } catch (DecodingFailed $failed) {
            throw $this->below(ToolCompiler::ENVELOPE, $failed);
        }

        return new ExposedCall(Surface::Mcp, $call->credential, $envelope, $codec, $members[ToolCompiler::COMMAND]);
    }

    private function query(QueryCodec $codec, ToolCall $call): ReadCall
    {
        $members = $this->members($call, [ToolCompiler::QUERY]);

        try {
            $query = $codec->query->decode($members[ToolCompiler::QUERY], ClassificationAccess::Public);
        } catch (DecodingFailed $failed) {
            throw $this->below(ToolCompiler::QUERY, $failed);
        }

        return new ReadCall(new QueryCall($query, $call->credential, Surface::Mcp), $codec);
    }

    private function queryCodec(McpTool $tool): QueryCodec
    {
        return $tool->query ?? throw new LogicException(sprintf('The MCP tool %s runs neither a command nor a query.', $tool->name->value));
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function members(ToolCall $call, array $keys): array
    {
        try {
            return ToolArguments::members($call->arguments, $keys);
        } catch (DecodingFailed $failed) {
            throw ToolCallRefused::catalog($failed->errorCode, $failed->path, $failed->reason, $failed);
        }
    }

    /**
     * The refusal of a member's document, at the path of the value in the arguments.
     */
    private function below(string $member, DecodingFailed $failed): ToolCallRefused
    {
        return ToolCallRefused::catalog(
            $failed->errorCode,
            new FieldPath($member, ...($failed->path instanceof FieldPath ? $failed->path->segments : [])),
            $failed->reason,
            $failed,
        );
    }
}
