<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Codec;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ChangesetId;
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
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Step;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Tone;

/**
 * A contract that is not a record, as another generator, such as the one for receipts, builds
 * them: an id and enums, a list of decimals, a list of lists, and a classified object that holds a
 * classified property. What the emitters write for it is committed in Fixtures/Probe, so PHPStan,
 * Pint and Rector check it and a test can run the codec.
 */
final class ProbeContract
{
    public const string NAMESPACE = 'Cbox\Cms\Generators\Tests\Codec\Fixtures\Probe';

    public static function contract(): CodecContract
    {
        $step = new CodecObject('ProbeStep', ['One step of the probe.'], [
            new CodecProperty('step', 'step', CodecValue::enum(Step::class), true, null, 'The step.'),
            new CodecProperty('amounts', 'amounts', CodecValue::list(CodecValue::of(CodecKind::Decimal, [new ValidationRule(ValidationRuleName::Decimal, ['5', '2'])])), false, null, 'The amounts.'),
        ]);
        $secret = new CodecObject('ProbeSecret', ['The secret part of the probe.'], [
            new CodecProperty('code', 'code', CodecValue::of(CodecKind::Text, [new ValidationRule(ValidationRuleName::MaxLength, ['4'])]), true, null, 'The code.'),
            new CodecProperty('tone', 'tone', CodecValue::enum(Tone::class), false, ClassificationAccess::Confidential, 'The tone.'),
        ]);
        $root = new CodecObject('ProbeRecord', ['The probe.'], [
            new CodecProperty('changeset', 'changeset', CodecValue::id(ChangesetId::class), true, null, 'The changeset.'),
            new CodecProperty('first_step', 'firstStep', CodecValue::object($step), true, null, 'The first step.'),
            new CodecProperty('parent', 'parent', CodecValue::id(ChangesetId::class), false, null, 'The changeset before, if any.'),
            new CodecProperty('grid', 'grid', CodecValue::list(CodecValue::list(CodecValue::of(CodecKind::Integer, [new ValidationRule(ValidationRuleName::Min, ['0'])])), [new ValidationRule(ValidationRuleName::MaxItems, ['2'])]), false, null, 'Rows of numbers.'),
            new CodecProperty('secret', 'secret', CodecValue::object($secret), false, ClassificationAccess::Internal, 'The secret part.'),
            new CodecProperty('steps', 'steps', CodecValue::list(CodecValue::object($step)), true, null, 'Every step.'),
            new CodecProperty('tones', 'tones', CodecValue::list(CodecValue::enum(Tone::class)), true, null, 'The tones.'),
            new CodecProperty('when', 'when', CodecValue::of(CodecKind::Datetime, [new ValidationRule(ValidationRuleName::Max, ['2099-12-31T23:59:59Z'])]), false, null, 'When it happened.'),
        ]);

        return new CodecContract($root, 'ProbeCodecV3', 3, ['The codec of the probe.']);
    }

    /**
     * The files the emitters write for the contract, below this directory: the committed golden
     * files in Fixtures/Probe.
     *
     * @return list<GeneratedFile>
     */
    public static function files(): array
    {
        $location = new PhpLocation('Fixtures/Probe', self::NAMESPACE);

        return [
            ...PhpDtoEmitter::emit(self::contract(), $location),
            PhpCodecEmitter::emit(self::contract(), $location, $location),
        ];
    }
}
