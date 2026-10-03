<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Addons;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;

/**
 * What an addon may do through the kernel, as its manifest declares it and the installation
 * approves it (PRD 13.1). The install screen shows every one, and the kernel enforces each at its
 * boundary (invariant 21):
 *
 * - reads: the highest classification of fields the kernel hands the addon's hooks and its panel
 *   contributions. A hook sees a plan view with the fields up to the lower of this and the actor's
 *   classification access, and a transform hook cannot change a field above it. Public, the
 *   default, hands it only public fields.
 * - issues: the command classes the addon's panel UI may run, through its actions and the host's
 *   runCommand (PRD 13.4), each once. cms:build refuses one that is not a registered command
 *   exposed on Inertia, and an action whose command is not listed, as
 *   registry_panel_command_not_issuable. It limits what the panel offers; what the viewer may do
 *   is still decided by the viewer's grants on the server.
 * - uiTheme: whether the addon may ship a theme of token values for the panel. The application
 *   still selects and orders the themes it uses.
 */
#[Experimental]
final readonly class AddonCapabilities
{
    /** @var list<string> sorted */
    public array $issues;

    /**
     * @param  list<string>  $issues  command classes, such as RequestAccess::class
     *
     * @throws InvalidAddonManifest when a command is not a class name or is listed twice
     */
    public function __construct(
        public ClassificationAccess $reads = ClassificationAccess::Public,
        array $issues = [],
        public bool $uiTheme = false,
    ) {
        $checked = [];

        foreach ($issues as $command) {
            $class = ClassNames::check('a command the addon\'s panel UI issues', $command);

            if (array_key_exists(strtolower($class), $checked)) {
                throw InvalidAddonManifest::because(sprintf('The command %s is listed twice in the issues of the addon\'s capabilities. List each once.', $class));
            }

            $checked[strtolower($class)] = $class;
        }

        ksort($checked, SORT_STRING);
        $this->issues = array_values($checked);
    }

    /**
     * Whether the addon's panel UI may issue the command class. PHP class names are compared
     * without case, as PHP compares them.
     */
    public function mayIssue(string $command): bool
    {
        return array_any($this->issues, static fn (string $issued): bool => strcasecmp($issued, ltrim($command, '\\')) === 0);
    }
}
