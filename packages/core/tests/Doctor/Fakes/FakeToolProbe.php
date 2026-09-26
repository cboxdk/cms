<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\ToolProbe;

/**
 * Node, Playwright and Chromium as the test sets them; a null version or path is missing.
 */
final class FakeToolProbe implements ToolProbe
{
    public function __construct(
        public ?string $node = '22.22.3',
        public ?string $playwright = '1.63.0',
        public ?string $chromium = '/ms-playwright/chromium-1243/chrome',
    ) {}

    public function nodeVersion(): string
    {
        return $this->node ?? throw ProbeFailed::violation('There is no node on PATH (/usr/bin:/bin).');
    }

    public function playwrightVersion(): string
    {
        return $this->playwright ?? throw ProbeFailed::violation('The playwright package cannot be loaded from /app: Cannot find module.');
    }

    public function chromiumExecutable(): string
    {
        return $this->chromium ?? throw ProbeFailed::violation('Playwright expects Chromium at /ms-playwright/chromium-1243/chrome, which does not exist.');
    }
}
