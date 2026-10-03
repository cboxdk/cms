<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A replacement of one target of a replaceable point (PRD 13.4): a component of the addon's
 * bundle, registered under the contribution's id, with the exact props of the point, that takes
 * the default's place for one key. The default stays mounted, and renders when the replacement
 * throws.
 *
 * - key: the target it replaces, of the kind the point is keyed by: a field type
 *   (`<namespace>:<handle>`), a bound value class or a command (`<name>@<version>`). At a point
 *   with Ownership::Own the addon may replace only keys it owns: its own field types, value
 *   classes and commands (registry_panel_unowned_target). Two replacements of one key fail the
 *   build unless cbox-cms.panel.replacements names the winner
 *   (registry_panel_replacement_conflict).
 *
 * The id, point, priority and scope are those of every contribution (PanelContribution).
 */
#[Experimental]
final readonly class ReplacementContribution implements PanelContribution
{
    public const int KEY_MAX_LENGTH = 255;

    public int $priority;

    public string $key;

    /**
     * @param  string  $key  the target it replaces
     *
     * @throws InvalidAddonManifest when a value breaks its rule
     */
    public function __construct(
        public ContributionId $id,
        public string $point,
        string $key,
        int $priority = self::DEFAULT_PRIORITY,
        public Scope $scope = new Scope,
    ) {
        $this->priority = ContributionRules::priority($id->value, $priority);
        if ($key === '' || strlen($key) > self::KEY_MAX_LENGTH || preg_match('/\s/', $key) === 1) {
            throw InvalidAddonManifest::because(sprintf('The replacement %s replaces "%s", which is not a key: a field type, a class or a command and version of at most %d characters, without spaces.', $id->value, $key, self::KEY_MAX_LENGTH));
        }

        $this->key = ltrim($key, '\\');
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
        return PointKind::Replacement;
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
