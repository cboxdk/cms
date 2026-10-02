<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Staff\Boundary;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Identity\Staff\Domain\Dto\StaffRegistration;
use SensitiveParameter;

/**
 * Reads what cms:staff:create was given into a StaffRegistration: the options --email and --name,
 * and the password, which is never an argument or an option. It is read hidden from the terminal,
 * typed twice, or with --password-stdin from standard input, where a newline at its end is not
 * part of it. A usage error is a CliAnswer with exit 64 whose message never repeats a value.
 */
#[Internal]
final readonly class StaffCreateInput
{
    /** The most read from standard input: a password longer than the policy's limit is refused there, not cut. */
    private const int STDIN_LIMIT = 1_048_576;

    /**
     * A usage error for --email or --name, or null when both are valid. It is asked before the
     * password, so nobody types a password for a call that cannot run.
     */
    public static function options(mixed $email, mixed $name): ?CliAnswer
    {
        if (! is_string($email) || $email === '' || ! is_string($name) || $name === '') {
            return self::usage('cms:staff:create needs --email=<address> and --name=<display name>.');
        }

        try {
            new EmailAddress($email);
        } catch (InvalidIdentity $invalid) {
            return self::usage('--email: '.$invalid->getMessage());
        }

        try {
            new DisplayName($name);
        } catch (InvalidIdentity $invalid) {
            return self::usage('--name: '.$invalid->getMessage());
        }

        return null;
    }

    /**
     * The registration of valid options and the password, or a usage error when there is no
     * password.
     */
    public static function registration(string $email, string $name, #[SensitiveParameter] string|CliAnswer|null $password): StaffRegistration|CliAnswer
    {
        if ($password instanceof CliAnswer) {
            return $password;
        }

        if ($password === null || $password === '') {
            return self::usage('cms:staff:create needs a password: type it when asked in a terminal, or pipe it to standard input with --password-stdin. It is never an argument.');
        }

        return new StaffRegistration(new EmailAddress($email), new DisplayName($name), new Password($password));
    }

    /**
     * The password piped to standard input, without the line break at its end, or null when the
     * stream gives nothing.
     *
     * @param  resource  $stream
     */
    public static function fromStream($stream): ?string
    {
        $read = stream_get_contents($stream, self::STDIN_LIMIT);

        if (! is_string($read)) {
            return null;
        }

        $password = preg_replace('/\r?\n\z/', '', $read, 1);

        return is_string($password) && $password !== '' ? $password : null;
    }

    /**
     * The password typed twice in a terminal, or a usage error when the two differ.
     */
    public static function typedTwice(#[SensitiveParameter] mixed $first, #[SensitiveParameter] mixed $second): string|CliAnswer
    {
        if (! is_string($first) || $first === '') {
            return self::usage('No password was typed.');
        }

        return $first === $second ? $first : self::usage('The two passwords differ. Run cms:staff:create again.');
    }

    public static function usage(string $message): CliAnswer
    {
        return new CliAnswer(ExitCode::Usage, [], [$message]);
    }
}
