<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Why a command is run, where the command requires it: withdrawal, redaction, deletion,
 * reinstatement and grant changes (PRD 6.1). The code goes into the audit chain and events; the
 * optional free text is classified content on the changeset and never leaves it (PRD 12.12).
 */
#[Experimental]
final readonly class Reason
{
    public function __construct(
        public ReasonCode $code,
        public ?ReasonText $text = null,
    ) {}

    /**
     * What the audit chain and events may hold of the reason: the code alone.
     */
    public function forAudit(): ReasonCode
    {
        return $this->code;
    }
}
