<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\ContractSummaries;
use Cbox\Cms\Core\Registry\Domain\Dto\ContractSummary;
use Override;

/**
 * ContractSummaries from the registered codecs (GUARDRAILS 2.2): the JSON Schema a command's
 * CommandCodec carries, or the document schema of a query's QueryCodec, read for its `title` and
 * `description`. A contract version without a codec, or whose schema has no title, has no summary.
 */
#[Internal]
final readonly class CodecContractSummaries implements ContractSummaries
{
    public function __construct(
        private CommandCodecs $commands,
        private QueryCodecs $queries,
    ) {}

    #[Override]
    public function of(ActionKind $kind, CommandName $name, int $version): ?ContractSummary
    {
        $schema = match ($kind) {
            ActionKind::Write => $this->commands->find($name, $version)?->schema,
            ActionKind::Query => $this->queries->find($name, $version)?->querySchema,
        };

        if (! $schema instanceof JsonSchema) {
            return null;
        }

        $decoded = json_decode($schema->json, true, JsonSchema::DEPTH);
        $title = is_array($decoded) ? ($decoded['title'] ?? null) : null;
        $description = is_array($decoded) ? ($decoded['description'] ?? null) : null;

        if (! is_string($title) || $title === '') {
            return null;
        }

        return new ContractSummary($title, is_string($description) ? $description : '');
    }
}
