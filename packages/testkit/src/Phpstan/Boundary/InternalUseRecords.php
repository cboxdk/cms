<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Testkit\Phpstan\InternalUseSite;
use Cbox\Cms\Testkit\Phpstan\InternalUseWaiver;
use InvalidArgumentException;
use JsonException;

/**
 * The collected data of InternalUseCollector: each site and each waiver as one JSON string.
 * PHPStan hands collected data from its worker processes to the main process as JSON and keeps
 * it in the result cache, so a record holds only strings and integers.
 *
 * PHPStan keys collected data by the analysed file, so a record leaves that path out, and a
 * site stores its description and source only when they differ from it, in a trait. Decoding
 * takes the key back.
 */
#[Internal]
final class InternalUseRecords
{
    public static function site(InternalUseSite $site): string
    {
        return json_encode(['site' => [
            'line' => $site->line,
            'message' => $site->message,
            'description' => $site->description === $site->file ? null : $site->description,
            'source' => $site->source === $site->file ? null : $site->source,
        ]], JSON_THROW_ON_ERROR);
    }

    public static function waiver(InternalUseWaiver $waiver): string
    {
        return json_encode(['waiver' => ['line' => $waiver->line, 'count' => $waiver->count]], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  string  $file  the analysed file, the key PHPStan collected the record under
     *
     * @throws InvalidArgumentException when the record is not what site() or waiver() writes
     */
    public static function decode(string $file, string $record): InternalUseSite|InternalUseWaiver
    {
        try {
            $decoded = json_decode($record, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('An internal use record is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }

        $site = is_array($decoded) ? ($decoded['site'] ?? null) : null;
        $waiver = is_array($decoded) ? ($decoded['waiver'] ?? null) : null;

        if (is_array($site) && is_int($site['line'] ?? null) && is_string($site['message'] ?? null)) {
            return new InternalUseSite(
                file: $file,
                description: self::optionalString($site, 'description') ?? $file,
                source: self::optionalString($site, 'source') ?? $file,
                line: $site['line'],
                message: $site['message'],
            );
        }

        if (is_array($waiver) && is_int($waiver['line'] ?? null) && is_int($waiver['count'] ?? null) && $waiver['count'] > 0) {
            return new InternalUseWaiver($file, $waiver['line'], $waiver['count']);
        }

        throw new InvalidArgumentException("An internal use record for {$file} is neither a site nor a waiver: {$record}");
    }

    /**
     * @param  array<mixed>  $record
     *
     * @throws InvalidArgumentException when the key holds something else than a string or null
     */
    private static function optionalString(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("The {$key} of an internal use record is not a string.");
        }

        return $value;
    }
}
