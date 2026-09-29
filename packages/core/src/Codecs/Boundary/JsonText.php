<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Codecs\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use JsonException;
use stdClass;

/**
 * The JSON text of the generated codecs (GUARDRAILS 2.2).
 *
 * decode() reads a JSON object as PHP's json_decode() does, with objects as stdClass and arrays as
 * lists, and refuses what json_decode() lets through: an object with the same key twice, where
 * json_decode() keeps the last value, a document that is not an object, and nesting deeper than
 * json_decode() reads at the depth MAX_DEPTH, which is 63 arrays or objects inside one another. Keys are compared as their decoded text, so "a" and "a" are the same key.
 *
 * encode() writes the canonical form: no whitespace, slashes and non-ASCII characters unescaped,
 * and keys in the order the codec added them, which the generated code makes sorted, so one value
 * always gives the same bytes.
 */
#[Experimental]
final readonly class JsonText
{
    /** The depth json_decode() reads to; the values inside the innermost array or object count as a level. */
    public const int MAX_DEPTH = 64;

    private const int ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @throws DecodingFailed with json_malformed
     */
    public static function decode(string $json): stdClass
    {
        try {
            $value = json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw DecodingFailed::malformed(sprintf('the document is not well-formed JSON, or nests more than %d arrays or objects inside one another: %s', self::MAX_DEPTH - 1, $exception->getMessage()), $exception);
        }

        if (! $value instanceof stdClass) {
            throw DecodingFailed::malformed('the document is not a JSON object');
        }

        $duplicate = self::duplicateKey($json);

        if ($duplicate !== null) {
            throw DecodingFailed::malformed(sprintf('an object has the key "%s" twice', $duplicate));
        }

        return $value;
    }

    /**
     * @throws EncodingFailed when the value holds text that is not UTF-8
     */
    public static function encode(stdClass $value): string
    {
        try {
            return json_encode($value, self::ENCODE_FLAGS, self::MAX_DEPTH);
        } catch (JsonException $exception) {
            throw EncodingFailed::because('it has no JSON form: '.$exception->getMessage(), $exception);
        }
    }

    /**
     * The first key that an object of the well-formed JSON text $json has twice, or null.
     */
    private static function duplicateKey(string $json): ?string
    {
        /** @var list<array{object: bool, keys: array<string, true>, expectsKey: bool}> $open */
        $open = [];
        $length = strlen($json);
        $offset = 0;

        while (($offset += strcspn($json, '"{}[],', $offset)) < $length) {
            $character = $json[$offset];
            $top = array_key_last($open);

            if ($character === '"') {
                $end = self::stringEnd($json, $offset);

                if ($top !== null && $open[$top]['expectsKey']) {
                    $key = json_decode(substr($json, $offset, $end - $offset + 1));

                    if (! is_string($key) || isset($open[$top]['keys'][$key])) {
                        return is_string($key) ? $key : '';
                    }

                    $open[$top]['keys'][$key] = true;
                    $open[$top]['expectsKey'] = false;
                }

                $offset = $end + 1;

                continue;
            }

            if ($character === '{' || $character === '[') {
                $open[] = ['object' => $character === '{', 'keys' => [], 'expectsKey' => $character === '{'];
            } elseif ($character === '}' || $character === ']') {
                array_pop($open);
            } elseif ($top !== null && $open[$top]['object']) {
                $open[$top]['expectsKey'] = true;
            }

            $offset++;
        }

        return null;
    }

    /**
     * The offset of the quote that ends the string starting at $start.
     */
    private static function stringEnd(string $json, int $start): int
    {
        $offset = $start + 1;

        while (($offset += strcspn($json, '"\\', $offset)) < strlen($json) && $json[$offset] === '\\') {
            $offset += 2;
        }

        return $offset;
    }
}
