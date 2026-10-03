<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PointId;

/**
 * What the install screen shows of an addon before the installation approves it (PRD 13.1, 13.4):
 * its entry in addons.php (namespace, package, core API, reads, the commands its panel UI issues,
 * whether it ships a theme, its panel API, the experimental points it accepts and its bundle), the
 * points its contributions touch, sorted, with the experimental ones among them, the number of
 * its hooks and subscribers, and the trust statement of its panel UI: installed addon UI runs in
 * the panel's window with the viewer's session, and the kernel enforces what the addon may do on
 * the server, not in the browser.
 */
#[Experimental]
final readonly class AddonDisclosure
{
    /** What the install screen says of every addon with panel UI. */
    public const string IN_WINDOW_TRUST = 'This addon\'s panel UI runs in the panel\'s window with the viewer\'s session. It can read what the viewer sees on a page and act as the viewer on the panel\'s origin. The server still decides every command and query by the viewer\'s grants, and hands a contribution data only up to the addon\'s reads.';

    /**
     * @param  list<PointId>  $points
     * @param  list<PointId>  $experimental
     */
    public function __construct(
        public AddonEntry $addon,
        public array $points,
        public array $experimental,
        public int $hooks,
        public int $subscribers,
    ) {}

    /**
     * The trust statement of the addon's panel UI, or null for an addon without UI.
     */
    public function trust(): ?string
    {
        return $this->addon->panel instanceof AddonPanel ? self::IN_WINDOW_TRUST : null;
    }
}
