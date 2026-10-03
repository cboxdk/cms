<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\InvalidWriteResult;

/**
 * A step of a command form's flow (PRD 13.4): a component of the addon's bundle, registered
 * under the contribution's id, that runs before the submit or after the receipt and may cancel.
 * The core's own confirmation and dry run always run last before the commit, and no step can
 * skip them. Steps are not enforcement: the addon's hooks are.
 *
 * - command: the form's command and version, `<name>@<version>`.
 * - patches: the paths of the command document the step may change, as FieldPath::toString()
 *   writes them, such as "fields.ext.approvals.reason". A step may change only paths below
 *   `ext.<namespace>` of its addon, or any path of a command the addon declares; cms:build
 *   refuses another path, or one the command's schema does not have, as
 *   registry_panel_flow_path_unknown.
 * - timeoutSeconds: how long the step may take, from 1 to 30 seconds.
 *
 * The id, point, priority and scope are those of every contribution (PanelContribution).
 */
#[Experimental]
final readonly class FlowStep implements PanelContribution
{
    public const int MAX_TIMEOUT_SECONDS = 30;

    public int $priority;

    /** @var list<string> */
    public array $patches;

    /**
     * @param  string  $command  the form's command and version, such as "grant.assign@1"
     * @param  list<string>  $patches  the paths it may change
     *
     * @throws InvalidAddonManifest when a value breaks its rule
     */
    public function __construct(
        public ContributionId $id,
        public string $point,
        public string $command,
        public StepPosition $position,
        array $patches = [],
        public int $timeoutSeconds = self::MAX_TIMEOUT_SECONDS,
        int $priority = self::DEFAULT_PRIORITY,
        public Scope $scope = new Scope,
    ) {
        $this->priority = ContributionRules::priority($id->value, $priority);
        $paths = [];

        foreach ($patches as $patch) {
            try {
                $path = FieldPath::fromString($patch)->toString();
            } catch (InvalidWriteResult $invalid) {
                throw InvalidAddonManifest::because(sprintf('The flow step %s patches "%s", which is not a path of a command document such as "fields.ext.approvals.reason". %s', $id->value, $patch, $invalid->getMessage()));
            }

            if (in_array($path, $paths, true)) {
                throw InvalidAddonManifest::because(sprintf('The flow step %s lists the path %s twice in patches. List each once.', $id->value, $path));
            }

            $paths[] = $path;
        }

        sort($paths, SORT_STRING);
        $this->patches = $paths;

        if ($timeoutSeconds < 1 || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw InvalidAddonManifest::because(sprintf('The flow step %s has a timeout of %d seconds. Give 1 to %d seconds.', $id->value, $timeoutSeconds, self::MAX_TIMEOUT_SECONDS));
        }
    }

    public function id(): ContributionId
    {
        return $this->id;
    }

    public function point(): string
    {
        return $this->point;
    }

    public function kind(): PointKind
    {
        return PointKind::FlowStep;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function runsCode(): bool
    {
        return true;
    }
}
