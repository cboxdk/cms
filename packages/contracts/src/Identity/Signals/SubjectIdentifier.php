<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;

/**
 * The subject a security event names (RFC 9493). Only the format iss_sub carries its values, the
 * issuer and the subject: it is the only format the core admits (PRD 5.16), so the values of the
 * others, such as an email address, which is personal data, are never held.
 */
#[Experimental]
final readonly class SubjectIdentifier
{
    private function __construct(public SubjectFormat $format, public ?Issuer $issuer, public ?Subject $subject) {}

    /**
     * The format iss_sub: the subject as its issuer knows it.
     */
    public static function issuerAndSubject(Issuer $issuer, Subject $subject): self
    {
        return new self(SubjectFormat::IssSub, $issuer, $subject);
    }

    /**
     * A subject in any other format, such as email, which the core refuses.
     *
     * @throws InvalidIdentity for the format iss_sub, which carries its values
     */
    public static function inFormat(SubjectFormat $format): self
    {
        if ($format === SubjectFormat::IssSub) {
            throw InvalidIdentity::signalValue('subject of the format iss_sub', 'built with its issuer and subject');
        }

        return new self($format, null, null);
    }
}
