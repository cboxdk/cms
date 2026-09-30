<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Mcp\Domain\Dto\ToolAnswer;
use JsonException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use LogicException;

/**
 * The JSON of the MCP adapter's answers: a JSON document the surface gave, as the value laravel/mcp
 * encodes into the message, and a surface's answer that is a JSON-RPC error as the exception
 * laravel/mcp turns into one, with the problem details as its data.
 */
#[Internal]
final readonly class Answers
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * The value of a JSON document, with objects kept as objects, so an empty object stays one.
     */
    public static function document(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The JSON text of a value decoded from a message.
     *
     * @throws JsonException when the value has no JSON form
     */
    public static function json(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }

    /**
     * @throws LogicException when the answer is a tool result
     */
    public static function exception(ToolAnswer $answer, int|string $id): JsonRpcException
    {
        $data = $answer->document === null ? null : json_decode($answer->document, true, 512, JSON_THROW_ON_ERROR);

        return new JsonRpcException(
            $answer->message,
            $answer->rpcCode ?? throw new LogicException('A tool result is no JSON-RPC error.'),
            $id,
            is_array($data) ? self::keyed($data) : null,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private static function keyed(array $data): array
    {
        $keyed = [];

        foreach ($data as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
