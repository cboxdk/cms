<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A decorator of a default (PRD 13.4): a function of the addon's bundle, registered under the
 * contribution's id, that adds before, after or a badge to a default it never receives, and may
 * tighten the props the point lets it tighten. The host always renders the default once.
 *
 * - tightens: the props it tightens, each once; cms:build refuses one the point does not
 *   declare, as registry_panel_tightening_undeclared.
 * - mirrors: the hook class of the addon that enforces on the server what the decorator blocks.
 *   A decorator that tightens Tighten::DisabledReason blocks a submit, so it must mirror a
 *   ValidateHook or AuthorizeHook of the addon on the one command its scope names, or cms:build
 *   refuses it as registry_panel_check_unmirrored.
 *
 * The id, point, priority and scope are those of every contribution (PanelContribution).
 */
#[Experimental]
final readonly class DecoratorContribution implements PanelContribution
{
    public int $priority;

    /** @var list<Tighten> */
    public array $tightens;

    public ?string $mirrors;

    /**
     * @param  list<Tighten>  $tightens  the props it tightens
     * @param  string|null  $mirrors  the class of the addon's hook it mirrors
     *
     * @throws InvalidAddonManifest when a value breaks its rule
     */
    public function __construct(
        public ContributionId $id,
        public string $point,
        array $tightens = [],
        ?string $mirrors = null,
        int $priority = self::DEFAULT_PRIORITY,
        public Scope $scope = new Scope,
    ) {
        $this->priority = ContributionRules::priority($id->value, $priority);
        $seen = [];

        foreach ($tightens as $tighten) {
            if (isset($seen[$tighten->value])) {
                throw InvalidAddonManifest::because(sprintf('The decorator %s tightens %s twice. List each once.', $id->value, $tighten->value));
            }

            $seen[$tighten->value] = true;
        }

        $this->tightens = $tightens;
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
        return PointKind::Decorator;
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
