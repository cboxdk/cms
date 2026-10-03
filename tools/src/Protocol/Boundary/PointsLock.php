<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Protocol\Boundary;

use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Tooling\Protocol\Domain\Dto\LockedPoint;
use Cbox\Cms\Tooling\Protocol\Domain\Dto\PointSchema;
use JsonException;
use stdClass;

/**
 * The compatibility lock of the panel's stable points (PRD 13.4), points.lock.json: for each
 * #[Stable] point, its schema's path, the SHA-256 of its contract and the contract itself, the
 * schema as canonical JSON (keys sorted, no whitespace) without the keywords that only document it,
 * DOCUMENTING. An addon written against a stable point keeps working for the point's version, so
 * the only change its props may take is an added optional member; anything else is the point's next
 * version.
 *
 * composer generate:protocol writes the lock from the stable points and compares each with the
 * committed lock first: a removed member, a member made required or no longer required, an added
 * required member, or any change to an existing member's schema, narrowing or widening, is refused
 * with generate_schema_invalid, naming the point and the place, and nothing is written. A point
 * that turns stable is recorded; a point that leaves the lock, by its schema or by losing
 * #[Stable], is refused too. Gate 6 holds the committed lock to what the points give.
 */
final readonly class PointsLock
{
    /** The version of the lock's format. */
    public const int FORMAT = 1;

    /** The keywords that only document a schema, which the contract leaves out. */
    public const array DOCUMENTING = ['$comment', 'description', 'examples', 'title'];

    private const int DEPTH = 64;

    private const string DEFS = '#/$defs/';

    /**
     * The contract of a schema: canonical JSON without DOCUMENTING.
     *
     * @throws GenerationFailed with generate_schema_invalid
     */
    public static function contract(string $schema, string $path): string
    {
        return self::encode(self::canonical(self::decode($schema, $path)));
    }

    /**
     * The lock of the stable points, after it compared each with the lock committed before, $previous
     * (null when there is none, and then every stable point is recorded).
     *
     * @param  list<PointSchema>  $points
     *
     * @throws GenerationFailed with generate_schema_invalid, naming every incompatible change
     */
    public static function file(array $points, ?string $previous, string $path): GeneratedFile
    {
        $locked = $previous === null ? [] : self::read($previous, $path);
        $current = [];
        $problems = [];

        foreach ($points as $point) {
            if (! $point->stable) {
                continue;
            }

            $id = $point->point->toString();
            $current[$id] = new LockedPoint($id, $point->binding->path(), hash('sha256', $point->lockedContract), $point->lockedContract);
        }

        ksort($current, SORT_STRING);

        foreach ($locked as $id => $before) {
            $after = $current[$id] ?? null;

            if (! $after instanceof LockedPoint) {
                $problems[] = new GenerationProblem(GenerateErrorCode::SchemaInvalid, sprintf(
                    'The stable panel point %s in %s has no stable schema any more. A stable point keeps its version for as long as the panel API\'s major version; give its successor the next version and keep this one.',
                    $id,
                    $path,
                ));

                continue;
            }

            foreach (self::changes($before->contract, $after->contract, $after->schema) as $change) {
                $problems[] = new GenerationProblem(GenerateErrorCode::SchemaInvalid, sprintf(
                    'The stable panel point %s %s. Its props may only gain an optional member; give the change to its next version, %s, and keep this one with a downcast from it.',
                    $id,
                    $change,
                    preg_replace_callback('/@([0-9]+)\z/', static fn (array $match): string => '@'.((int) $match[1] + 1), $id),
                ));
            }
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        $document = new stdClass;
        $document->format = self::FORMAT;
        $document->points = new stdClass;

        foreach ($current as $id => $point) {
            $entry = new stdClass;
            $entry->contract = json_decode($point->contract, false, self::DEPTH, JSON_THROW_ON_ERROR);
            $entry->schema = $point->schema;
            $entry->sha256 = $point->sha256;
            $document->points->{$id} = $entry;
        }

        return new GeneratedFile($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * The incompatible changes from the contract $before to $after, each as the place and what
     * changed there, such as `removes the member at #/properties/title`; none for an added optional
     * member, or for a contract that did not change.
     *
     * @return list<string>
     *
     * @throws GenerationFailed with generate_schema_invalid for a contract that is not JSON
     */
    public static function changes(string $before, string $after, string $path): array
    {
        $old = self::decode($before, $path);
        $new = self::decode($after, $path);

        if (! $old instanceof stdClass || ! $new instanceof stdClass) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf('A contract of %s in the lock is not a JSON object.', $path));
        }

        $changes = [];
        new self()->compare($old, $new, $old, $new, '#', $changes, []);

        return $changes;
    }

    /**
     * The points of a lock's JSON, by id.
     *
     * @return array<string, LockedPoint>
     *
     * @throws GenerationFailed with generate_schema_invalid
     */
    private static function read(string $json, string $path): array
    {
        $document = self::decode($json, $path);
        $points = $document instanceof stdClass ? ($document->points ?? null) : null;

        if (! $document instanceof stdClass || ($document->format ?? null) !== self::FORMAT || ! $points instanceof stdClass) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf('The lock %s is not a lock of format %d: an object with "format" and "points". Restore it from git.', $path, self::FORMAT));
        }

        $locked = [];

        foreach (get_object_vars($points) as $id => $entry) {
            $schema = $entry instanceof stdClass ? ($entry->schema ?? null) : null;
            $sha256 = $entry instanceof stdClass ? ($entry->sha256 ?? null) : null;
            $contract = $entry instanceof stdClass ? ($entry->contract ?? null) : null;

            if (! is_string($schema) || ! is_string($sha256) || ! $contract instanceof stdClass) {
                throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf('The point %s in the lock %s has no schema, sha256 and contract. Restore the lock from git.', $id, $path));
            }

            $text = self::encode(self::canonical($contract));

            if (hash('sha256', $text) !== $sha256) {
                throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf('The contract of the point %s in the lock %s does not have its sha256. The lock is edited by composer generate:protocol only; restore it from git.', $id, $path));
            }

            $locked[(string) $id] = new LockedPoint((string) $id, $schema, $sha256, $text);
        }

        return $locked;
    }

    /**
     * Compares the schema nodes $old and $new at $at, adding each incompatible change; a `$ref` on
     * both sides is compared through the definitions it names, so a renamed definition is no change.
     *
     * @param  list<string>  $changes
     * @param  array<string, true>  $seen  the pairs of definitions under comparison
     */
    private function compare(mixed $old, mixed $new, stdClass $oldRoot, stdClass $newRoot, string $at, array &$changes, array $seen): void
    {
        if ($old instanceof stdClass && $new instanceof stdClass) {
            $oldReference = $old->{'$ref'} ?? null;
            $newReference = $new->{'$ref'} ?? null;

            if (is_string($oldReference) && is_string($newReference) && count(get_object_vars($old)) === 1 && count(get_object_vars($new)) === 1) {
                $pair = $oldReference.' '.$newReference;

                if (! isset($seen[$pair])) {
                    $this->compare($this->definition($oldRoot, $oldReference), $this->definition($newRoot, $newReference), $oldRoot, $newRoot, $at, $changes, [...$seen, $pair => true]);
                }

                return;
            }

            $this->object($old, $new, $oldRoot, $newRoot, $at, $changes, $seen);

            return;
        }

        if (is_array($old) && is_array($new) && array_is_list($old) && array_is_list($new)) {
            if (count($old) !== count($new)) {
                $changes[] = sprintf('changes %s from %s to %s', $at, self::encode($old), self::encode($new));

                return;
            }

            foreach ($old as $index => $item) {
                $this->compare($item, $new[$index], $oldRoot, $newRoot, $at.'/'.$index, $changes, $seen);
            }

            return;
        }

        if ($old !== $new) {
            $changes[] = sprintf('changes %s from %s to %s', $at, self::encode($old), self::encode($new));
        }
    }

    /**
     * Compares two schema objects: the members of an object schema by the rules of the lock, and
     * every other keyword by its value.
     *
     * @param  list<string>  $changes
     * @param  array<string, true>  $seen
     */
    private function object(stdClass $old, stdClass $new, stdClass $oldRoot, stdClass $newRoot, string $at, array &$changes, array $seen): void
    {
        $oldProperties = $old->properties ?? null;
        $newProperties = $new->properties ?? null;
        $members = $oldProperties instanceof stdClass && $newProperties instanceof stdClass;

        if ($members) {
            $oldRequired = $this->strings($old->required ?? []);
            $newRequired = $this->strings($new->required ?? []);

            foreach (get_object_vars($oldProperties) as $key => $schema) {
                $place = $at.'/properties/'.$key;

                if (! property_exists($newProperties, (string) $key)) {
                    $changes[] = 'removes the member at '.$place;

                    continue;
                }

                if (in_array($key, $oldRequired, true) !== in_array($key, $newRequired, true)) {
                    $changes[] = (in_array($key, $newRequired, true) ? 'makes required the member at ' : 'makes optional the member at ').$place;
                }

                $this->compare($schema, $newProperties->{$key}, $oldRoot, $newRoot, $place, $changes, $seen);
            }

            foreach (get_object_vars($newProperties) as $key => $schema) {
                if (! property_exists($oldProperties, (string) $key) && in_array($key, $newRequired, true)) {
                    $changes[] = sprintf('adds the required member at %s/properties/%s', $at, $key);
                }
            }
        }

        $skipped = $members ? ['properties', 'required', '$defs'] : ['$defs'];
        $keys = array_values(array_diff(array_unique([...array_keys(get_object_vars($old)), ...array_keys(get_object_vars($new))]), $skipped));
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            if (! property_exists($old, $key) || ! property_exists($new, $key)) {
                $changes[] = sprintf('%s "%s" at %s', property_exists($new, $key) ? 'adds' : 'removes', $key, $at);

                continue;
            }

            $this->compare($old->{$key}, $new->{$key}, $oldRoot, $newRoot, $at.'/'.$key, $changes, $seen);
        }
    }

    private function definition(stdClass $root, string $reference): mixed
    {
        $definitions = $root->{'$defs'} ?? null;

        return str_starts_with($reference, self::DEFS) && $definitions instanceof stdClass
            ? ($definitions->{substr($reference, strlen(self::DEFS))} ?? null)
            : null;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * The schema node with the keys of every object, and every list of required keys, sorted and
     * DOCUMENTING left out. The members of `properties` and the definitions of `$defs` are names,
     * not keywords, so a member named `description` stays.
     */
    private static function canonical(mixed $value, bool $schema = true): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonical($item), $value);
        }

        if (! $value instanceof stdClass) {
            return $value;
        }

        $keys = array_map(strval(...), array_keys(get_object_vars($value)));
        $keys = $schema ? array_values(array_diff($keys, self::DOCUMENTING)) : $keys;
        sort($keys, SORT_STRING);
        $sorted = new stdClass;

        foreach ($keys as $key) {
            $member = self::canonical($value->{$key}, ! $schema || ! in_array($key, ['properties', '$defs'], true));

            // The order of the required keys says nothing.
            if ($schema && $key === 'required' && is_array($member)) {
                $member = array_values(array_filter($member, is_string(...)));
                sort($member, SORT_STRING);
            }

            $sorted->{$key} = $member;
        }

        return $sorted;
    }

    /**
     * @throws GenerationFailed with generate_schema_invalid
     */
    private static function decode(string $json, string $path): mixed
    {
        try {
            return json_decode($json, false, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf('%s is not well-formed JSON: %s', $path, $exception->getMessage()), $exception);
        }
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
