<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Progress\Domain;

/**
 * The entries of PROGRESS.md by section. A section starts at a level-two heading (`## Kontroller
 * kørt`); an entry is a list item that starts at the beginning of a line (`- `) with the lines
 * that follow it up to the next list item, a blank line or a heading. Text outside a list item,
 * such as a section's introduction, is no entry.
 */
final readonly class ProgressLedger
{
    /**
     * Where the open decisions wait for Sylvester. Before CHECKS-LOG.md existed, the changed
     * checks of a task were recorded here too (GUARDRAILS 7.3).
     */
    public const string REVIEW = 'Til review af Sylvester';

    /** Where the gates a task ran are recorded with their results. */
    public const string CHECKS_RUN = 'Kontroller kørt';

    /**
     * @param  array<string, list<string>>  $sections  the entries of each section, by heading
     */
    private function __construct(private array $sections) {}

    public static function fromMarkdown(string $markdown): self
    {
        $sections = [];
        $section = null;
        $entry = null;

        foreach ([...(preg_split('/\R/', $markdown) ?: []), ''] as $line) {
            $continues = $entry !== null && trim($line) !== '' && ! str_starts_with($line, '#') && ! str_starts_with($line, '- ');

            if ($continues) {
                $entry .= ' '.trim($line);

                continue;
            }

            if ($section !== null && $entry !== null) {
                $sections[$section][] = $entry;
            }

            $entry = null;

            if (str_starts_with($line, '## ')) {
                $section = trim(substr($line, 3));
                $sections[$section] ??= [];
            } elseif ($section !== null && str_starts_with($line, '- ')) {
                $entry = substr($line, 2);
            }
        }

        return new self($sections);
    }

    public function hasSection(string $section): bool
    {
        return array_key_exists($section, $this->sections);
    }

    /**
     * @return list<string>
     */
    public function entries(string $section): array
    {
        return $this->sections[$section] ?? [];
    }

    /**
     * The entries of a section here that the earlier ledger does not have, in order: a new entry,
     * or an entry whose text changed. Each entry of the earlier ledger matches one entry here.
     *
     * @return list<string>
     */
    public function addedSince(self $earlier, string $section): array
    {
        $remaining = array_count_values($earlier->entries($section));
        $added = [];

        foreach ($this->entries($section) as $entry) {
            if (($remaining[$entry] ?? 0) > 0) {
                $remaining[$entry]--;
            } else {
                $added[] = $entry;
            }
        }

        return $added;
    }
}
