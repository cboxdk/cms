<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Composer\InstalledVersions;
use Laravel\Mcp\Server;
use OutOfBoundsException;
use Override;
use stdClass;

/**
 * The MCP server of Cbox CMS on laravel/mcp (GUARDRAILS 1: laravel/mcp behind an internal adapter,
 * pinned to an exact minor). It offers tools only: tools/list and tools/call are answered by
 * ListToolsMethod and CallToolMethod, which ask the MCP surface through McpEndpoint, so no other
 * part of the package depends on laravel/mcp. McpRoutes registers it on a route.
 */
#[Internal]
final class McpServer extends Server
{
    public const string PACKAGE = 'cboxdk/cms';

    /** @var array<string, array<string, bool>|stdClass|string> */
    #[Override]
    protected array $capabilities = [
        self::CAPABILITY_TOOLS => [
            'listChanged' => false,
        ],
    ];

    #[Override]
    protected string $name = 'Cbox CMS';

    #[Override]
    protected string $instructions = <<<'MARKDOWN'
        The tools of this Cbox CMS installation, one per command and query it exposes to agents.
        A command's tool takes the command's document as command and the envelope as envelope, with
        an idempotency key of your own, and answers with the receipt; set envelope.dry_run to see
        the plan and the receipt without committing. A query's tool takes the query's document as
        query and answers with its result, which holds only the fields the blueprints open to
        agents. A rejected call is a tool error whose problem details carry the error catalog's
        codes, such as version_conflict when the content changed since you read it: read it again
        and retry with the new version.
        MARKDOWN;

    #[Override]
    protected function boot(): void
    {
        $this->version = $this->packageVersion();
        $this->addMethod('tools/list', ListToolsMethod::class);
        $this->addMethod('tools/call', CallToolMethod::class);
    }

    private function packageVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'dev';
        } catch (OutOfBoundsException) {
            return 'dev';
        }
    }
}
