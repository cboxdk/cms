<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;

/**
 * The write actions of a registry that an exposed surface offers but no CommandCodec reads
 * (GUARDRAILS 2.1, 2.2): a surface cannot read or describe such a command, so REST refuses it at
 * cms:build and MCP lists it as undescribed.
 */
final readonly class ExposedCommandCodecs
{
    /**
     * Each write action exposed on a surface whose command's version has no codec, as
     * `<name> v<version> on <surfaces>`.
     *
     * @param  list<ActionEntry>  $actions
     * @return list<string>
     */
    public static function missing(array $actions, CommandCodecs $codecs): array
    {
        $missing = [];

        foreach ($actions as $action) {
            if ($action->kind !== ActionKind::Write || $action->surfaces === [] || $codecs->find($action->command, $action->commandVersion) instanceof CommandCodec) {
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
