<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * An image of the documentation that a command captures, never one drawn by hand: a terminal
 * screenshot (Screenshot) or a browser screenshot of the panel (BrowserScreenshot). ScreenshotAudit
 * holds each to its file in docs/screenshots and to the pages that embed it with its caption.
 */
interface CapturedImage
{
    /** Lowercase words joined by hyphens; the file name without its extension. */
    public string $key { get; }

    /** The text every page that embeds the image uses as its alt text. */
    public string $caption { get; }

    /**
     * The path of the image, repo-relative.
     */
    public function path(): string;

    /**
     * The command that captures the image again, as a developer types it, or the empty string when
     * the entry names none.
     */
    public function captureCommand(): string;
}
