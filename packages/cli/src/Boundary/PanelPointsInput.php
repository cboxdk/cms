<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFillsRequest;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointsRequest;

/**
 * Reads the arguments of cms:panel:points and cms:panel:fills into their actions' requests. A
 * point's id is `<name>@<version>`, such as account.me.sections@1; cms:panel:points also takes a
 * name without a version, which selects a point's versions and the points a page of that name
 * renders. Anything else is a usage error, exit 64.
 */
#[Internal]
final readonly class PanelPointsInput
{
    /**
     * @throws CliCallRefused
     */
    public static function points(mixed $selector): PanelPointsRequest
    {
        if ($selector === null) {
            return new PanelPointsRequest;
        }

        if (! is_string($selector)) {
            throw CliCallRefused::usage('Give a page, a point\'s name or a point\'s id, such as account.me or account.me.sections@1.');
        }

        try {
            return str_contains($selector, '@')
                ? new PanelPointsRequest(point: PointId::fromString($selector))
                : new PanelPointsRequest(name: new PageName($selector));
        } catch (InvalidPanelPoint $invalid) {
            throw CliCallRefused::usage($invalid->getMessage(), $invalid);
        }
    }

    /**
     * @throws CliCallRefused
     */
    public static function fills(mixed $point): PanelFillsRequest
    {
        if (! is_string($point)) {
            throw CliCallRefused::usage('Give the id of a panel point, such as account.me.sections@1.');
        }

        try {
            return new PanelFillsRequest(PointId::fromString($point));
        } catch (InvalidPanelPoint $invalid) {
            throw CliCallRefused::usage($invalid->getMessage(), $invalid);
        }
    }
}
