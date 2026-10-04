<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\Dto\DevAddons;
use Cbox\Cms\Panel\Domain\InvalidDevAddons;

/**
 * CBOX_CMS_PANEL_DEV_ADDONS as this process has it, and the application's environment.
 */
#[Internal]
interface DevServerProbe
{
    /**
     * The variable's text, or null when it is unset or empty.
     */
    public function setting(): ?string;

    /**
     * The dev servers the variable names.
     *
     * @throws InvalidDevAddons when the variable cannot be read
     */
    public function addons(): DevAddons;

    /**
     * The application's environment, such as local or production.
     */
    public function environment(): string;
}
