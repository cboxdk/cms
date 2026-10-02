<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\TestDatabase\Boundary;

use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\Infrastructure\TestDatabaseSetup;
use DOMDocument;
use DOMElement;
use UnexpectedValueException;

/**
 * The owner role's connection to the configured test database as the Pest suites see it, for the
 * tools that run outside them, such as the selftest's clean-up.
 *
 * The values are the `<env>` elements of phpunit.xml, where a variable that is already set in the
 * environment wins unless the element says force="true", as PHPUnit applies them, and the owner
 * connection is built from them as WorkbenchServiceProvider builds `pgsql_owner`: the schema of
 * DB_SCHEMA first in its search path, and the credential store's schema, cms_identity, after it.
 * tests/Feature/Tooling/TestDatabaseToolsTest.php holds the two equal.
 */
final readonly class PhpunitDatabase
{
    /**
     * @param  array<string, string>  $environment  the process environment, such as getenv()
     */
    public static function owner(string $phpunitXml, array $environment): ConnectionSettings
    {
        $variables = self::variables($phpunitXml, $environment);
        $port = $variables['DB_PORT'] ?? '5432';

        return new ConnectionSettings(
            name: 'pgsql_owner',
            host: self::nonEmpty($variables, 'DB_HOST') ?? '127.0.0.1',
            port: ctype_digit($port) ? (int) $port : throw new UnexpectedValueException("DB_PORT is not a port: {$port}."),
            database: self::nonEmpty($variables, 'DB_DATABASE') ?? throw new UnexpectedValueException("{$phpunitXml} sets no DB_DATABASE."),
            username: self::nonEmpty($variables, 'DB_OWNER_USERNAME') ?? 'cms_owner',
            password: $variables['DB_OWNER_PASSWORD'] ?? '',
            searchPath: self::schema($variables).','.TestDatabaseSetup::IDENTITY_SCHEMA,
        );
    }

    /**
     * The kernel's schema of the suites' connections, DB_SCHEMA.
     *
     * @param  array<string, string>  $variables  what variables() returns
     */
    public static function schema(array $variables): string
    {
        return self::nonEmpty($variables, 'DB_SCHEMA') ?? 'cms';
    }

    /**
     * The environment the suites run with: phpunit.xml's `<env>` values under the process
     * environment, which wins unless an element is forced.
     *
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    public static function variables(string $phpunitXml, array $environment): array
    {
        $xml = is_file($phpunitXml) ? file_get_contents($phpunitXml) : false;
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $loaded = $xml !== false && $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new UnexpectedValueException("Cannot read {$phpunitXml}.");
        }

        $variables = $environment;

        foreach ($document->getElementsByTagName('env') as $element) {
            if (! $element->parentNode instanceof DOMElement || $element->parentNode->tagName !== 'php') {
                continue;
            }

            $name = $element->getAttribute('name');
            $force = in_array(strtolower($element->getAttribute('force')), ['true', '1'], true);

            if ($name !== '' && ($force || ! array_key_exists($name, $environment))) {
                $variables[$name] = $element->getAttribute('value');
            }
        }

        return $variables;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private static function nonEmpty(array $variables, string $name): ?string
    {
        $value = $variables[$name] ?? '';

        return $value === '' ? null : $value;
    }
}
