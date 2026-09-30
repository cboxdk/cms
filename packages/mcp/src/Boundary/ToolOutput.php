<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\McpResponse;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ReceiptCodecV1;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Mcp\Domain\Dto\ToolAnswer;
use Cbox\Cms\Mcp\Domain\Dto\ToolListing;
use Cbox\Cms\Mcp\Domain\McpTools;
use Cbox\Cms\Mcp\Domain\ToolCallRefused;
use LogicException;

/**
 * The MCP profile's answer to a tool call (GUARDRAILS 2.1: MCP with tool errors), as the error
 * catalog's MCP column says for each code (McpResponse):
 *
 * - A write that was not rejected is a tool result with the receipt (receipt.v1.json): committed,
 *   committed_wait_timeout, which committed the change and is no failure, and dry_run.
 * - An answered read is a tool result with the result, written by the query's result codec at the
 *   classification access of the reading agent, from a result the query pipeline already stripped
 *   to the fields the blueprints open to agents (PRD 2.31, 12.2).
 * - A rejection, or a refusal with a catalog code, is decided by its first error, the one that
 *   decided it: a tool result with isError set for a code the agent can act on, such as
 *   validation_failed or version_conflict, and the JSON-RPC error -32603 for one the installation
 *   is at fault for, such as registry_cache_missing. Both carry the problem details
 *   (problem.v1.json) with every catalog code and path. The paths of a write's errors are relative
 *   to the command's document, so they are written below `command`, as the arguments hold it.
 * - A call that names no tool the surface offers is the JSON-RPC error -32602, which has no code
 *   in the error catalog.
 */
#[Internal]
final readonly class ToolOutput
{
    public function __construct(
        private ReceiptCodecV1 $receipts,
        private ProblemCodecV1 $problems,
    ) {}

    public function listing(McpTools $tools): ToolListing
    {
        return new ToolListing($tools->tools);
    }

    public function written(WriteResult $result): ToolAnswer
    {
        if ($result->receipt->outcome === Outcome::Rejected && $result->errors !== []) {
            $errors = array_map(
                static fn (CatalogError $error): CatalogError => new CatalogError(
                    $error->code,
                    $error->path instanceof FieldPath ? new FieldPath(ToolCompiler::COMMAND, ...$error->path->segments) : null,
                    $error->message,
                ),
                $result->errors,
            );

            return $this->rejected($errors[0], ...array_slice($errors, 1));
        }

        return ToolAnswer::result($this->receipts->encode($result->receipt, ClassificationAccess::Public));
    }

    public function read(QueryResult $result, QueryCodec $codec): ToolAnswer
    {
        if ($result->result instanceof Result) {
            // The query pipeline answers with the result of the query the codec is registered for,
            // the class the codec writes; the codec's template is covariant, so its parameter reads
            // never.
            // @phpstan-ignore argument.type (the result of this codec's query, see above)
            return ToolAnswer::result($codec->result->encode($result->result, $result->access));
        }

        $errors = $result->errors;

        return $errors !== []
            ? $this->rejected($errors[0], ...array_slice($errors, 1))
            : throw new LogicException('A read that was not answered carries at least one catalog error.');
    }

    public function refused(ToolCallRefused $refused): ToolAnswer
    {
        return $refused->errorCode instanceof ErrorCode
            ? $this->rejected(new CatalogError($refused->errorCode, $refused->path, $refused->getMessage()))
            : ToolAnswer::invalidParams($refused->getMessage());
    }

    /**
     * The listing when the tools cannot be listed.
     */
    public function unlisted(ToolCallRefused $refused): ToolListing
    {
        return new ToolListing([], $this->refused($refused));
    }

    private function rejected(CatalogError $first, CatalogError ...$more): ToolAnswer
    {
        $problem = $this->problems->encode(Problem::of($first->code, $first->message, [$first, ...array_values($more)]), ClassificationAccess::Public);
        $response = $first->code->entry()->mcp;

        return $response->jsonRpcCode() === null
            ? ToolAnswer::toolError($problem, $first->message)
            : ToolAnswer::rpcError($response, $problem, $first->message);
    }
}
