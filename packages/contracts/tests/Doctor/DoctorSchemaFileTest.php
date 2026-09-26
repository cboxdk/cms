<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Doctor;

/*
 * GUARDRAILS 2.6 puts protocol schemas in the contracts package. The document of
 * `cms:doctor --json` is described by resources/schemas/doctor.v1.json; the Codecs suite checks the
 * encoder's output against it.
 */

/**
 * The value at a path of keys in the decoded schema, or null when the path does not exist.
 */
function schemaValue(mixed $schema, string ...$path): mixed
{
    foreach ($path as $key) {
        if (! is_array($schema) || ! array_key_exists($key, $schema)) {
            return null;
        }

        $schema = $schema[$key];
    }

    return $schema;
}

it('ships the JSON Schema of the doctor document, version 1', function (): void {
    $path = dirname(__DIR__, 2).'/resources/schemas/doctor.v1.json';
    $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    expect(schemaValue($schema, '$schema'))->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and(schemaValue($schema, 'required'))->toBe(['checks', 'dev', 'exit_code', 'status', 'version'])
        ->and(schemaValue($schema, 'properties', 'version', 'const'))->toBe(1);
});
