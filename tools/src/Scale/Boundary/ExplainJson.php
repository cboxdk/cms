<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Scale\Boundary;

use JsonException;
use UnexpectedValueException;

/**
 * Reads the execution time from what EXPLAIN (ANALYZE, FORMAT JSON) returns: a JSON list with one
 * object whose "Execution Time" is in milliseconds.
 */
final readonly class ExplainJson
{
    /**
     * @throws UnexpectedValueException when the text is not such a document
     */
    public static function executionMilliseconds(string $json): float
    {
        try {
            $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new UnexpectedValueException('EXPLAIN returned no JSON: '.$invalid->getMessage(), 0, $invalid);
        }

        $time = is_array($document) && is_array($document[0] ?? null) ? ($document[0]['Execution Time'] ?? null) : null;

        return is_int($time) || is_float($time) ? (float) $time : throw new UnexpectedValueException('EXPLAIN (ANALYZE, FORMAT JSON) returned no "Execution Time".');
    }
}
