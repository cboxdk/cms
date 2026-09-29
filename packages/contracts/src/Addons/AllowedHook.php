<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Addons;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Phase;

/**
 * A hook an addon's manifest allows: a command class and a phase (PRD 13.1, 6.3). The manifest
 * allows or refuses hooks; it does not repeat them. Each hook class of the addon still declares
 * itself with #[Hook], and cms:build refuses a #[Hook] of the addon's package whose command and
 * phase no AllowedHook of its manifest names, as registry_undeclared_hook.
 */
#[Experimental]
final readonly class AllowedHook
{
    public string $command;

    /**
     * @param  string  $command  the command class, such as PublishNote::class
     *
     * @throws InvalidAddonManifest when the command is not a class name
     */
    public function __construct(
        string $command,
        public Phase $phase,
    ) {
        $this->command = ClassNames::check('an allowed hook\'s command', $command);
    }

    /**
     * Whether it allows a hook of the phase on the command class. PHP class names are compared
     * without case, as PHP compares them.
     */
    public function allows(string $command, Phase $phase): bool
    {
        return $phase === $this->phase && strcasecmp(ltrim($command, '\\'), $this->command) === 0;
    }
}
