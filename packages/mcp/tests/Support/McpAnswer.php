<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Support;

use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * The JSON-RPC response of the MCP endpoint in a test: its text, and its values by dotted path, as
 * PHPUnit reads them.
 */
final readonly class McpAnswer
{
    /** @var array<array-key, mixed> */
    public array $body;

    public function __construct(public string $raw)
    {
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $this->body = is_array($body) ? $body : throw new RuntimeException('The MCP endpoint did not answer with a JSON object: '.$raw);
    }

    /**
     * The value at the dotted path, such as result.isError, or null.
     */
    public function at(string $path): mixed
    {
        return data_get($this->body, $path);
    }

    /**
     * The list at the dotted path, each item an array.
     *
     * @return list<array<array-key, mixed>>
     */
    public function list(string $path): array
    {
        $items = $this->at($path);
        Assert::assertIsArray($items);

        return array_values(array_map(static fn (mixed $item): array => is_array($item) ? $item : throw new RuntimeException("An item at {$path} is not an object."), $items));
    }

    public function isError(): bool
    {
        $isError = $this->at('result.isError');
        Assert::assertIsBool($isError);

        return $isError;
    }

    /**
     * The document of a tool result, from its structured content, after checking that its text
     * content is the same document.
     *
     * @return array<array-key, mixed>
     */
    public function document(): array
    {
        $content = $this->list('result.content');
        Assert::assertCount(1, $content);
        Assert::assertSame('text', $content[0]['type'] ?? null);

        $text = $content[0]['text'] ?? null;
        $structured = $this->at('result.structuredContent');
        Assert::assertIsString($text);
        Assert::assertIsArray($structured);
        Assert::assertSame(json_decode($text, true, 512, JSON_THROW_ON_ERROR), $structured);

        return $structured;
    }

    /**
     * The errors of the document's problem, each as "<code> <field>".
     *
     * @return list<string>
     */
    public function problemErrors(): array
    {
        $errors = $this->document()['errors'] ?? null;
        Assert::assertIsArray($errors);

        return array_values(array_map(
            static fn (mixed $error): string => is_array($error) && is_string($error['code'] ?? null)
                ? $error['code'].' '.(is_string($error['field'] ?? null) ? $error['field'] : '-')
                : throw new RuntimeException('A problem error is not an object with a code.'),
            $errors,
        ));
    }

    /**
     * The names of the tools of a listing.
     *
     * @param  list<array<array-key, mixed>>  $tools
     * @return list<string>
     */
    public static function names(array $tools): array
    {
        return array_map(static fn (array $tool): string => is_string($tool['name'] ?? null) ? $tool['name'] : throw new RuntimeException('A tool has no name.'), $tools);
    }
}
