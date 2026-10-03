<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A check of a command form (PRD 13.4): a pure function of the addon's bundle, registered under
 * the contribution's id, from the command document to issues. Checks only add issues, and the
 * server's errors replace them after a submit.
 *
 * - command: the form's command and version, `<name>@<version>`, such as "entry.create@1".
 * - severity: how much its issues weigh. An Error blocks the client submit, so the check must
 *   mirror a ValidateHook or AuthorizeHook of the addon on the same command (mirrors), or
 *   cms:build refuses it as registry_panel_check_unmirrored; the rule then holds over REST, MCP
 *   and the CLI too.
 *
 * The id, point, priority and scope are those of every contribution (PanelContribution).
 */
#[Experimental]
final readonly class FormCheck implements PanelContribution
{
    public int $priority;

    public ?string $mirrors;

    /**
     * @param  string  $command  the form's command and version, such as "entry.create@1"
     * @param  string|null  $mirrors  the class of the addon's hook it mirrors
     *
     * @throws InvalidAddonManifest when a value breaks its rule
     */
    public function __construct(
        public ContributionId $id,
        public string $point,
        public string $command,
        public Severity $severity,
        ?string $mirrors = null,
        int $priority = self::DEFAULT_PRIORITY,
        public Scope $scope = new Scope,
    ) {
        $this->priority = ContributionRules::priority($id->value, $priority);
        $this->mirrors = $mirrors === null ? null : ContributionRules::className($id->value, 'mirrored hook', $mirrors);
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
        return PointKind::FormCheck;
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
