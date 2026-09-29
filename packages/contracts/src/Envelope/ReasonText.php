<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;
use SensitiveParameter;

/**
 * The free text of a reason, as the person wrote it: 1 to 4000 bytes of UTF-8. It can name
 * people and hold personal data, so it is stored only as classified content on the changeset,
 * under the same deletion rules as other content, and never in the audit chain or an event
 * (PRD 6.1, 12.12). The text is not a public property, so it is read on purpose, with
 * classifiedContent(), by the code that stores it as classified content.
 */
#[Experimental]
final readonly class ReasonText
{
    private string $text;

    public function __construct(#[SensitiveParameter] string $text)
    {
        if (trim($text) === '' || strlen($text) > 4000 || ! mb_check_encoding($text, 'UTF-8')) {
            throw InvalidEnvelope::reasonText();
        }

        $this->text = $text;
    }

    /**
     * The text, for the code that stores it as classified content on the changeset.
     */
    public function classifiedContent(): string
    {
        return $this->text;
    }
}
