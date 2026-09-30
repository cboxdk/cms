<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookMap;
use Cbox\Cms\Core\Registry\Domain\Dto\VersionHooks;

/**
 * What cms:hooks prints (PRD 13.2): the hook map of a command, each version with its hooks in the
 * order they run and their budgets, and the budget of them all, the longest the hooks may take
 * together for one call.
 *
 * With --json, one document, keys sorted: `{"command": "<name>", "version": 1, "versions":
 * [{"budget_ms": <sum>, "hooks": [...], "version": <n>}]}`. Without it, a heading per version and a
 * numbered line per hook.
 */
#[Internal]
final readonly class HookMapOutput
{
    public const int VERSION = 1;

    public function of(HookMap $map, bool $json): CliAnswer
    {
        if ($json) {
            return new CliAnswer(ExitCode::Ok, [json_encode(
                [
                    'command' => $map->command->value,
                    'version' => self::VERSION,
                    'versions' => array_map(static fn (VersionHooks $version): array => [
                        'budget_ms' => self::budget($version),
                        'hooks' => array_map(HookJson::toArray(...), $version->hooks),
                        'version' => $version->version,
                    ], $map->versions),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )]);
        }

        $lines = [];

        foreach ($map->versions as $version) {
            $count = count($version->hooks);

            if ($count === 0) {
                $lines[] = sprintf('<info>%s</info> v%d: no hooks run.', $map->command->value, $version->version);

                continue;
            }

            $lines[] = sprintf(
                '<info>%s</info> v%d: %d %s, in the order they run, budget %d ms in all',
                $map->command->value,
                $version->version,
                $count,
                $count === 1 ? 'hook' : 'hooks',
                self::budget($version),
            );

            foreach ($version->hooks as $number => $hook) {
                $lines[] = sprintf('  %d. %s', $number + 1, HookJson::line($hook));
            }
        }

        return new CliAnswer(ExitCode::Ok, $lines);
    }

    private static function budget(VersionHooks $version): int
    {
        return array_sum(array_map(static fn (HookEntry $hook): int => $hook->budgetMs, $version->hooks));
    }
}
