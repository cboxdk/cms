<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use RuntimeException;

/**
 * cms:panel:points or cms:panel:fills was asked for a panel point, or a page, that the registry
 * holds no point of: no #[PanelPoint] class declares it, or it was added since the last cms:build.
 */
#[Experimental]
final class UnknownPanelPoint extends RuntimeException
{
    /**
     * @param  list<PointId>  $registered  the ids of the registered points
     */
    public static function notRegistered(PointId $point, array $registered): self
    {
        return new self(sprintf('No panel point is registered as %s. %s', $point->toString(), self::registered($registered)));
    }

    /**
     * @param  list<PointId>  $registered  the ids of the registered points
     */
    public static function noneOn(PageName $page, array $registered): self
    {
        return new self(sprintf('No registered panel point is named %s or rendered by a page of that name. %s', $page->value, self::registered($registered)));
    }

    /**
     * @param  list<PointId>  $registered
     */
    private static function registered(array $registered): string
    {
        return ($registered === []
            ? 'The registry holds no panel points.'
            : 'The registered panel points are '.implode(', ', array_map(static fn (PointId $point): string => $point->toString(), $registered)).'.')
            .' Run php artisan cms:build when the point was declared since the last build.';
    }
}
