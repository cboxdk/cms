<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Boundary;

use Cbox\Cms\Tooling\Docs\Domain\JsSuite;

/**
 * Reads the JS unit suite of gate 5 from a vitest.config.ts: the `include` patterns of the project
 * named JsSuite::PROJECT, each a string literal in single or double quotes. The configuration is a
 * TypeScript module, so it is read as text, not run: the project is the `test: { name: 'unit', ...
 * }` object, and its include is the array literal after `include:` in that object. A file that is
 * missing, or that has no such project or include, gives a suite that includes nothing, so every
 * TypeScript example on a page is then a finding.
 */
final readonly class VitestUnitSuite
{
    private const string PROJECT = '/name:\s*([\'"])'.JsSuite::PROJECT.'\1\s*,(?<body>.*?)\n\s*\}/s';

    private const string INCLUDE = '/\binclude:\s*\[(?<items>[^\]]*)\]/s';

    private const string LITERAL = '/([\'"])(?<pattern>[^\'"]+)\1/';

    public static function read(string $file): JsSuite
    {
        $source = is_file($file) ? file_get_contents($file) : false;

        if ($source === false || preg_match(self::PROJECT, $source, $project) !== 1 || preg_match(self::INCLUDE, $project['body'], $include) !== 1) {
            return JsSuite::none();
        }

        preg_match_all(self::LITERAL, $include['items'], $literals);

        return new JsSuite($literals['pattern']);
    }
}
