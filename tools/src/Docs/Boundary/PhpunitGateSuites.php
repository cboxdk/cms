<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Boundary;

use Cbox\Cms\Tooling\Check\Domain\LocalProfile;
use Cbox\Cms\Tooling\Docs\Domain\GateSuites;
use Cbox\Cms\Tooling\Docs\Domain\SuiteDirectory;
use Cbox\Cms\Tooling\Docs\Domain\SuiteSelection;
use DOMDocument;
use DOMElement;
use UnexpectedValueException;

/**
 * Reads the gate-5 suites (LocalProfile::SUITES) from a phpunit.xml: each suite's `<directory>`
 * elements with their prefix and suffix, its `<file>` and its `<exclude>` elements, as paths
 * relative to the file's directory.
 */
final readonly class PhpunitGateSuites
{
    /**
     * @param  list<string>  $names  the suites to read; a suite the file does not define is left out
     */
    public static function read(string $file, array $names = LocalProfile::SUITES): GateSuites
    {
        $document = new DOMDocument;
        $xml = is_file($file) ? file_get_contents($file) : false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $xml !== false && $xml !== '' && $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new UnexpectedValueException("{$file} is not a readable XML file.");
        }

        $suites = [];

        foreach ($document->getElementsByTagName('testsuite') as $suite) {
            if (! in_array($suite->getAttribute('name'), $names, true)) {
                continue;
            }

            $directories = [];
            $files = [];
            $excludes = [];

            foreach ($suite->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }

                $path = self::path($child->textContent);

                match ($child->tagName) {
                    'directory' => $directories[] = new SuiteDirectory(
                        $path,
                        $child->getAttribute('prefix'),
                        $child->hasAttribute('suffix') ? $child->getAttribute('suffix') : SuiteDirectory::SUFFIX,
                    ),
                    'file' => $files[] = $path,
                    'exclude' => $excludes[] = $path,
                    default => null,
                };
            }

            $suites[] = new SuiteSelection($suite->getAttribute('name'), $directories, $files, $excludes);
        }

        return new GateSuites($suites);
    }

    private static function path(string $text): string
    {
        $path = trim($text);

        return rtrim(str_starts_with($path, './') ? substr($path, 2) : $path, '/');
    }
}
