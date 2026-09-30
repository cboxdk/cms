<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use JsonException;

/**
 * The envelope options of cms:run as the document a caller of another surface sends (GUARDRAILS
 * 2.1, 2.2): --idempotency-key as idempotency_key, --dry-run as dry_run and --wait-level as
 * wait_level, each only when given, in a JSON object. It is the CLI's request body: it checks
 * nothing, and the envelope's generated codec reads it by the rules of envelope.v1.json, as it reads
 * the body of a request.
 */
#[Internal]
final readonly class EnvelopeOptions
{
    /**
     * @throws CliCallRefused when an option is not UTF-8 text
     */
    public static function document(mixed $idempotencyKey, mixed $dryRun, mixed $waitLevel): string
    {
        $fields = [];

        if ($idempotencyKey !== null) {
            $fields['idempotency_key'] = $idempotencyKey;
        }

        if ($dryRun === true) {
            $fields['dry_run'] = true;
        }

        if ($waitLevel !== null) {
            $fields['wait_level'] = $waitLevel;
        }

        try {
            return json_encode((object) $fields, JSON_THROW_ON_ERROR);
        } catch (JsonException $unencodable) {
            throw CliCallRefused::usage('An envelope option is not valid UTF-8 text.', $unencodable);
        }
    }
}
