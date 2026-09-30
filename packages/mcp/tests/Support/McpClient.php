<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Support;

use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Mcp\Adapter\McpRoutes;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use stdClass;

/**
 * An agent's side of the MCP endpoint in a test: JSON-RPC messages posted to POST /mcp, the
 * workbench's route of McpRoutes, through the application's HTTP kernel, with a credential as the
 * Bearer token.
 */
final readonly class McpClient
{
    /**
     * A JSON-RPC request of the MCP protocol, as its JSON text.
     */
    public static function message(string $method, ?stdClass $params = null, int $id = 1): string
    {
        $message = new stdClass;
        $message->jsonrpc = '2.0';
        $message->id = $id;
        $message->method = $method;

        if ($params instanceof stdClass) {
            $message->params = $params;
        }

        return json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Posts the message to the MCP endpoint with the credential as its Bearer token.
     */
    public static function post(string $message, ?TransportCredential $credential): McpAnswer
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];

        if ($credential instanceof TransportCredential) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$credential->reveal();
        }

        $response = app(Kernel::class)->handle(Request::create('/'.McpRoutes::PATH, 'POST', [], [], [], $server, $message));

        return new McpAnswer((string) $response->getContent());
    }

    /**
     * The tools tools/list gives.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function tools(): array
    {
        return self::post(self::message('tools/list'), null)->list('result.tools');
    }

    /**
     * Calls the tool with the arguments, given as JSON text.
     */
    public static function call(string $tool, string $arguments, ?TransportCredential $credential): McpAnswer
    {
        $params = new stdClass;
        $params->name = $tool;
        $params->arguments = json_decode($arguments, false, 512, JSON_THROW_ON_ERROR);

        return self::post(self::message('tools/call', $params), $credential);
    }

    /**
     * The arguments of probe-rename-v1: the command's document and the envelope.
     *
     * @param  array<string, bool|string>  $envelope
     */
    public static function writeArguments(McpWorld $world, array $envelope, ?FieldValues $fields = null): string
    {
        return sprintf('{"command":%s,"envelope":%s}', $world->exposed->document($fields), json_encode((object) $envelope, JSON_THROW_ON_ERROR));
    }
}
