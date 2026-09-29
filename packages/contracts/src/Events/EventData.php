<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The data of an event (PRD 7.2): named EventDatum values, sorted by name. It holds ids,
 * versions, values that are not text, before and after, and hashes of text, and never content.
 *
 * An event payload builds it from its properties:
 *
 *     return EventData::empty()
 *         ->with('entry', EventDatum::identifier($this->entry))
 *         ->with('version', EventDatum::integer($this->version));
 *
 * A name is snake_case, at most MAX_NAME_LENGTH characters, and appears once.
 */
#[Experimental]
final readonly class EventData
{
    public const int MAX_NAME_LENGTH = 63;

    private const string NAME_PATTERN = '/\A[a-z][a-z0-9_]*\z/';

    /**
     * @param  array<string, EventDatum>  $fields  sorted by name
     */
    private function __construct(private array $fields) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * The data with one more field.
     */
    public function with(string $name, EventDatum $datum): self
    {
        if (strlen($name) > self::MAX_NAME_LENGTH || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw InvalidEvent::fieldName();
        }

        if (array_key_exists($name, $this->fields)) {
            throw InvalidEvent::duplicateField($name);
        }

        $fields = $this->fields;
        $fields[$name] = $datum;
        ksort($fields, SORT_STRING);

        return new self($fields);
    }

    /**
     * The fields, sorted by name.
     *
     * @return array<string, EventDatum>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->fields);
    }

    public function get(string $name): EventDatum
    {
        return $this->fields[$name] ?? throw InvalidEvent::missingField($name);
    }
}
