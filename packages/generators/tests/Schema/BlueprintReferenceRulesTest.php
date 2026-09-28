<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;

/*
 * The reference page of the blueprint schema v1, docs/addons/blueprint-v1.md at the root of the
 * repository, lists under "Rules across values and files" every rule cms:generate checks
 * beyond JSON Schema. Its table names exactly the error codes cms:generate refuses a schema with,
 * besides the schema's own `generate_schema_invalid` and `generate_schema_unsupported_version`, so
 * a rule the code adds or drops cannot leave the page behind.
 */

function referencePage(): string
{
    return (string) file_get_contents(__DIR__.'/../../../../docs/addons/blueprint-v1.md');
}

/**
 * The error codes in the last column of the rules table, in the order of the page.
 *
 * @return list<string>
 */
function referenceRuleCodes(): array
{
    $page = referencePage();
    $start = strpos($page, "\n## Rules across values and files\n");
    expect($start)->not->toBeFalse();

    $section = substr($page, (int) $start + 1);
    $end = strpos($section, "\n## ", 1);
    $section = $end === false ? $section : substr($section, 0, $end);

    preg_match_all('/^\| .+ \| `(generate_[a-z_]+)` \|$/m', $section, $matches);

    return $matches[1];
}

it('lists every error code cms:generate refuses a schema with beyond JSON Schema, and no other', function (): void {
    $codes = [];

    foreach (GenerateErrorCode::cases() as $code) {
        if (GenerateCommand::exitCode($code) === GenerateCommand::EXIT_INVALID_SCHEMA
            && ! in_array($code, [GenerateErrorCode::SchemaInvalid, GenerateErrorCode::SchemaUnsupportedVersion], true)) {
            $codes[] = $code->value;
        }
    }

    $listed = referenceRuleCodes();
    sort($codes);
    $sorted = $listed;
    sort($sorted);

    expect($sorted)->toBe($codes)
        ->and(array_unique($listed))->toBe($listed)
        ->and($listed)->not->toContain('generate_handle_collision');
});

it('says that two owners may each have a type with the same handle', function (): void {
    expect(referencePage())
        ->toContain('Two owners may each have a type with the same handle: the generated code names a type by its owner and handle, `<owner>:<handle>`');
});
