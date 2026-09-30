<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Generators\Codec\Domain\CodecKind;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Codec\Domain\PhpCodecEmitter;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Boundary\JsonSchemaContract;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\Dto\ValueBinding;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use LogicException;
use Workbench\App\Cms\Generated\TypeHandle;

/*
 * The schema of a command's contract version (GUARDRAILS 2.1, 2.2, 2.4): the reader binds the
 * document to the command, takes the name from its #[Command] and refuses a version the attribute
 * does not give; a version is a value object of one integer with the schema's bounds; and the
 * fields of any type are exactly the definitions of FieldValuesSchema, which the reader refuses in
 * any other form, so every rule the schema states is one the codec checks.
 */

/** The fields property of the committed schema, as compact JSON. */
const REVISE_FIELDS = '"fields":{"description":"Every field of the new revision.","$ref":"#/$defs/fields"}';

/**
 * The committed schema of entry.revise as compact JSON, with each text of $replace replaced; a
 * text the schema does not have is refused, so a case never tests the schema unchanged.
 *
 * @param  array<string, string>  $replace
 */
function reviseSchema(array $replace = []): string
{
    $json = json_encode(json_decode((string) file_get_contents(dirname(__DIR__, 4).'/'.ProtocolSchemas::COMMAND_SCHEMA_DIRECTORY.'/entry.revise.v1.json'), false, 64, JSON_THROW_ON_ERROR), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    foreach ($replace as $from => $to) {
        if (! str_contains($json, $from)) {
            throw new LogicException('entry.revise.v1.json has no '.$from.'.');
        }

        $json = str_replace($from, $to, $json);
    }

    return $json;
}

/**
 * The binding of entry.revise, with the values and the command given.
 *
 * @param  array<string, ValueBinding>|null  $values
 * @param  positive-int  $version
 */
function reviseBinding(?array $values = null, ?string $command = ReviseEntry::class, int $version = 1): SchemaBinding
{
    $binding = array_first(array_filter(ProtocolSchemas::commands(), static fn (SchemaBinding $binding): bool => $binding->schema === 'entry.revise.v1.json'))
        ?? throw new LogicException('entry.revise.v1.json has no binding.');

    return new SchemaBinding($binding->schema, $binding->codecClass, $version, ['#' => $command ?? ReviseEntry::class], $values ?? $binding->values, [], $binding->directory, $command);
}

/**
 * @param  array<array-key, mixed>  $replace  each text of the schema to replace by its string
 */
function reviseProblem(array $replace = [], ?SchemaBinding $binding = null): string
{
    $replace = array_map(static fn (mixed $to): string => is_string($to) ? $to : throw new LogicException('A replacement is a string.'), $replace);

    try {
        JsonSchemaContract::read(reviseSchema(array_combine(array_map(strval(...), array_keys($replace)), $replace)), $binding ?? reviseBinding(), Experimental::class);
    } catch (GenerationFailed $failed) {
        expect($failed->codes())->toBe([GenerateErrorCode::SchemaInvalid]);

        return $failed->problems[0]->message;
    }

    throw new LogicException('The schema was read without a problem.');
}

it('reads a command\'s schema with its name from #[Command], its version as an integer value and its fields', function (): void {
    $contract = JsonSchemaContract::read(reviseSchema(), reviseBinding(), Experimental::class);
    $kinds = [];

    foreach ($contract->root->properties as $property) {
        $kinds[$property->key] = [$property->value->kind, $property->value->class, array_map(static fn (object $rule): string => $rule->name->value.':'.implode(',', $rule->arguments), $property->value->rules)];
    }

    expect($contract->command?->name)->toBe('entry.revise')
        ->and(json_decode($contract->command->schema ?? '', true, 64, JSON_THROW_ON_ERROR))->toBe(json_decode(reviseSchema(), true, 64, JSON_THROW_ON_ERROR))
        ->and($kinds)->toBe([
            'entry' => [CodecKind::Id, EntryId::class, []],
            'fields' => [CodecKind::Fields, FieldValues::class, []],
            'version' => [CodecKind::IntegerValue, AggregateVersion::class, ['min:1']],
        ])
        ->and(array_map(static fn (CodecProperty $property): string => $property->name, $contract->root->constructorOrder()))->toBe(['entry', 'version', 'fields']);
});

it('writes the command\'s schema into its codec in a nowdoc, and refuses one that contains the delimiter', function (): void {
    $contract = JsonSchemaContract::read(reviseSchema(), reviseBinding(), Experimental::class);
    $location = new PhpLocation('Generated', 'Cbox\Cms\Probe\Generated');
    $file = PhpCodecEmitter::emit($contract, $location, $location);
    $changed = JsonSchemaContract::read(reviseSchema(['"title":"entry.revise, contract version 1"' => '"title":"The SCHEMA of entry.revise"']), reviseBinding(), Experimental::class);

    expect($file->contents)->toContain("    public const string COMMAND = 'entry.revise';\n")
        ->and($file->contents)->toContain("    public const string SCHEMA = <<<'SCHEMA'\n        {\n")
        ->and($file->contents)->toContain("\n        SCHEMA;\n")
        ->and($file->contents)->toContain('return new CommandCodec(new CommandName(self::COMMAND), self::VERSION, new self, new JsonSchema(self::SCHEMA));')
        ->and(static fn (): CodecContract => $changed)->not->toThrow(GenerationFailed::class);

    try {
        PhpCodecEmitter::emit($changed, $location, $location);
    } catch (GenerationFailed $failed) {
        expect($failed->codes())->toBe([GenerateErrorCode::InvalidOutput])
            ->and($failed->problems[0]->message)->toContain('contains SCHEMA');

        return;
    }

    throw new LogicException('A schema with the delimiter of its nowdoc was emitted.');
});

it('refuses a command\'s schema that does not fit its command or the fields of FieldValuesSchema', function (array $replace, ?SchemaBinding $binding, string $message): void {
    expect(reviseProblem($replace, $binding))->toBe('entry.revise.v1.json '.$message.'.');
})->with([
    'a version #[Command] does not give' => [[], reviseBinding(version: 2), '#: is version 2 of a command, but #[Command] of Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry gives version 1'],
    'a command that is not the document\'s class' => [[], new SchemaBinding('entry.revise.v1.json', 'ReviseEntryCodecV1', 1, ['#' => ReviseEntry::class], reviseBinding()->values, [], ProtocolSchemas::COMMAND_SCHEMA_DIRECTORY, TypeHandle::class), '#: is the schema of the command Workbench\App\Cms\Generated\TypeHandle, which is not a class or not the class the document is bound to'],
    'fields bound to another class' => [[], reviseBinding([...reviseBinding()->values, '#/properties/fields' => ValueBinding::fields(EntryId::class)]), '#/properties/fields: is bound as fields to Cbox\Cms\Contracts\Ids\EntryId; fields are Cbox\Cms\Contracts\Fields\FieldValues'],
    'fields with a rule of their own' => [[REVISE_FIELDS => '"fields":{"$ref":"#/$defs/fields","minProperties":1}'], null, '#/properties/fields: has the keyword "minProperties", which the codec has no form for'],
    'fields that are not the reference' => [[REVISE_FIELDS => '"fields":{"type":"object","additionalProperties":false}'], null, '#/properties/fields: is bound as fields, but is not {"$ref": "#/$defs/fields"} with a description at most'],
    'a handle without its length' => [['"maxLength":63,' => ''], null, '#/$defs/field_handle: is not the definition FieldValuesSchema gives the fields of a revision; copy it from there'],
    'a value that may be a number' => [['"array","object"]' => '"array","object","number"]'], null, '#/$defs/field_value: is not the definition FieldValuesSchema gives the fields of a revision; copy it from there'],
    'no definition of the extension fields' => [['"extension_fields":{' => '"other_fields":{'], null, '#/$defs/extension_fields: is not the definition FieldValuesSchema gives the fields of a revision; copy it from there'],
    'a version bound to a value of a string' => [[], reviseBinding([...reviseBinding()->values, '#/properties/version' => ValueBinding::value(TypeHandle::class)]), '#/properties/version: is bound to Workbench\App\Cms\Generated\TypeHandle as an integer value, which has no constructor of one int and public int $value'],
    'a version with a minimum that is not an integer' => [['"minimum":1' => '"minimum":0.5'], null, '#/properties/version: has a "minimum" that is not an integer of 0 or more'],
]);
