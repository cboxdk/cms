<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Codec\Fixtures\Probe;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Step;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Tone;
use DateTimeImmutable;
use Override;
use stdClass;

/**
 * The codec of the probe.
 *
 * @implements JsonCodec<ProbeRecord>
 */
final readonly class ProbeCodecV3 implements JsonCodec
{
    /** The contract version this codec reads and writes. */
    public const int VERSION = 3;

    /**
     * The canonical JSON of $dto as a caller with $access may see it: sorted keys, no
     * whitespace, and without every field classified above the access (PRD 12.2) or held as
     * Omitted.
     *
     * @param  ProbeRecord  $dto
     *
     * @throws EncodingFailed when the DTO holds a value that has no form in the contract
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        return JsonText::encode($this->encodeProbeRecord($dto->visibleTo($access)));
    }

    /**
     * The DTO a JSON document holds, read as a caller with $access: a field classified above
     * the access must be absent, and is Omitted, as is an optional field that is absent.
     *
     * @throws DecodingFailed with json_malformed or json_invalid
     */
    #[Override]
    public function decode(string $json, ClassificationAccess $access): ProbeRecord
    {
        return $this->decodeProbeRecord(JsonText::decode($json), $access);
    }

    private function encodeProbeRecord(ProbeRecord $object): stdClass
    {
        $json = new stdClass;
        $json->changeset = $object->changeset->toString();
        $json->first_step = $this->encodeProbeStep($object->firstStep);

        if (! $object->grid instanceof Omitted) {
            $json->grid = $object->grid;
        }

        if (! $object->parent instanceof Omitted) {
            $json->parent = $object->parent instanceof ChangesetId ? $object->parent->toString() : null;
        }

        if (! $object->secret instanceof Omitted) {
            $json->secret = $object->secret instanceof ProbeSecret ? $this->encodeProbeSecret($object->secret) : null;
        }

        $json->steps = array_map($this->encodeProbeStep(...), $object->steps);
        $json->tones = array_map(static fn (Tone $item): mixed => $item->value, $object->tones);

        if (! $object->when instanceof Omitted) {
            $json->when = $object->when instanceof DateTimeImmutable ? JsonValues::encodeDatetime($object->when) : null;
        }

        return $json;
    }

    private function decodeProbeRecord(stdClass $value, ClassificationAccess $access): ProbeRecord
    {
        $object = JsonValues::object($value, null, ['changeset', 'first_step', 'grid', 'parent', 'secret', 'steps', 'tones', 'when']);

        return new ProbeRecord(
            changeset: JsonValues::required($object, 'changeset', null, static fn (mixed $value, FieldPath $at): ChangesetId => JsonValues::id($value, $at, ChangesetId::fromString(...))),
            firstStep: JsonValues::required($object, 'first_step', null, $this->decodeProbeStep(...)),
            grid: JsonValues::nullable($object, 'grid', null, static fn (mixed $value, FieldPath $at): array => JsonValues::list($value, $at, static fn (mixed $item, FieldPath $itemAt): array => JsonValues::list($item, $itemAt, static fn (mixed $item2, FieldPath $item2At): int => JsonValues::integer($item2, $item2At, min: 0)), maxItems: 2)),
            parent: JsonValues::nullable($object, 'parent', null, static fn (mixed $value, FieldPath $at): ChangesetId => JsonValues::id($value, $at, ChangesetId::fromString(...))),
            secret: JsonValues::nullableClassified($object, 'secret', null, ClassificationAccess::Internal, $access, fn (mixed $value, FieldPath $at): ProbeSecret => $this->decodeProbeSecret($value, $at, $access)),
            steps: JsonValues::required($object, 'steps', null, fn (mixed $value, FieldPath $at): array => JsonValues::list($value, $at, $this->decodeProbeStep(...))),
            tones: JsonValues::required($object, 'tones', null, static fn (mixed $value, FieldPath $at): array => JsonValues::list($value, $at, static fn (mixed $item, FieldPath $itemAt): Tone => JsonValues::enum($item, $itemAt, Tone::class))),
            when: JsonValues::nullable($object, 'when', null, static fn (mixed $value, FieldPath $at): DateTimeImmutable => JsonValues::datetime($value, $at, max: '2099-12-31T23:59:59Z')),
        );
    }

    private function encodeProbeStep(ProbeStep $object): stdClass
    {
        $json = new stdClass;

        if (! $object->amounts instanceof Omitted) {
            $json->amounts = $object->amounts === null ? null : array_map(static fn (string $item): mixed => JsonValues::encodeDecimal($item, 5, 2), $object->amounts);
        }

        $json->step = $object->step->value;

        return $json;
    }

    private function decodeProbeStep(mixed $value, FieldPath $path): ProbeStep
    {
        $object = JsonValues::object($value, $path, ['amounts', 'step']);

        return new ProbeStep(
            amounts: JsonValues::nullable($object, 'amounts', $path, static fn (mixed $value, FieldPath $at): array => JsonValues::list($value, $at, static fn (mixed $item, FieldPath $itemAt): string => JsonValues::decimal($item, $itemAt, 5, 2))),
            step: JsonValues::required($object, 'step', $path, static fn (mixed $value, FieldPath $at): Step => JsonValues::enum($value, $at, Step::class)),
        );
    }

    private function encodeProbeSecret(ProbeSecret $object): stdClass
    {
        $json = new stdClass;
        $json->code = $object->code;

        if (! $object->tone instanceof Omitted) {
            $json->tone = $object->tone instanceof Tone ? $object->tone->value : null;
        }

        return $json;
    }

    private function decodeProbeSecret(mixed $value, FieldPath $path, ClassificationAccess $access): ProbeSecret
    {
        $object = JsonValues::object($value, $path, ['code', 'tone']);

        return new ProbeSecret(
            code: JsonValues::required($object, 'code', $path, static fn (mixed $value, FieldPath $at): string => JsonValues::text($value, $at, maxLength: 4)),
            tone: JsonValues::nullableClassified($object, 'tone', $path, ClassificationAccess::Confidential, $access, static fn (mixed $value, FieldPath $at): Tone => JsonValues::enum($value, $at, Tone::class)),
        );
    }
}
