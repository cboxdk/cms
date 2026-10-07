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
 * - data: a #[Query] of the addon, or null, as a SlotFill's. The panel runs it as the viewer
 *   through the query pipeline, at the lower of the viewer's classification access and the
 *   addon's reads capability, with the query's input taken from the point's props by name, and
 *   hands its result to the component beside the point's props, as `data`. A point whose props
 *   the page holds in the browser gives a query no input, so such a query takes none. cms:build
 *   refuses a query that is not the addon's or whose input the props cannot give, as
 *   registry_panel_data_query_invalid. The core's pickers of a command form's fields read the
 *   kernel's lists this way.
 *
 * The id, point, priority and scope are those of every contribution (PanelContribution).
 */
#[Experimental]
final readonly class ReplacementContribution implements PanelContribution
{
    public const int KEY_MAX_LENGTH = 255;

    public int $priority;

    public string $key;

    public ?string $data;

    /**
     * @param  string  $key  the target it replaces
     * @param  string|null  $data  the class of a #[Query] of the addon, such as ListNodes::class
     *
     * @throws InvalidAddonManifest when a value breaks its rule
     */
    public function __construct(
        public ContributionId $id,
        public string $point,
        string $key,
        ?string $data = null,
        int $priority = self::DEFAULT_PRIORITY,
        public Scope $scope = new Scope,
    ) {
        $this->priority = ContributionRules::priority($id->value, $priority);
        if ($key === '' || strlen($key) > self::KEY_MAX_LENGTH || preg_match('/\s/', $key) === 1) {
            throw InvalidAddonManifest::because(sprintf('The replacement %s replaces "%s", which is not a key: a field type, a class or a command and version of at most %d characters, without spaces.', $id->value, $key, self::KEY_MAX_LENGTH));
        }

        $this->key = ltrim($key, '\\');
        $this->data = $data === null ? null : ContributionRules::className($id->value, 'data query', $data);
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
