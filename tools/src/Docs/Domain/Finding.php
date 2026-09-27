<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

use Stringable;

/**
 * One finding of the documentation check. It prints as `<file>:<line>: <message>` when it has a
 * place in a file, and as `<extension point>: <message>` when it is about an extension point or an
 * exclusion, such as `Cbox\Cms\Contracts\Clock: undocumented`.
 */
final readonly class Finding implements Stringable
{
    private function __construct(
        public string $subject,
        public ?int $line,
        public string $message,
    ) {}

    /**
     * @param  string  $file  repo-relative
     */
    public static function at(string $file, int $line, string $message): self
    {
        return new self($file, $line, $message);
    }

    public static function about(string $extensionPoint, string $message): self
    {
        return new self($extensionPoint, null, $message);
    }

    /**
     * Findings in files first, by file and line, then those about extension points, by name.
     *
     * @param  list<self>  $findings
     * @return list<self>
     */
    public static function sorted(array $findings): array
    {
        usort($findings, static fn (self $a, self $b): int => [$a->line === null, $a->subject, $a->line, $a->message] <=> [$b->line === null, $b->subject, $b->line, $b->message]);

        return $findings;
    }

    public function __toString(): string
    {
        return $this->line === null
            ? "{$this->subject}: {$this->message}"
            : "{$this->subject}:{$this->line}: {$this->message}";
    }
}
