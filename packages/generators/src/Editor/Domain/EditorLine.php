<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Editor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * The first line of a blueprint file that points editors at the blueprint schema (PRD 14.1,
 * blueprint decision 3): `# yaml-language-server: $schema=<path>`, with the path relative to the
 * file's directory. yaml-language-server, the language server of Red Hat's YAML extension, reads
 * it; YAML reads it as a comment, so cms:generate does not see it.
 *
 * apply() puts the line first and keeps every other byte of the file. It owns the lines of the
 * file's leading block, the blank lines, comments, directives and document marker before the
 * first content, that set yaml-language-server's `$schema`: they are replaced by the one line,
 * never added to. A line further down is content or a comment inside it and is never touched. A
 * byte order mark stays first, and the line ends as the file's first line does, CRLF or LF.
 */
#[Internal]
final readonly class EditorLine
{
    public const string PREFIX = '# yaml-language-server: $schema=';

    private const string BYTE_ORDER_MARK = "\u{FEFF}";

    /** A comment that sets yaml-language-server's `$schema`, as it reads one. */
    private const string SCHEMA_COMMENT = '/\A[ \t]*#[ \t]*yaml-language-server[ \t]*:[ \t]*\$schema=/';

    /** A line of the leading block: blank, a comment, a directive or a document start marker without content. */
    private const string LEADING = '/\A(?:[ \t]*(?:#.*)?|%.*|---(?:[ \t]+(?:#.*)?)?)\r?\n?\z/';

    public string $line;

    /**
     * @param  string  $schema  the path of the blueprint schema, relative to the file's directory
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig when the path is empty or has a line break
     */
    public function __construct(public string $schema)
    {
        if ($schema === '' || preg_match('/[\r\n]/', $schema) === 1) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf('The blueprint schema path "%s" cannot be written on one line.', $schema));
        }

        $this->line = self::PREFIX.$schema;
    }

    /**
     * The line for a file in the directory, pointing at the schema.
     *
     * @param  string  $schema  the absolute, canonical path of the blueprint schema
     * @param  string  $directory  the absolute, canonical directory of the blueprint file
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig
     */
    public static function towards(string $schema, string $directory): self
    {
        return new self(RelativePath::between($directory, $schema));
    }

    /**
     * The file's contents with this line first and without any other line of its leading block
     * that sets `$schema`. Applying it twice gives the same bytes as applying it once.
     */
    public function apply(string $contents): string
    {
        $mark = str_starts_with($contents, self::BYTE_ORDER_MARK) ? self::BYTE_ORDER_MARK : '';
        $lines = $this->lines(substr($contents, strlen($mark)));
        $kept = [];
        $leading = true;

        foreach ($lines as $line) {
            $leading = $leading && preg_match(self::LEADING, $line) === 1;

            if ($leading && preg_match(self::SCHEMA_COMMENT, $line) === 1) {
                continue;
            }

            $kept[] = $line;
        }

        return $mark.$this->line.$this->lineEnding($lines).implode('', $kept);
    }

    /**
     * The lines of the text, each with its line ending.
     *
     * @return list<string>
     */
    private function lines(string $text): array
    {
        $lines = preg_split('/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $lines === false ? [$text] : $lines;
    }

    /**
     * The line ending of the first line that has one, or LF.
     *
     * @param  list<string>  $lines
     */
    private function lineEnding(array $lines): string
    {
        foreach ($lines as $line) {
            if (str_ends_with($line, "\r\n")) {
                return "\r\n";
            }

            if (str_ends_with($line, "\n")) {
                return "\n";
            }
        }

        return "\n";
    }
}
