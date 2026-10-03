<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\DowncastsFromNewest;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use LogicException;

/**
 * PointDowncasts could not build the props of an older version of a panel point: the caller gave
 * props of another class than the newest version's, or the older version's class has no downcast or
 * one that builds something else. Each is a bug of the code that builds the props or of the
 * downcast, never of the viewer's input, and cms:build refuses an older version without a downcast.
 */
#[Experimental]
final class PointDowncastRefused extends LogicException
{
    public static function notNewest(PointId $target, PanelPointEntry $newest, object $props): self
    {
        return new self(sprintf(
            'The props of %s are built from the props of the newest version of its point, %s, which are %s. They were given %s.',
            $target->toString(),
            $newest->id()->toString(),
            $newest->class,
            $props::class,
        ));
    }

    public static function withoutDowncast(PointId $target): self
    {
        return new self(sprintf(
            'The panel point %s is an older version and its props class does not implement %s. Run php artisan cms:build, which refuses it.',
            $target->toString(),
            DowncastsFromNewest::class,
        ));
    }

    public static function builtOther(PointId $target, object $props): self
    {
        return new self(sprintf(
            'The downcast of the panel point %s built %s, which are not its props. A downcast builds the props of its own class.',
            $target->toString(),
            $props::class,
        ));
    }
}
