<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Boundary;

use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Mcp\Boundary\ToolArguments;

/*
 * The arguments of a tool call split into their members, each an object handed on as its own JSON
 * text, and refused at the path of the member that is wrong.
 */

it('hands on each member as its own JSON text, empty objects included', function (): void {
    expect(ToolArguments::members('{"envelope":{"idempotency_key":"k"},"command":{"fields":{}}}', ['command', 'envelope']))->toBe([
        'command' => '{"fields":{}}',
        'envelope' => '{"idempotency_key":"k"}',
    ]);
});

it('refuses arguments it cannot split, at the path of the member', function (string $arguments, string $code, ?string $path): void {
    $refusal = null;

    try {
        ToolArguments::members($arguments, ['command', 'envelope']);
    } catch (DecodingFailed $failed) {
        $refusal = $failed;
    }

    expect($refusal)->toBeInstanceOf(DecodingFailed::class)
        ->and($refusal?->errorCode->value)->toBe($code)
        ->and($refusal?->path?->toString())->toBe($path);
})->with([
    'not JSON' => ['{', 'json_malformed', null],
    'not an object' => ['[]', 'json_malformed', null],
    'a key twice' => ['{"command":{},"command":{},"envelope":{}}', 'json_malformed', null],
    'an unknown key' => ['{"command":{},"envelope":{},"query":{}}', 'json_invalid', null],
    'a missing member' => ['{"command":{}}', 'json_invalid', 'envelope'],
    'a member that is null' => ['{"command":null,"envelope":{}}', 'json_invalid', 'command'],
    'a member that is not an object' => ['{"command":"text","envelope":{}}', 'json_invalid', 'command'],
]);
