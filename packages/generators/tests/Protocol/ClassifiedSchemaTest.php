<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Generators\Codec\Domain\CodecKind;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecObject;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Codec\Domain\PhpCodecEmitter;
use Cbox\Cms\Generators\Codec\Domain\PhpDtoEmitter;
use Cbox\Cms\Generators\Codec\Domain\TypeScriptEmitter;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Boundary\JsonSchemaContract;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\Locker;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\Lockers;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\PlainLocker;
use LogicException;

/*
 * A property of a kernel JSON Schema withheld above a classification (PRD 12.2, GUARDRAILS 2.2):
 * `x-cms-classification` names a class above public; the key is not required, has no default and
 * is never null; the bound class holds the value or Omitted, and a class that holds such a
 * property, in an object or in a list, is classified, so the codec reads it with the reader's
 * access and writes what visibleTo() leaves. Anything else is refused with
 * generate_schema_invalid.
 */

/**
 * The probe schema: a list of lockers, each with a name and a code classified as given.
 *
 * @param  array<string, mixed>  $code  the schema of the code
 * @param  list<string>  $required  the required keys of a locker
 */
function lockerSchema(array $code = ['type' => 'string', 'x-cms-classification' => 'personal'], array $required = ['name']): string
{
    return (string) json_encode([
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'title' => 'probe.lockers, contract version 1',
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['lockers'],
        'properties' => ['lockers' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/locker']]],
        '$defs' => [
            'locker' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => $required,
                'properties' => ['code' => $code, 'name' => ['type' => 'string']],
            ],
        ],
    ], JSON_UNESCAPED_SLASHES);
}

/**
 * @param  class-string  $locker
 */
function lockerBinding(string $locker = Locker::class): SchemaBinding
{
    return new SchemaBinding(schema: 'probe.lockers.v1.json', codecClass: 'LockersCodecV1', version: 1, objects: ['#' => Lockers::class, '#/$defs/locker' => $locker]);
}

function lockerContract(): CodecContract
{
    return JsonSchemaContract::read(lockerSchema(), lockerBinding(), Experimental::class);
}

/**
 * The message of the problem the reader gives for the schema and binding.
 */
function lockerProblem(string $json, SchemaBinding $binding): string
{
    try {
        JsonSchemaContract::read($json, $binding, Experimental::class);
    } catch (GenerationFailed $failed) {
        expect($failed->codes())->toBe([GenerateErrorCode::SchemaInvalid]);

        return $failed->problems[0]->message;
    }

    throw new LogicException('The schema was read.');
}

it('reads a classified property as required where the reader may see it, withheld above its class, and the object that holds it in a list as classified', function (): void {
    $contract = lockerContract();
    $locker = $contract->root->properties[0]->value->item?->object;
    $code = $locker?->properties[0];

    expect($code?->key)->toBe('code')
        ->and($code?->required)->toBeTrue()
        ->and($code?->classification)->toBe(ClassificationAccess::Personal)
        ->and($code?->withheld())->toBeTrue()
        ->and($code?->mayBeNull())->toBeFalse()
        ->and($locker?->classified())->toBeTrue()
        ->and($contract->root->classified())->toBeTrue();
});

it('emits a codec that reads the classified property with the reader\'s access and writes what visibleTo() leaves', function (): void {
    $contract = lockerContract();
    $codec = PhpCodecEmitter::emit($contract, new PhpLocation('Generated', 'Cbox\Cms\Probe\Generated'), new PhpLocation('Generated', 'Cbox\Cms\Probe\Generated'))->contents;
    $typescript = TypeScriptEmitter::emit($contract, 'protocol/LockersV1.ts', '../validation', ['The lockers.'])->contents;

    expect($codec)->toContain('return JsonText::encode($this->encodeLockers($dto->visibleTo($access)));')
        ->toContain("JsonValues::requiredClassified(\$object, 'code', \$path, ClassificationAccess::Personal, \$access,")
        ->toContain('$this->decodeLocker($item, $itemAt, $access)')
        ->toContain('if (! $object->code instanceof Omitted) {')
        ->and($typescript)->toContain('code?: string;')
        ->toContain("presence: 'omittable'");
});

it('refuses a classification that is not a class above public, a classified key that is required, has a default or may be null, and a class that cannot hold Omitted', function (): void {
    expect(lockerProblem(lockerSchema(['type' => 'string', 'x-cms-classification' => 'public']), lockerBinding()))->toContain('that is not a classification above public')
        ->and(lockerProblem(lockerSchema(['type' => 'string', 'x-cms-classification' => 'secret']), lockerBinding()))->toContain('that is not a classification above public')
        ->and(lockerProblem(lockerSchema(required: ['code', 'name']), lockerBinding()))->toContain('a classified key is left out for a reader whose access does not allow it')
        ->and(lockerProblem(lockerSchema(['type' => 'string', 'default' => 'A', 'x-cms-classification' => 'personal']), lockerBinding()))->toContain('a classified key has no default')
        ->and(lockerProblem(lockerSchema(['type' => ['string', 'null'], 'x-cms-classification' => 'personal']), lockerBinding()))->toContain('a classified value is never null')
        ->and(lockerProblem(lockerSchema(), lockerBinding(PlainLocker::class)))->toContain('withheld above a classification, but '.PlainLocker::class.'::__construct($code) is not of the type string|');
});

it('generates a DTO whose visibleTo() shows every classified object of a list to the reader, and refuses a list of lists of them', function (): void {
    $contract = lockerContract();
    $files = PhpDtoEmitter::emit($contract, new PhpLocation('Dto', 'Cbox\Cms\Probe\Dto'));
    $lockers = array_first(array_filter($files, static fn (GeneratedFile $file): bool => $file->path === 'Dto/Lockers.php'));
    $nested = new CodecContract(new CodecObject('Shelves', ['Shelves of lockers.'], [
        new CodecProperty('shelves', 'shelves', CodecValue::list(CodecValue::list($contract->root->properties[0]->value->item ?? CodecValue::of(CodecKind::Text))), true, null, 'The shelves.'),
    ]), 'ShelvesCodecV1', 1, ['Shelves.']);

    expect($lockers?->contents)->toContain('lockers: array_map(static fn (Locker $item): Locker => $item->visibleTo($access), $this->lockers),')
        ->and(static fn (): array => PhpDtoEmitter::emit($nested, new PhpLocation('Dto', 'Cbox\Cms\Probe\Dto')))
        ->toThrow(GenerationFailed::class, 'The property shelves is a list of lists of classified objects');
});
