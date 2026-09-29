<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Codec;

use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Generators\Codec\Domain\CodecKind;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecObject;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Codec\Domain\PhpCodecEmitter;
use Cbox\Cms\Generators\Codec\Domain\PhpDtoEmitter;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Probe\ProbeCodecV3;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Probe\ProbeRecord;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Probe\ProbeSecret;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Probe\ProbeStep;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Step;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Tone;
use Closure;
use DateTimeImmutable;
use LogicException;

/*
 * The DTO and codec emitters on a contract that is not a record (GUARDRAILS 2.2), as the generator
 * of another contract builds one: ids and enums, lists of decimals and of lists and a classified
 * object all go through encode() and back through decode(), and a rule that a kind of value has no
 * form for, or a key that is not a name, is refused rather than dropped or renamed.
 */

it('writes exactly the committed probe files', function (): void {
    foreach (ProbeContract::files() as $file) {
        expect(file_get_contents(__DIR__.'/'.$file->path))->toBe($file->contents, $file->path.' differs from what the emitters write.');
    }

    expect(array_map(static fn (GeneratedFile $file): string => $file->path, ProbeContract::files()))->toBe([
        'Fixtures/Probe/ProbeRecord.php',
        'Fixtures/Probe/ProbeStep.php',
        'Fixtures/Probe/ProbeSecret.php',
        'Fixtures/Probe/ProbeCodecV3.php',
    ]);
});

function probeRecord(ProbeSecret|Omitted|null $secret): ProbeRecord
{
    return new ProbeRecord(
        changeset: ChangesetId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a0c'),
        firstStep: new ProbeStep(amounts: ['1.5', '-2'], step: Step::One),
        grid: [[1, 2], []],
        parent: Omitted::Field,
        secret: $secret,
        steps: [new ProbeStep(amounts: null, step: Step::Two), new ProbeStep(amounts: Omitted::Field, step: Step::One)],
        tones: [Tone::Cold, Tone::Warm],
        when: new DateTimeImmutable('2026-01-01T12:00:00+02:00'),
    );
}

it('writes ids, enums, lists and objects under their keys and reads them back', function (): void {
    $codec = new ProbeCodecV3;
    $record = probeRecord(new ProbeSecret(code: 'A1', tone: Tone::Warm));
    $json = '{"changeset":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a0c","first_step":{"amounts":["1.50","-2.00"],"step":1},"grid":[[1,2],[]],"secret":{"code":"A1","tone":"warm"},"steps":[{"amounts":null,"step":2},{"step":1}],"tones":["cold","warm"],"when":"2026-01-01T10:00:00.000000Z"}';

    expect($codec->encode($record, ClassificationAccess::Confidential))->toBe($json)
        ->and($codec->encode($codec->decode($json, ClassificationAccess::Confidential), ClassificationAccess::Confidential))->toBe($json)
        ->and($codec->encode($record, ClassificationAccess::Internal))->toBe(str_replace(',"tone":"warm"', '', $json))
        ->and($codec->encode($record, ClassificationAccess::Public))->toBe(str_replace(',"secret":{"code":"A1","tone":"warm"}', '', $json))
        ->and($codec->encode(probeRecord(null), ClassificationAccess::Public))->toBe(str_replace(',"secret":{"code":"A1","tone":"warm"}', '', $json))
        ->and($codec->encode(probeRecord(null), ClassificationAccess::Internal))->toBe(str_replace('{"code":"A1","tone":"warm"}', 'null', $json))
        ->and($codec->encode(probeRecord(Omitted::Field), ClassificationAccess::Confidential))->toBe(str_replace(',"secret":{"code":"A1","tone":"warm"}', '', $json))
        ->and(ProbeCodecV3::VERSION)->toBe(3)
        ->and($codec->decode(str_replace('"grid"', '"parent":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a0b","grid"', $json), ClassificationAccess::Confidential)->parent)
        ->toEqual(ChangesetId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a0b'));
});

it('refuses what the probe contract does not allow, at the path of the value', function (string $json, ClassificationAccess $access, string $path, string $reason): void {
    try {
        new ProbeCodecV3()->decode($json, $access);
    } catch (DecodingFailed $failure) {
        expect([$failure->path?->toString(), $failure->reason])->toBe([$path, $reason]);

        return;
    }

    throw new LogicException('The document was not refused.');
})->with([
    'a negative number in a nested list' => ['{"changeset":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a0c","first_step":{"step":1},"grid":[[1],[0,-1]],"steps":[],"tones":[]}', ClassificationAccess::Public, 'grid[1][1]', 'is -1, less than the minimum 0'],
    'too many rows' => ['{"changeset":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a0c","first_step":{"step":1},"grid":[[],[],[]],"steps":[],"tones":[]}', ClassificationAccess::Public, 'grid', 'has 3 items, more than the 2 the field allows'],
    'an enum value it does not have' => ['{"changeset":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a0c","first_step":{"step":3},"steps":[],"tones":[]}', ClassificationAccess::Public, 'first_step.step', 'is not one of 1, 2'],
    'a decimal with too many digits' => ['{"changeset":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a0c","first_step":{"step":1},"steps":[{"step":1,"amounts":["1234"]}],"tones":[]}', ClassificationAccess::Public, 'steps[0].amounts[0]', 'is not a decimal number in a string with at most 3 digits before the point and 2 after it'],
    'a classified property inside a classified object' => ['{"changeset":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a0c","first_step":{"step":1},"secret":{"code":"A","tone":"warm"},"steps":[],"tones":[]}', ClassificationAccess::Internal, 'secret.tone', 'is classified confidential, above the classification access internal'],
    'a time after its maximum' => ['{"changeset":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a0c","first_step":{"step":1},"steps":[],"tones":[],"when":"2100-01-01T00:00:00Z"}', ClassificationAccess::Public, 'when', 'is 2100-01-01T00:00:00.000000Z, after the maximum 2099-12-31T23:59:59Z'],
    'an id that is none' => ['{"changeset":"x","first_step":{"step":1},"steps":[],"tones":[]}', ClassificationAccess::Public, 'changeset', 'is not a valid id: Expected a UUID in the form xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx with hex digits, got "x".'],
]);

/**
 * The GenerationFailed an emitter throws for a contract with the one property `a` of $value.
 *
 * @param  Closure(CodecContract, PhpLocation): mixed  $emit
 */
function refusedContract(Closure $emit, CodecValue $value, string $key = 'a'): GenerationFailed
{
    $contract = new CodecContract(new CodecObject('Refused', ['Refused.'], [new CodecProperty($key, 'a', $value, true, null, 'A.')]), 'RefusedCodecV1', 1, ['Refused.']);

    try {
        $emit($contract, new PhpLocation('Refused', 'App\Refused'));
    } catch (GenerationFailed $failure) {
        expect($failure->codes())->toBe([GenerateErrorCode::InvalidOutput]);

        return $failure;
    }

    throw new LogicException('The emitter accepted the contract.');
}

it('refuses a rule that a kind of value has no form for, in the DTO and in the codec', function (CodecValue $value, string $message): void {
    expect(refusedContract(static fn (CodecContract $contract, PhpLocation $location): mixed => PhpDtoEmitter::emit($contract, $location), $value)->getMessage())->toContain($message)
        ->and(refusedContract(static fn (CodecContract $contract, PhpLocation $location): mixed => PhpCodecEmitter::emit($contract, $location, $location), $value)->getMessage())->toContain($message);
})->with([
    'a length rule on an integer' => [CodecValue::of(CodecKind::Integer, [new ValidationRule(ValidationRuleName::MaxLength, ['3'])]), 'The codec of Refused::$a has no form for the rule max_length on a value of the kind integer.'],
    'an items rule on the item of a list' => [CodecValue::list(CodecValue::of(CodecKind::Text, [new ValidationRule(ValidationRuleName::MinItems, ['1'])])), 'The codec of Refused::$a has no form for the rule min_items on a value of the kind text.'],
    'presence as a rule' => [CodecValue::of(CodecKind::Text, [new ValidationRule(ValidationRuleName::Required)]), 'has no form for the rule required on a value of the kind text.'],
]);

it('refuses a key that is not a name', function (): void {
    expect(refusedContract(static fn (CodecContract $contract, PhpLocation $location): mixed => PhpDtoEmitter::emit($contract, $location), CodecValue::of(CodecKind::Boolean), 'first-step')->getMessage())
        ->toContain('The key "first-step" of Refused is not a name')
        ->and(refusedContract(static fn (CodecContract $contract, PhpLocation $location): mixed => PhpCodecEmitter::emit($contract, $location, $location), CodecValue::of(CodecKind::Boolean), '1st')->getMessage())
        ->toContain('The key "1st" of Refused is not a name');
});

it('refuses a value that lacks what its kind needs', function (CodecValue $value, string $message): void {
    expect(refusedContract(static fn (CodecContract $contract, PhpLocation $location): mixed => PhpCodecEmitter::emit($contract, $location, $location), $value)->getMessage())->toContain($message);
})->with([
    'a decimal without its precision and scale' => [CodecValue::of(CodecKind::Decimal), 'A decimal needs a `decimal` rule with its precision and scale.'],
    'a decimal with a scale alone' => [CodecValue::of(CodecKind::Decimal, [new ValidationRule(ValidationRuleName::Decimal, ['2'])]), 'A decimal needs a `decimal` rule with its precision and scale.'],
    'a decimal of a precision that is no integer' => [CodecValue::of(CodecKind::Decimal, [new ValidationRule(ValidationRuleName::Decimal, ['ten', '2'])]), 'The rule argument "ten" is not an integer.'],
    'a choice without values' => [CodecValue::of(CodecKind::Choice), 'A choice needs an `in` rule with at least one value.'],
    'a bound that is no integer' => [CodecValue::of(CodecKind::Integer, [new ValidationRule(ValidationRuleName::Max, ['1.5'])]), 'The rule argument "1.5" is not an integer.'],
]);
