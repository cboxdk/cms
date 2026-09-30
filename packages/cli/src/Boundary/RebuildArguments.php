<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildRequest;
use InvalidArgumentException;

/**
 * The arguments of cms:types:rebuild as a RebuildRequest: the type, `<owner>:<handle>`, and the
 * name of the run, --run, or the Clock's time in UTC, such as 20260930T120000Z, when it is left
 * out. A run that stopped is resumed by running again with its name.
 */
#[Internal]
final readonly class RebuildArguments
{
    public const string RUN_FORMAT = 'Ymd\THis\Z';

    /**
     * @throws InvalidArgumentException when the type is not a type name or the run's name is not visible ASCII of at most 100 characters
     */
    public static function request(mixed $type, mixed $run, Clock $clock): RebuildRequest
    {
        if (! is_string($type)) {
            throw new InvalidArgumentException('Name the type to rebuild as <owner>:<handle>.');
        }

        try {
            $name = new TypeName($type);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(sprintf('"%s" is not a type name; name the type to rebuild as <owner>:<handle>.', $type));
        }

        if ($run === null) {
            return new RebuildRequest($name, $clock->now()->format(self::RUN_FORMAT));
        }

        if (! is_string($run) || strlen($run) > 100 || preg_match('/\A[\x21-\x7E]+\z/', $run) !== 1) {
            throw new InvalidArgumentException('--run names the run with 1 to 100 visible ASCII characters, such as nightly-2031-05-01.');
        }

        return new RebuildRequest($name, $run);
    }
}
