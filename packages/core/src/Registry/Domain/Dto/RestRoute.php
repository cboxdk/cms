<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;
use Cbox\Cms\Core\Registry\Domain\RestMethod;

/**
 * A route of the REST surface (GUARDRAILS 2.1, PRD 8.8), compiled by cms:build into rest.php from
 * an action that #[Action] exposes on Surface::Rest: the kind of the action, the command or query
 * it handles by name and version, and the method and path they give.
 *
 * The path carries the version of the REST contract, v1, first (PRD 8.8: the contract version is
 * in the path), then `commands` or `queries`, the name and the version of the command or query:
 *
 * - a write is `POST /v1/commands/<name>/v<version>`, such as POST /v1/commands/entry.create/v1,
 *   with the command's JSON document as the body;
 * - a read is `GET /v1/queries/<name>/v<version>`, such as GET /v1/queries/entry.list/v1, with the
 *   query's JSON document in the query parameter QUERY_PARAMETER.
 *
 * The method and the path follow from the rest, so a route read from a cache file that names
 * another is refused.
 */
#[Experimental]
final readonly class RestRoute
{
    /** The version of the REST contract, the first segment of every path. */
    public const string CONTRACT = 'v1';

    /** The query parameter that holds a read's query document. */
    public const string QUERY_PARAMETER = 'query';

    public RestMethod $method;

    public string $path;

    public function __construct(
        public ActionKind $kind,
        public CommandName $name,
        public int $version,
    ) {
        if ($version < 1) {
            throw InvalidRegistryEntry::because(sprintf('The REST route of "%s" has version %d. Versions start at 1.', $name->value, $version));
        }

        $this->method = RestMethod::of($kind);
        $this->path = sprintf('/%s/%s/%s/v%d', self::CONTRACT, $kind === ActionKind::Write ? 'commands' : 'queries', $name->value, $version);
    }

    /**
     * The route of an action, or null when the action is not exposed on REST.
     */
    public static function of(ActionEntry $action): ?self
    {
        return $action->exposes(Surface::Rest)
            ? new self($action->kind, $action->command, $action->commandVersion)
            : null;
    }
}
