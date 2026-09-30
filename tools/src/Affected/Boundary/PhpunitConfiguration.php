<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Affected\Boundary;

use DOMDocument;
use DOMElement;
use DOMXPath;
use UnexpectedValueException;

/**
 * Derives a PHPUnit configuration from the checkout's phpunit.xml that leaves some test files out,
 * written to another directory: every relative path of phpunit.xml (the bootstrap, the cache
 * directory, and the directories, files and exclusions of the test suites and the source) becomes
 * absolute below the checkout, because PHPUnit resolves them against the configuration's own
 * directory, only the named test suites are kept, and each gets an `<exclude>` for each file left
 * out. Everything else is phpunit.xml's, so the runs keep its settings and environment.
 */
final readonly class PhpunitConfiguration
{
    /**
     * @param  list<string>  $suites  the names of the test suites to keep
     * @param  list<string>  $excluded  absolute paths of the test files to leave out
     */
    public static function without(string $xml, string $root, array $suites, array $excluded): string
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;

        if ($xml === '' || ! $document->loadXML($xml, LIBXML_NONET)) {
            throw new UnexpectedValueException('phpunit.xml is not XML.');
        }

        $phpunit = $document->documentElement;

        if (! $phpunit instanceof DOMElement || $phpunit->tagName !== 'phpunit') {
            throw new UnexpectedValueException('phpunit.xml has no <phpunit> root element.');
        }

        foreach (['bootstrap', 'cacheDirectory'] as $attribute) {
            if ($phpunit->hasAttribute($attribute)) {
                $phpunit->setAttribute($attribute, self::absolute($phpunit->getAttribute($attribute), $root));
            }
        }

        $xpath = new DOMXPath($document);
        $paths = $xpath->query('/phpunit/testsuites/testsuite/*[self::directory or self::file or self::exclude] | /phpunit/source//*[self::directory or self::file]');

        foreach ($paths === false ? [] : $paths as $node) {
            if ($node instanceof DOMElement) {
                $node->textContent = self::absolute(trim($node->textContent), $root);
            }
        }

        $found = $xpath->query('/phpunit/testsuites/testsuite');

        foreach ($found === false ? [] : $found as $suite) {
            if (! $suite instanceof DOMElement) {
                continue;
            }

            if (! in_array($suite->getAttribute('name'), $suites, true)) {
                $suite->parentNode?->removeChild($suite);

                continue;
            }

            foreach ($excluded as $file) {
                $suite->appendChild($document->createElement('exclude'))->textContent = $file;
            }
        }

        $written = $document->saveXML();

        if ($written === false) {
            throw new UnexpectedValueException('Cannot write the derived PHPUnit configuration.');
        }

        return $written;
    }

    private static function absolute(string $path, string $root): string
    {
        return str_starts_with($path, '/') ? $path : $root.'/'.$path;
    }
}
