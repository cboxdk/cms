<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;

/**
 * The development tools of --dev: Node on PATH, and Playwright with its Chromium in the project's
 * node_modules (GUARDRAILS 7.1, gate 8 of GUARDRAILS 10).
 */
#[Internal]
interface ToolProbe
{
    /**
     * The version of the node on PATH, such as "22.13.0".
     *
     * @throws ProbeFailed violation when there is no node on PATH or it does not run
     */
    public function nodeVersion(): string;

    /**
     * The version of the playwright package the project installed, such as "1.63.0".
     *
     * @throws ProbeFailed violation when it is not installed
     */
    public function playwrightVersion(): string;

    /**
     * The path of the Chromium that Playwright launches, when it has been downloaded.
     *
     * @throws ProbeFailed violation when it has not been downloaded
     */
    public function chromiumExecutable(): string;
}
