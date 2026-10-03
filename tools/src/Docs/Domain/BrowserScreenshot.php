<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * One browser screenshot of the documentation: a page of the panel as a Browser test of gate 8
 * shows it in Chromium, captured into docs/screenshots/<key>.png when the test runs with
 * CMS_DOCS_SCREENSHOTS=1 in the dev image (Screenshots::browser()).
 */
final readonly class BrowserScreenshot implements CapturedImage
{
    /**
     * @param  string  $key  lowercase words joined by hyphens; the file name without `.png`
     * @param  string  $caption  the text every page that embeds the shot uses as its alt text
     * @param  string  $test  the Browser test that captures it, repo-relative
     */
    public function __construct(
        public string $key,
        public string $caption,
        public string $test,
    ) {}

    public function path(): string
    {
        return DocsLayout::SCREENSHOTS.'/'.$this->key.'.png';
    }

    public function captureCommand(): string
    {
        return $this->test === '' ? '' : 'composer image:run -- env CMS_DOCS_SCREENSHOTS=1 vendor/bin/pest --testsuite=Browser '.$this->test;
    }
}
