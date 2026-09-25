<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Codecs\Boundary;

use BackedEnum;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use stdClass;

/**
 * The JSON form of a receipt, version 1 (GUARDRAILS 2.2: one codec per contract version fixes the
 * JSON of ids, enums, time, null and missing fields).
 *
 * This is a temporary M0 codec, written by hand for the receipt and idempotency stores. The codecs
 * that M1 generates replace it.
 *
 * The form:
 *
 *     {"changeset_id":"0193...","codec_version":1,"outcome":"committed",
 *      "projections":[{"acknowledged_at":"2026-01-01T00:00:00.123456Z","projection":"fragments",
 *      "state":"acknowledged"}],"retention_class":"standard","wait_level":"origin"}
 *
 * - Keys are sorted at every level, and there is no whitespace, so the same receipt always gives
 *   the same bytes.
 * - Every field is always present. An absent value is null: changeset_id for a receipt that
 *   committed nothing, acknowledged_at for a pending projection. Decoding rejects a missing field
 *   and an unknown field.
 * - Ids are the canonical UUIDv7 string. Enums are their PRD names in snake_case.
 * - A time is UTC with exactly six fraction digits and a Z: 2026-01-01T00:00:00.123456Z.
 * - codec_version is the integer 1. Decoding checks it before anything else, so a newer document
 *   fails as an unknown version and not as unknown fields.
 *
 * Decoding fails with MalformedReceiptJson and never returns a partial receipt.
 */
#[Internal]
final readonly class ReceiptCodecV1
{
    public const int VERSION = 1;

    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    private const string TIME_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/';

    /** A receipt is four levels deep: document, projections, status, value. */
    private const int MAX_DEPTH = 8;

    private const array RECEIPT_FIELDS = ['changeset_id', 'codec_version', 'outcome', 'projections', 'retention_class', 'wait_level'];

    private const array PROJECTION_FIELDS = ['acknowledged_at', 'projection', 'state'];

    public function encode(Receipt $receipt): string
    {
        // The keys are written in sorted order at every level; a test holds them there.
        $document = [
            'changeset_id' => $receipt->changesetId?->toString(),
            'codec_version' => self::VERSION,
            'outcome' => $receipt->outcome->value,
            'projections' => array_map(
                static fn (ProjectionStatus $status): array => [
                    'acknowledged_at' => $status->acknowledgedAt?->setTimezone(new DateTimeZone('UTC'))->format(self::TIME_FORMAT),
                    'projection' => $status->projection->value,
                    'state' => $status->state->value,
                ],
                $receipt->projections,
            ),
            'retention_class' => $receipt->retentionClass->value,
            'wait_level' => $receipt->waitLevel->value,
        ];

        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function decode(string $json): Receipt
    {
        try {
            $document = json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw MalformedReceiptJson::invalidJson($exception);
        }

        if (! $document instanceof stdClass) {
            throw MalformedReceiptJson::wrongType('$', 'an object', get_debug_type($document));
        }

        $fields = get_object_vars($document);

        if (! array_key_exists('codec_version', $fields)) {
            throw MalformedReceiptJson::missingField('$', 'codec_version');
        }

        $version = $fields['codec_version'];

        if (! is_int($version)) {
            throw MalformedReceiptJson::wrongType('$.codec_version', 'an integer', get_debug_type($version));
        }

        if ($version !== self::VERSION) {
            throw MalformedReceiptJson::unknownVersion($version);
        }

        $this->assertFields('$', $fields, self::RECEIPT_FIELDS);

        $outcome = $this->enum('$.outcome', $fields['outcome'], Outcome::class);
        $changesetId = $this->changesetId('$.changeset_id', $fields['changeset_id']);
        $waitLevel = $this->enum('$.wait_level', $fields['wait_level'], WaitLevel::class);
        $retentionClass = $this->enum('$.retention_class', $fields['retention_class'], RetentionClass::class);
        $projections = $this->projections('$.projections', $fields['projections']);

        try {
            return new Receipt($outcome, $changesetId, $waitLevel, $retentionClass, $projections);
        } catch (InvalidReceipt $exception) {
            throw MalformedReceiptJson::invalidReceipt('$', $exception);
        }
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @param  list<string>  $expected
     */
    private function assertFields(string $path, array $fields, array $expected): void
    {
        foreach ($expected as $field) {
            if (! array_key_exists($field, $fields)) {
                throw MalformedReceiptJson::missingField($path, $field);
            }
        }

        foreach (array_keys($fields) as $field) {
            if (! in_array((string) $field, $expected, true)) {
                throw MalformedReceiptJson::unknownField($path, (string) $field);
            }
        }
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private function enum(string $path, mixed $value, string $enum): BackedEnum
    {
        if (! is_string($value)) {
            throw MalformedReceiptJson::wrongType($path, 'a string', get_debug_type($value));
        }

        $case = $enum::tryFrom($value);

        if ($case === null) {
            throw MalformedReceiptJson::unknownValue(
                $path,
                $value,
                array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases()),
            );
        }

        return $case;
    }

    private function changesetId(string $path, mixed $value): ?ChangesetId
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw MalformedReceiptJson::wrongType($path, 'a string or null', get_debug_type($value));
        }

        try {
            return ChangesetId::fromString($value);
        } catch (InvalidUuid7 $exception) {
            throw MalformedReceiptJson::malformedId($path, $exception);
        }
    }

    /**
     * @return list<ProjectionStatus>
     */
    private function projections(string $path, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw MalformedReceiptJson::wrongType($path, 'an array', get_debug_type($value));
        }

        $statuses = [];

        foreach ($value as $index => $item) {
            $statuses[] = $this->projection(sprintf('%s[%d]', $path, $index), $item);
        }

        return $statuses;
    }

    private function projection(string $path, mixed $value): ProjectionStatus
    {
        if (! $value instanceof stdClass) {
            throw MalformedReceiptJson::wrongType($path, 'an object', get_debug_type($value));
        }

        $fields = get_object_vars($value);
        $this->assertFields($path, $fields, self::PROJECTION_FIELDS);

        $name = $fields['projection'];

        if (! is_string($name)) {
            throw MalformedReceiptJson::wrongType($path.'.projection', 'a string', get_debug_type($name));
        }

        $state = $this->enum($path.'.state', $fields['state'], ProjectionState::class);
        $acknowledgedAt = $this->time($path.'.acknowledged_at', $fields['acknowledged_at']);

        try {
            return new ProjectionStatus(new ProjectionName($name), $state, $acknowledgedAt);
        } catch (InvalidReceipt $exception) {
            throw MalformedReceiptJson::invalidReceipt($path, $exception);
        }
    }

    private function time(string $path, mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw MalformedReceiptJson::wrongType($path, 'a string or null', get_debug_type($value));
        }

        if (preg_match(self::TIME_PATTERN, $value) !== 1) {
            throw MalformedReceiptJson::malformedTime($path, $value);
        }

        $time = DateTimeImmutable::createFromFormat('!'.self::TIME_FORMAT, $value, new DateTimeZone('UTC'));

        // A date that does not exist, such as 2026-02-30, parses by rolling over; the round trip catches it.
        if ($time === false || $time->format(self::TIME_FORMAT) !== $value) {
            throw MalformedReceiptJson::malformedTime($path, $value);
        }

        return $time;
    }
}
