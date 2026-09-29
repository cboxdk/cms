<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Validation\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\FieldPath;
use stdClass;

/**
 * How the input validator reads the shape of decoded JSON and names where a value is: an object is
 * an array with string keys or a stdClass, a list is an array whose keys are 0, 1, 2 and so on,
 * and the empty array is both, because JSON decoded to arrays cannot tell `{}` from `[]`.
 */
#[Internal]
final readonly class InputPaths
{
    /** A key that can be a segment of a FieldPath. */
    private const string NAME = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * The object's members by key, or null when $value is not an object.
     *
     * @return array<array-key, mixed>|null
     */
    public static function object(mixed $value): ?array
    {
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }

        return is_array($value) && ($value === [] || ! array_is_list($value)) ? $value : null;
    }

    /**
     * The list's items, or null when $value is not a list.
     *
     * @return list<mixed>|null
     */
    public static function list(mixed $value): ?array
    {
        return is_array($value) && array_is_list($value) ? $value : null;
    }

    /**
     * The path of a member or item below $at: $at with the segment, or a path of the segment alone
     * at the top of the input.
     */
    public static function below(?FieldPath $at, string|int $segment): FieldPath
    {
        if ($at instanceof FieldPath) {
            return $at->then($segment);
        }

        return new FieldPath((string) $segment);
    }

    /**
     * Whether an unknown key can be named by a path. One that cannot, such as `a-b` or `0`, is
     * reported at its object's path with the key in the message.
     */
    public static function nameable(int|string $key): bool
    {
        return is_string($key) && preg_match(self::NAME, $key) === 1;
    }

    /**
     * The key as a message shows it: quoted, cut after 64 bytes and with control characters and
     * bytes outside ASCII escaped.
     */
    public static function shown(int|string $key): string
    {
        $key = (string) $key;
        $cut = strlen($key) > 64 ? substr($key, 0, 64).'...' : $key;

        return '"'.addcslashes($cut, "\0..\37\177..\377\"\\").'"';
    }
}
