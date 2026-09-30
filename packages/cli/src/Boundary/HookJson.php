<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;

/**
 * A hook as cms:actions and cms:hooks print it (PRD 13.2): as a JSON object, keys sorted, with
 * the addon and what it reads null for a hook of a package without a manifest, and as a line.
 */
#[Internal]
final readonly class HookJson
{
    /**
     * @return array{addon: ?string, budget_ms: int, class: string, package: string, phase: string, priority: int, reads: ?string}
     */
    public static function toArray(HookEntry $hook): array
    {
        return [
            'addon' => $hook->addon?->value,
            'budget_ms' => $hook->budgetMs,
            'class' => $hook->class,
            'package' => $hook->package,
            'phase' => $hook->phase->value,
            'priority' => $hook->priority,
            'reads' => $hook->reads?->value,
        ];
    }

    /**
     * `<phase> priority <n> budget <n> ms <class> (<package>[, addon <namespace> reading up to <access>])`.
     */
    public static function line(HookEntry $hook): string
    {
        return sprintf(
            '%-9s  priority %d  budget %d ms  %s (%s%s)',
            $hook->phase->value,
            $hook->priority,
            $hook->budgetMs,
            $hook->class,
            $hook->package,
            ! $hook->addon instanceof AddonNamespace || ! $hook->reads instanceof ClassificationAccess ? '' : sprintf(', addon %s reading up to %s', $hook->addon->value, $hook->reads->value),
        );
    }
}
