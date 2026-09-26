<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Tooling;

use DOMDocument;
use UnexpectedValueException;

/**
 * Reads the names of the test suites from a phpunit.xml, so the tests can hold the local profile
 * to it.
 */
final readonly class PhpunitSuites
{
    /**
     * @return list<string>
     */
    public static function in(string $file): array
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

        $names = [];

        foreach ($document->getElementsByTagName('testsuite') as $suite) {
            if ($suite->getAttribute('name') !== '') {
                $names[] = $suite->getAttribute('name');
            }
        }

        return $names;
    }
}
