<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A notice on the login and other credential pages (PRD 13.4): a translated plain-text notice
 * with a tone. It is data, because no addon code runs on a credential page.
 *
 * - message: the translation key of the notice in the addon's catalogue.
 *
 * The id, point, priority and scope are those of every contribution (PanelContribution).
 */
#[Experimental]
final readonly class LoginNotice implements PanelContribution
{
    public int $priority;

    public string $message;

    /**
     * @param  string  $message  the translation key of the notice
     *
     * @throws InvalidAddonManifest when a value breaks its rule
     */
    public function __construct(
        public ContributionId $id,
        public string $point,
        string $message,
        public Tone $tone = Tone::Neutral,
        int $priority = self::DEFAULT_PRIORITY,
        public Scope $scope = new Scope,
    ) {
        $this->priority = ContributionRules::priority($id->value, $priority);
        $this->message = ContributionRules::translationKey($id->value, 'message', $message);
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
        return PointKind::Data;
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
        return false;
    }
}
