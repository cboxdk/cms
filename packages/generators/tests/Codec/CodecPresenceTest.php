<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Codec;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Generators\Codec\Domain\CodecKind;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecObject;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Codec\Domain\PhpCodecEmitter;
use Cbox\Cms\Generators\Codec\Domain\PhpSource;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\Box;
use LogicException;

/*
 * The presence of a property in the codec model (GUARDRAILS 2.2): required, required and nullable,
 * with a default, nullable or not, or optional and Omitted, and what each gives the PHP type; a
 * bound object's arguments in its constructor's order; and a property withheld above a
 * classification, which can be neither nullable nor defaulted.
 */

function presenceProperty(string $key, bool $required, bool $nullable = false, ?string $default = null, ?ClassificationAccess $classification = null): CodecProperty
{
    return new CodecProperty($key, $key, CodecValue::of(CodecKind::Integer, [new ValidationRule(ValidationRuleName::Integer)]), $required, $classification, 'A number.', $nullable, $default);
}

it('gives each presence its PHP type, whether it may be null and whether it may be Omitted', function (CodecProperty $property, string $type, bool $null, bool $omitted): void {
    expect(PhpSource::propertyNative($property))->toBe($type)
        ->and($property->mayBeNull())->toBe($null)
        ->and($property->omittable())->toBe($omitted);
})->with([
    'required' => [presenceProperty('a', true), 'int', false, false],
    'required and nullable' => [presenceProperty('a', true, nullable: true), 'int|null', true, false],
    'with a default' => [presenceProperty('a', false, default: '3'), 'int', false, false],
    'with a default, nullable' => [presenceProperty('a', false, nullable: true, default: 'null'), 'int|null', true, false],
    'optional' => [presenceProperty('a', false), 'int|Omitted|null', true, true],
    'required and withheld' => [presenceProperty('a', true, classification: ClassificationAccess::Internal), 'int|Omitted', false, true],
]);

it('orders a bound object\'s arguments as its constructor does, and a generated object\'s by key', function (): void {
    $properties = [presenceProperty('c', true), presenceProperty('a', true), presenceProperty('b', true)];
    $bound = new CodecObject('Box', [], $properties, Box::class, ['b', 'c', 'a']);
    $generated = new CodecObject('Box', [], $properties);
    $unknown = new CodecObject('Box', [], $properties, Box::class, ['c']);
    $keys = static fn (CodecObject $object): array => array_map(static fn (CodecProperty $property): string => $property->key, $object->constructorOrder());

    expect($keys($bound))->toBe(['b', 'c', 'a'])
        ->and($keys($generated))->toBe(['a', 'b', 'c'])
        ->and($keys($unknown))->toBe(['c', 'a', 'b'])
        ->and(array_map(static fn (CodecProperty $property): string => $property->key, $bound->properties))->toBe(['a', 'b', 'c']);
});

it('refuses a withheld property that is nullable or has a default', function (CodecProperty $property): void {
    $contract = new CodecContract(new CodecObject('Probe', ['A probe.'], [$property]), 'ProbeCodecV1', 1, ['A codec.']);
    $location = new PhpLocation('Generated', 'Cbox\Cms\Probe\Generated');

    try {
        PhpCodecEmitter::emit($contract, $location, $location);
    } catch (GenerationFailed $failed) {
        expect($failed->codes())->toBe([GenerateErrorCode::InvalidOutput])
            ->and($failed->problems[0]->message)->toBe('The property a is withheld above a classification, so it cannot be nullable or have a default.');

        return;
    }

    throw new LogicException('The property was emitted.');
})->with([
    'nullable' => [presenceProperty('a', true, nullable: true, classification: ClassificationAccess::Internal)],
    'with a default' => [presenceProperty('a', false, default: '3', classification: ClassificationAccess::Confidential)],
]);

it('writes the codec\'s attribute before its class, and none without one', function (): void {
    $object = new CodecObject('Probe', ['A probe.'], [presenceProperty('a', true)]);
    $location = new PhpLocation('Generated', 'Cbox\Cms\Probe\Generated');
    $with = PhpCodecEmitter::emit(new CodecContract($object, 'ProbeCodecV1', 1, ['A codec.'], Experimental::class), $location, $location)->contents;
    $without = PhpCodecEmitter::emit(new CodecContract($object, 'ProbeCodecV1', 1, ['A codec.']), $location, $location)->contents;

    expect($with)->toContain("use Cbox\\Cms\\Contracts\\Attributes\\Experimental;\n")
        ->and($with)->toContain(" */\n#[Experimental]\nfinal readonly class ProbeCodecV1 implements JsonCodec\n")
        ->and($without)->not->toContain('Experimental')
        ->and($without)->toContain(" */\nfinal readonly class ProbeCodecV1 implements JsonCodec\n");
});
