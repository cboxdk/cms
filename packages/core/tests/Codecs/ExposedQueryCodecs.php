<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;

/**
 * The query actions of a registry that an exposed surface offers but no QueryCodec reads
 * (GUARDRAILS 2.1, 2.2): a surface cannot read such a query, write its result or describe either,
 * so REST refuses it at cms:build and MCP lists it as undescribed.
 */
final readonly class ExposedQueryCodecs
{
    /**
     * Each query action exposed on a surface whose query's version has no codec, as
     * `<name> v<version> on <surfaces>`.
     *
     * @param  list<ActionEntry>  $actions
     * @return list<string>
     */
    public static function missing(array $actions, QueryCodecs $codecs): array
    {
        $missing = [];

        foreach ($actions as $action) {
            if ($action->kind !== ActionKind::Query || $action->surfaces === [] || $codecs->find($action->command, $action->commandVersion) instanceof QueryCodec) {
                continue;
            }

            $missing[] = sprintf(
                '%s v%d on %s',
                $action->command->value,
                $action->commandVersion,
                implode(', ', array_map(static fn (Surface $surface): string => $surface->value, $action->surfaces)),
            );
        }

        return $missing;
    }
}
