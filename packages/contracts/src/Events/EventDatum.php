<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use BackedEnum;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\Identifier;
use DateTimeImmutable;
use DateTimeZone;

/**
 * One value in an event's data (PRD 7.2): an id, the hash of a text, an integer, a boolean, an
 * instant, the value of a backed enum case, null, or a list of these.
 *
 * It is made only from typed values, so there is no way to put a string in: an id is checked for
 * the form of EventIdentifier, and the string value of an enum case must be a word without spaces
 * (ENUM_PATTERN), so text never passes as either (PRD 6.5 invariant 10).
 */
#[Experimental]
final readonly class EventDatum
{
    /**
     * The form of an enum case's string value in an event: 1 to 63 letters, digits, and "_", ".",
     * ":" or "-", starting with a letter or digit.
     */
    public const string ENUM_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,62}\z/';

    /**
     * @param  list<EventDatum>  $items
     */
    private function __construct(
        public DatumKind $kind,
        private EventIdentifier|TextHash|DateTimeImmutable|int|bool|string|null $value,
        private array $items = [],
    ) {}

    public static function identifier(Identifier $id): self
    {
        return new self(DatumKind::Identifier, EventIdentifier::of($id));
    }

    public static function hash(TextHash $hash): self
    {
        return new self(DatumKind::Hash, $hash);
    }

    public static function integer(int $value): self
    {
        return new self(DatumKind::Integer, $value);
    }

    public static function boolean(bool $value): self
    {
        return new self(DatumKind::Boolean, $value);
    }

    /**
     * An instant, kept in UTC with its microseconds.
     */
    public static function time(DateTimeImmutable $value): self
    {
        return new self(DatumKind::Time, $value->setTimezone(new DateTimeZone('UTC')));
    }

    public static function enum(BackedEnum $case): self
    {
        return self::enumValue($case->value);
    }

    /**
     * The value of an enum case, as the log reads it back without the enum's class. A subscriber
     * turns it into the case with its enum's from().
     */
    public static function enumValue(int|string $value): self
    {
        if (is_string($value) && preg_match(self::ENUM_PATTERN, $value) !== 1) {
            throw InvalidEvent::enumValue(strlen($value));
        }

        return new self(DatumKind::Enum, $value);
    }

    public static function null(): self
    {
        return new self(DatumKind::Null, null);
    }

    public static function list(self ...$items): self
    {
        return new self(DatumKind::List, null, array_values($items));
    }

    public function asIdentifier(): EventIdentifier
    {
        return $this->value instanceof EventIdentifier ? $this->value : throw InvalidEvent::datumKind(DatumKind::Identifier, $this->kind);
    }

    public function asHash(): TextHash
    {
        return $this->value instanceof TextHash ? $this->value : throw InvalidEvent::datumKind(DatumKind::Hash, $this->kind);
    }

    public function asInteger(): int
    {
        return $this->kind === DatumKind::Integer && is_int($this->value) ? $this->value : throw InvalidEvent::datumKind(DatumKind::Integer, $this->kind);
    }

    public function asBoolean(): bool
    {
        return is_bool($this->value) ? $this->value : throw InvalidEvent::datumKind(DatumKind::Boolean, $this->kind);
    }

    public function asTime(): DateTimeImmutable
    {
        return $this->value instanceof DateTimeImmutable ? $this->value : throw InvalidEvent::datumKind(DatumKind::Time, $this->kind);
    }

    public function asEnumValue(): int|string
    {
        return $this->kind === DatumKind::Enum && (is_int($this->value) || is_string($this->value)) ? $this->value : throw InvalidEvent::datumKind(DatumKind::Enum, $this->kind);
    }

    /**
     * @return list<EventDatum>
     */
    public function items(): array
    {
        return $this->kind === DatumKind::List ? $this->items : throw InvalidEvent::datumKind(DatumKind::List, $this->kind);
    }

    public function isNull(): bool
    {
        return $this->kind === DatumKind::Null;
    }
}
