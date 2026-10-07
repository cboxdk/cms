<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActivateActorCodecV1;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;

// A command is exposed in the panel by its JSON Schema (GUARDRAILS 2.2, PRD 6.1): the schema the
// command's generated codec carries is what the generic command form renders, so a command needs
// no page of its own. The form reads the schema's title and description as the form's, each
// property's description as its field's, and the examples of a patterned string as the field's
// hint; the panel's catalogue gives the translated texts when it has the keys of the command and
// its fields, and the form falls back to the schema's own. actor.activate.v1.json is such a schema:
// every property has a description, and both catalogues have its texts.

const EXAMPLE_ROOT = __DIR__.'/../../..';

/**
 * @return array<string, mixed>
 */
function commandFormSchema(): array
{
    return json_decode(ActivateActorCodecV1::SCHEMA, true, 32, JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, string>
 */
function panelCatalogue(string $locale): array
{
    $decoded = json_decode((string) file_get_contents(EXAMPLE_ROOT.'/js/panel/src/i18n/catalogues/'.$locale.'.json'), true, 16, JSON_THROW_ON_ERROR);
    $texts = [];

    foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $texts[$key] = $value;
        }
    }

    return $texts;
}

it('carries the schema the form is rendered from, with a title and a description of every field', function (): void {
    $schema = commandFormSchema();
    $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

    expect(ActivateActorCodecV1::COMMAND)->toBe('actor.activate')
        ->and($schema['title'])->toBe('actor.activate, contract version 1')
        ->and($schema['description'])->toBeString()
        ->and(array_keys($properties))->toBe(['actor', 'version'])
        ->and($schema['required'])->toBe(['actor', 'version'])
        ->and($schema['additionalProperties'])->toBeFalse();

    foreach ($properties as $key => $property) {
        expect($property)->toBeArray()->toHaveKey('description', message: "The field {$key} has a description.");
    }
});

it('reads the document the form submits, as every surface reads it', function (): void {
    $command = new ActivateActorCodecV1()->decode('{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","version":1}', ClassificationAccess::Public);

    expect($command)->toBeInstanceOf(ActivateActor::class)
        ->and($command->actor->toString())->toBe('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01')
        ->and($command->version->value)->toBe(1);
});

it('has the texts of the command and each of its fields in both catalogues', function (string $locale): void {
    $texts = panelCatalogue($locale);
    $schema = commandFormSchema();
    $properties = is_array($schema['properties'] ?? null) ? array_keys($schema['properties']) : [];

    expect($texts)->toHaveKeys(['panel.action.actor.activate.title', 'panel.action.actor.activate.description']);

    foreach ($properties as $field) {
        expect($texts)->toHaveKeys([
            'panel.action.actor.activate.field.'.$field.'.label',
            'panel.action.actor.activate.field.'.$field.'.description',
        ]);
    }
})->with(['en', 'da']);
