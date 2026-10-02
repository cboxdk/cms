<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress;

use Override;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * A Symfony mail transport for the tests of LaravelMailGateway: it keeps every mail it takes, until
 * breakDown(), after which it refuses each as an SMTP server that does not answer does. It checks
 * the message as a real transport does before sending, so a mail without a sender fails.
 */
final class SwitchableTransport implements TransportInterface
{
    /** @var list<Email> */
    public array $taken = [];

    private bool $broken = false;

    public function breakDown(): void
    {
        $this->broken = true;
    }

    #[Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): SentMessage
    {
        if ($this->broken) {
            throw new TransportException('Connection could not be established with host "smtp.example.net:587": ada@example.org');
        }

        if ($message instanceof Message) {
            $message->ensureValidity();
        }

        $sent = new SentMessage($message, $envelope ?? Envelope::create($message));

        if ($message instanceof Email) {
            $this->taken[] = $message;
        }

        return $sent;
    }

    #[Override]
    public function __toString(): string
    {
        return 'switchable://';
    }
}
