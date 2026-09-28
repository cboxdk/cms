<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * One screenshot of the documentation: the output of a command in a terminal, captured by
 * `composer docs:screenshots` into docs/screenshots/<key>.svg (Screenshots).
 */
final readonly class Screenshot
{
    /** The width of the terminal in columns, unless a shot says otherwise. */
    public const int COLUMNS = 120;

    /**
     * @param  string  $key  lowercase words joined by hyphens; the file name without `.svg`
     * @param  list<string>  $command  the command line, run from the repository root and shown on the prompt line
     * @param  string  $caption  the text every page that embeds the shot uses as its alt text
     * @param  int  $exitCode  the exit code the command must end with, or the capture fails and writes nothing
     */
    public function __construct(
        public string $key,
        public array $command,
        public string $caption,
        public int $exitCode = 0,
        public int $columns = self::COLUMNS,
    ) {}

    /**
     * The path of the image, repo-relative.
     */
    public function path(): string
    {
        return DocsLayout::SCREENSHOTS.'/'.$this->key.'.svg';
    }

    /**
     * The command line as a developer types it.
     */
    public function commandLine(): string
    {
        return implode(' ', $this->command);
    }
}
