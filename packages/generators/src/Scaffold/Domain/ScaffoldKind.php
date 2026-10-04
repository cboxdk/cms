<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PointKind;

/**
 * The kinds of contribution cms:make:panel scaffolds, as the command names them: a fill of a slot,
 * an action, a form check and a flow step.
 */
#[Internal]
enum ScaffoldKind: string
{
    case Fill = 'fill';
    case Action = 'action';
    case Check = 'check';
    case Step = 'step';

    /**
     * The kind of point the contribution is on.
     */
    public function pointKind(): PointKind
    {
        return match ($this) {
            self::Fill => PointKind::Slot,
            self::Action => PointKind::Action,
            self::Check => PointKind::FormCheck,
            self::Step => PointKind::FlowStep,
        };
    }

    /**
     * The kind that scaffolds a contribution of the point kind, or null for a kind it does not.
     */
    public static function forPointKind(PointKind $kind): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->pointKind() === $kind) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Whether a contribution of the kind runs code in the panel, so it has a stub.
     */
    public function runsCode(): bool
    {
        return $this !== self::Action;
    }
}
