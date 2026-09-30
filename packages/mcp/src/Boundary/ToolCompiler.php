<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Mcp\Domain\Dto\McpTool;
use Cbox\Cms\Mcp\Domain\Dto\UndescribedTool;
use Cbox\Cms\Mcp\Domain\InvalidToolName;
use Cbox\Cms\Mcp\Domain\McpTools;
use Cbox\Cms\Mcp\Domain\ToolName;
use stdClass;

/**
 * Compiles the MCP tools from the registry cms:build compiles (GUARDRAILS 2.1, PRD 14.5): one tool
 * per action that lists Surface::Mcp, named after its command or query (ToolName), whose arguments
 * are described by the generated JSON forms of what it reads:
 *
 * - a write takes `command`, the command's document as its CommandCodec's JSON Schema describes
 *   it, and `envelope`, the envelope of envelope.v1.json, whose idempotency_key is required;
 * - a read takes `query`, the query's document as its QueryCodec's JSON Schema describes it.
 *
 * Each embedded schema keeps or gets an `$id` of its own, so its `#/$defs/...` references resolve
 * inside it. The tool's title is the schema's title, and its description the schema's description
 * followed by how the tool runs and answers, so a tool describes itself from the same text as the
 * contract (PRD 14.5). An action whose command or query no codec reads cannot be described, and is
 * named with the reason in McpTools::$undescribed instead of offered as a tool.
 */
#[Internal]
final readonly class ToolCompiler
{
    public const string COMMAND = 'command';

    public const string ENVELOPE = 'envelope';

    public const string QUERY = 'query';

    /** The `$id` of the embedded envelope schema. */
    public const string ENVELOPE_ID = 'urn:cbox-cms:envelope:v1';

    public function compile(CompiledRegistry $registry, CommandCodecs $commands, QueryCodecs $queries, JsonSchema $envelope): McpTools
    {
        $tools = [];
        $undescribed = [];

        foreach ($registry->actions as $action) {
            if (! $action->exposes(Surface::Mcp)) {
                continue;
            }

            try {
                $name = ToolName::of($action->command, $action->commandVersion);
            } catch (InvalidToolName $invalid) {
                $undescribed[] = new UndescribedTool($action, $invalid->getMessage());

                continue;
            }

            $tool = $action->kind === ActionKind::Write
                ? $this->write($name, $action, $commands->find($action->command, $action->commandVersion), $envelope)
                : $this->read($name, $action, $queries->find($action->command, $action->commandVersion));

            if ($tool instanceof McpTool) {
                $tools[] = $tool;
            } else {
                $undescribed[] = new UndescribedTool($action, $tool);
            }
        }

        return new McpTools($tools, $undescribed);
    }

    /**
     * The tool of a write, or why there is none.
     */
    private function write(ToolName $name, ActionEntry $action, ?CommandCodec $codec, JsonSchema $envelope): McpTool|string
    {
        if (! $codec instanceof CommandCodec) {
            return sprintf(
                'No codec reads version %d of the command %s, so its arguments cannot be described or read. Register its CommandCodec, with the command\'s JSON Schema, under the container tag %s.',
                $action->commandVersion,
                $action->command->value,
                CommandCodecs::TAG,
            );
        }

        $command = $this->embedded($codec->schema, sprintf('urn:cbox-cms:command:%s:v%d', $action->command->value, $action->commandVersion));

        return new McpTool(
            $name,
            $this->title($command, $action),
            $this->describe($command, sprintf(
                'Runs version %d of the command %s through the command pipeline, as the agent of the credential. Send the command\'s document as command and the envelope as envelope, with an idempotency key of your own in envelope.idempotency_key, and the same key when you repeat the call; set envelope.dry_run to compute the plan and the receipt without committing. The answer is the receipt (receipt.v1.json), or the problem details (problem.v1.json) with the codes of the error catalog.',
                $action->commandVersion,
                $action->command->value,
            )),
            $this->arguments([self::COMMAND => $command, self::ENVELOPE => $this->embedded($envelope, self::ENVELOPE_ID)]),
            $codec,
            null,
        );
    }

    /**
     * The tool of a read, or why there is none.
     */
    private function read(ToolName $name, ActionEntry $action, ?QueryCodec $codec): McpTool|string
    {
        if (! $codec instanceof QueryCodec) {
            return sprintf(
                'No codec reads version %d of the query %s, so its arguments cannot be described or read. Register its QueryCodec, with the JSON Schemas of the query and its result, under the container tag %s.',
                $action->commandVersion,
                $action->command->value,
                QueryCodecs::TAG,
            );
        }

        $query = $this->embedded($codec->querySchema, sprintf('urn:cbox-cms:query:%s:v%d', $action->command->value, $action->commandVersion));

        return new McpTool(
            $name,
            $this->title($query, $action),
            $this->describe($query, sprintf(
                'Reads version %d of the query %s through the query pipeline, as the agent of the credential. Send the query\'s document as query. The answer is the result, with only the fields the blueprints open to agents, or the problem details (problem.v1.json) with the codes of the error catalog.',
                $action->commandVersion,
                $action->command->value,
            )),
            $this->arguments([self::QUERY => $query]),
            null,
            $codec,
        );
    }

    /**
     * The schema as an object, with an `$id` of its own unless it has one.
     */
    private function embedded(JsonSchema $schema, string $id): stdClass
    {
        $object = JsonText::decode($schema->json);

        $object->{'$id'} ??= $id;

        return $object;
    }

    /**
     * The schema of a tool's arguments: an object with each member required and no other.
     *
     * @param  array<string, stdClass>  $members
     */
    private function arguments(array $members): JsonSchema
    {
        $properties = new stdClass;

        foreach ($members as $key => $schema) {
            $properties->{$key} = $schema;
        }

        $arguments = new stdClass;
        $arguments->type = 'object';
        $arguments->additionalProperties = false;
        $arguments->required = array_keys($members);
        $arguments->properties = $properties;

        return new JsonSchema(JsonText::encode($arguments));
    }

    private function title(stdClass $schema, ActionEntry $action): string
    {
        $title = $schema->title ?? null;

        return is_string($title) && trim($title) !== '' ? $title : sprintf('%s, version %d', $action->command->value, $action->commandVersion);
    }

    private function describe(stdClass $schema, string $how): string
    {
        $description = $schema->description ?? null;

        return is_string($description) && trim($description) !== '' ? rtrim($description).' '.$how : $how;
    }
}
