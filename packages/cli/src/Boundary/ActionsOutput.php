<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionDescription;

/**
 * What cms:actions prints (GUARDRAILS 7.1): every action of the compiled registry, in its order.
 *
 * With --json, one document, keys sorted: `{"actions": [...], "version": 1}`, each action with its
 * class and package, its kind (write or query), the command or query it handles by name, version
 * and class, its surfaces, the permission a grant needs to allow it and its hooks in the order they
 * run. Without it, a block per action and a count.
 */
#[Internal]
final readonly class ActionsOutput
{
    public const int VERSION = 1;

    /**
     * @param  list<ActionDescription>  $actions
     */
    public function of(array $actions, bool $json): CliAnswer
    {
        if ($json) {
            return new CliAnswer(ExitCode::Ok, [json_encode(
                ['actions' => array_map($this->document(...), $actions), 'version' => self::VERSION],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )]);
        }

        $lines = [];

        foreach ($actions as $description) {
            $action = $description->action;
            $lines[] = sprintf('<info>%s</info> v%d  %s  %s (%s)', $action->command->value, $action->commandVersion, $action->kind->value, $action->class, $action->package);
            $lines[] = sprintf('  %-9s %s', $action->kind->input(), $action->commandClass);
            $lines[] = sprintf('  %-9s %s', 'surfaces', $action->surfaces === [] ? 'none, called by the kernel alone' : implode(', ', array_map(static fn (Surface $surface): string => $surface->value, $action->surfaces)));
            $lines[] = sprintf('  %-9s a role whose permissions hold %s', 'grant', $description->permission->value);

            if ($action->kind === ActionKind::Query) {
                $lines[] = sprintf('  %-9s none, a query runs no hooks', 'hooks');
            } elseif ($description->hooks === []) {
                $lines[] = sprintf('  %-9s none', 'hooks');
            } else {
                foreach ($description->hooks as $number => $hook) {
                    $lines[] = sprintf('  %-9s %s', $number === 0 ? 'hooks' : '', HookJson::line($hook));
                }
            }
        }

        $lines[] = sprintf('%d %s.', count($actions), count($actions) === 1 ? 'action' : 'actions');

        return new CliAnswer(ExitCode::Ok, $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(ActionDescription $description): array
    {
        $action = $description->action;

        return [
            'class' => $action->class,
            'command' => $action->command->value,
            'command_class' => $action->commandClass,
            'command_version' => $action->commandVersion,
            'hooks' => array_map(HookJson::toArray(...), $description->hooks),
            'kind' => $action->kind->value,
            'package' => $action->package,
            'permission' => $description->permission->value,
            'surfaces' => array_map(static fn (Surface $surface): string => $surface->value, $action->surfaces),
        ];
    }
}
