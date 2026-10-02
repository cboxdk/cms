<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use SensitiveParameter;

/**
 * A credential as the transport carried it, before it is verified (PRD 5.16): a bearer token, such
 * as the one of an Authorization header, or a session id, such as the one of the session cookie,
 * as $form says. The value is a secret: it is kept out of stack traces and var_dump(), and is read
 * only with reveal().
 */
#[Experimental]
final readonly class TransportCredential
{
    public function __construct(
        #[SensitiveParameter] private string $value,
        public CredentialForm $form = CredentialForm::Bearer,
    ) {}

    /**
     * The session id of a person who logged in, as the session cookie carried it.
     */
    public static function session(#[SensitiveParameter] string $value): self
    {
        return new self($value, CredentialForm::Session);
    }

    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[secret]', 'form' => $this->form->value];
    }
}
