<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * The name of an MCP tool (GUARDRAILS 2.1): the command's or query's name with each dot written as
 * a hyphen, then `-v` and the version, such as `entry-create-v1` for version 1 of entry.create. A
 * command name is dot-separated snake_case segments, so the hyphens mark the dots and two commands
 * never share a tool name. It keeps to the characters and length every MCP client takes, letters,
 * digits, `_` and `-` and at most MAX_LENGTH, so a client that refuses dots still lists the tool.
 */
#[Internal]
final readonly class ToolName
{
    /** The longest name MCP clients take. */
    public const int MAX_LENGTH = 64;

    private const string PATTERN = '/\A[a-z][a-z0-9_]*(?:-[a-z][a-z0-9_]*)+-v[1-9][0-9]*\z/';

    /**
     * @throws InvalidToolName when the text is not a tool name
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidToolName::form($value);
        }

        if (strlen($value) > self::MAX_LENGTH) {
            throw InvalidToolName::length($value);
        }
    }

    /**
     * The tool name of a version of a command or query.
     *
     * @throws InvalidToolName when the name would be longer than MAX_LENGTH
     */
    public static function of(CommandName $command, int $version): self
    {
        return new self(str_replace('.', '-', $command->value).'-v'.$version);
    }
}
