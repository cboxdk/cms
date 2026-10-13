<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Core\Maintenance\Domain\Dto\ServiceRegistration;

/**
 * Reads the options of cms:service:create into a ServiceRegistration: --name, the display name,
 * --email, the contact address, and --responsible, the UUIDv7 of the active staff actor
 * responsible for the service actor. Anything else is a usage error, exit 64, whose message never
 * repeats a value.
 */
#[Internal]
final readonly class ServiceCreateInput
{
    /**
     * @throws CliCallRefused
     */
    public static function read(mixed $email, mixed $name, mixed $responsible): ServiceRegistration
    {
        if (! is_string($email) || $email === '' || ! is_string($name) || $name === '' || ! is_string($responsible) || $responsible === '') {
            throw CliCallRefused::usage('cms:service:create needs --email=<address>, --name=<display name> and --responsible=<the UUIDv7 of the active staff actor responsible for the service actor>.');
        }

        try {
            $address = new EmailAddress($email);
        } catch (InvalidIdentity $invalid) {
            throw CliCallRefused::usage('--email: '.$invalid->getMessage(), $invalid);
        }

        try {
            $displayName = new DisplayName($name);
        } catch (InvalidIdentity $invalid) {
            throw CliCallRefused::usage('--name: '.$invalid->getMessage(), $invalid);
        }

        try {
            $staff = ActorId::fromString($responsible);
        } catch (InvalidUuid7 $invalid) {
            throw CliCallRefused::usage('--responsible: give the UUIDv7 of the active staff actor responsible for the service actor.', $invalid);
        }

        return new ServiceRegistration($displayName, $address, $staff);
    }
}
