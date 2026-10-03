<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Codecs\Themes;

use Cbox\Cms\Core\PanelThemes\Adapter\FileThemeSources;
use Cbox\Cms\Core\PanelThemes\Boundary\ThemeDocument;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenCatalogue;
use Cbox\Cms\Core\PanelThemes\Domain\InvalidTheme;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/*
 * A panel theme is validated against the theme's JSON Schema (PRD 13.4): js/ui-kit/src/generated/
 * theme.v1.json, which npm run generate:tokens writes from js/ui-kit/tokens.json, the catalogue
 * cms:build's ThemeDocument reads the same rules from. This holds the two to the same verdict on
 * every document: one that follows each rule, and one that breaks it, by opis/json-schema and by
 * the PHP reader, so a theme that passes cms:panel:theme:check is a theme an editor's schema
 * accepts, and the other way round.
 */

const THEME_SCHEMA = 'js/ui-kit/src/generated/theme.v1.json';

function themeCatalogue(): TokenCatalogue
{
    return new FileThemeSources()->catalogue();
}

function schemaAcceptsTheme(string $document): bool
{
    return ! new Validator()->validate(json_decode($document, false, 512, JSON_THROW_ON_ERROR), (string) file_get_contents(Codebase::root().'/'.THEME_SCHEMA))->error() instanceof ValidationError;
}

function readerAcceptsTheme(string $document): bool
{
    try {
        ThemeDocument::read(ThemeName::app(), $document, themeCatalogue());
    } catch (InvalidTheme) {
        return false;
    }

    return true;
}

/**
 * The names of the object at the path of keys in the schema.
 *
 * @return list<array-key>
 */
function schemaNames(mixed $schema, string ...$path): array
{
    foreach ($path as $key) {
        $schema = is_array($schema) ? $schema[$key] ?? null : null;
    }

    return is_array($schema) ? array_keys($schema) : [];
}

it('gives the verdict of the theme\'s JSON Schema on every document', function (string $document, bool $accepted): void {
    expect(schemaAcceptsTheme($document))->toBe($accepted)
        ->and(readerAcceptsTheme($document))->toBe($accepted);
})->with([
    'the empty theme' => ['{}', true],
    'tokens on the whole panel and on parts' => ['{"tokens":{"color-accent":{"light":"#1d6b47","dark":"#7fd0a6"},"radius-md":"4px"},"parts":{"task-screen":{"color-surface-raised":{"light":"#f4f8f6","dark":"#14201a"}}}}', true],
    'every type of value' => ['{"tokens":{"color-text":"oklch(20% 0.02 260)","space-4":"1.25rem","duration-fast":"80ms","font-weight-medium":"550","font-family":"Inter, \'Segoe UI\', sans-serif","focus-ring":"0 0 0 2px #ffffff, 0 0 0 4px #1d6b47","button-radius":"0"}}', true],
    'a component token' => ['{"tokens":{"button-radius":"9999px"}}', true],
    'a key other than tokens and parts' => ['{"name":"Acme"}', false],
    'a primitive token' => ['{"tokens":{"ref-white":"#000000"}}', false],
    'an unknown token' => ['{"tokens":{"color-brand":"#000000"}}', false],
    'a light value alone' => ['{"tokens":{"color-accent":{"light":"#1d6b47"}}}', false],
    'a third mode' => ['{"tokens":{"color-accent":{"light":"#1d6b47","dark":"#7fd0a6","dim":"#000000"}}}', false],
    'a colour of another form' => ['{"tokens":{"color-accent":"green"}}', false],
    'a colour in upper case' => ['{"tokens":{"color-accent":"#1D6B47"}}', false],
    'a length of another unit' => ['{"tokens":{"space-4":"2vw"}}', false],
    'a duration in seconds' => ['{"tokens":{"duration-fast":"0.1s"}}', false],
    'a shadow with a reference' => ['{"tokens":{"focus-ring":"0 0 0 2px {color-surface}"}}', false],
    'a number for a colour' => ['{"tokens":{"color-accent":3}}', false],
    'an unknown part' => ['{"parts":{"shell-brand":{"color-text":"#000000"}}}', false],
    'a primitive on a part' => ['{"parts":{"task-screen":{"ref-white":"#000000"}}}', false],
    'tokens that are a list' => ['{"tokens":["color-accent"]}', false],
]);

it('reads the token names and parts the schema names', function (): void {
    $schema = json_decode((string) file_get_contents(Codebase::root().'/'.THEME_SCHEMA), true, 512, JSON_THROW_ON_ERROR);
    $catalogue = themeCatalogue();
    $themeable = [];

    foreach ($catalogue->tokens as $token) {
        if ($token->themeable()) {
            $themeable[] = $token->name;
        }
    }

    expect(schemaNames($schema, '$defs', 'tokens', 'properties'))->toBe($themeable)
        ->and(schemaNames($schema, 'properties', 'parts', 'properties'))->toBe($catalogue->parts);
});
