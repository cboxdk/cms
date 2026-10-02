<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Egress\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Core\Egress\Domain\HostClass;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * A mail to one recipient in plain text (GUARDRAILS 3): the host class it is counted under, such as
 * password_reset, the recipient, the subject and the text.
 *
 * The subject is one line of at most MAX_SUBJECT characters without control characters, so it
 * cannot add a header; the text is not empty and at most MAX_TEXT_BYTES bytes. The recipient and
 * the text are personal or secret, such as a reset link, so var_dump() and a stack trace never show
 * them.
 */
#[Experimental]
final readonly class OutboundMail
{
    public const int MAX_SUBJECT = 200;

    public const int MAX_TEXT_BYTES = 65536;

    /**
     * @throws InvalidArgumentException when the subject or the text is not in its form
     */
    public function __construct(
        public HostClass $hostClass,
        #[SensitiveParameter] public EmailAddress $to,
        public string $subject,
        #[SensitiveParameter] public string $text,
    ) {
        if (trim($subject) === '' || mb_strlen($subject, 'UTF-8') > self::MAX_SUBJECT || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1) {
            throw new InvalidArgumentException(sprintf('A mail\'s subject is one line of 1 to %d characters without control characters.', self::MAX_SUBJECT));
        }

        if (trim($text) === '' || strlen($text) > self::MAX_TEXT_BYTES) {
            throw new InvalidArgumentException(sprintf('A mail\'s text is not empty and at most %d bytes.', self::MAX_TEXT_BYTES));
        }
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['hostClass' => $this->hostClass->value, 'to' => '[personal]', 'subject' => $this->subject, 'text' => '[secret]'];
    }
}
