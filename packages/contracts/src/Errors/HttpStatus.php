<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Errors;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The HTTP statuses the entries of the error catalog name (PRD 6.1, GUARDRAILS 2.1): the status
 * the REST surface answers with, in a problem details document, when a call ends with the code.
 */
#[Experimental]
enum HttpStatus: int
{
    /** The call succeeded, as a dry run does: it computed the plan and committed nothing. */
    case Ok = 200;

    /** The request cannot be read at all, such as a body that is not well-formed JSON. */
    case BadRequest = 400;

    /** The call carried no credential that verifies, so the caller is not known. */
    case Unauthorized = 401;

    /** The caller may not do this, and asking again does not change that. */
    case Forbidden = 403;

    /** The call conflicts with the current state, or with another call that is still running. */
    case Conflict = 409;

    /** The request was understood, but its content is invalid. */
    case UnprocessableContent = 422;

    /** The installation is misconfigured or broken, and the caller can do nothing about it. */
    case InternalServerError = 500;

    /** A dependency is not available right now, or the installation is not ready. */
    case ServiceUnavailable = 503;

    /**
     * The reason phrase of RFC 9110, such as Unprocessable Content.
     */
    public function reason(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::BadRequest => 'Bad Request',
            self::Unauthorized => 'Unauthorized',
            self::Forbidden => 'Forbidden',
            self::Conflict => 'Conflict',
            self::UnprocessableContent => 'Unprocessable Content',
            self::InternalServerError => 'Internal Server Error',
            self::ServiceUnavailable => 'Service Unavailable',
        };
    }
}
