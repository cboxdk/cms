<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Codecs\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\FieldPath;
use Throwable;
use UnexpectedValueException;

/**
 * A generated codec refused a JSON document (GUARDRAILS 2.2).
 *
 * - json_malformed: the document is not well-formed JSON, is not an object, nests too deep, or an
 *   object in it has a key twice. $path is null, because nothing of the document was read.
 * - json_invalid: the document is well-formed, but a value is not what the contract version says:
 *   a key is missing or unknown, a value has the wrong type or breaks a rule of its blueprint, or a
 *   field is classified above the caller's classification access. $path names the value, such as
 *   `sources[1].url`.
 */
#[Experimental]
final class DecodingFailed extends UnexpectedValueException
{
    public const string CODE_MALFORMED = 'json_malformed';

    public const string CODE_INVALID = 'json_invalid';

    private function __construct(
        public readonly ErrorCode $errorCode,
        public readonly ?FieldPath $path,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            '[%s] %s%s.',
            $errorCode->value,
            $path instanceof FieldPath ? $path->toString().': ' : '',
            $reason,
        ), 0, $previous);
    }

    /**
     * The document as a whole cannot be read.
     */
    public static function malformed(string $reason, ?Throwable $previous = null): self
    {
        return new self(ErrorCode::from(self::CODE_MALFORMED), null, $reason, $previous);
    }

    /**
     * The value at $path breaks the contract; $path is null for the document itself.
     */
    public static function invalid(?FieldPath $path, string $reason, ?Throwable $previous = null): self
    {
        return new self(ErrorCode::from(self::CODE_INVALID), $path, $reason, $previous);
    }
}
