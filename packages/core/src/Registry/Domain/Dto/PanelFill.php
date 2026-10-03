<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Contracts\PanelPoints\ReplacementContribution;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Core\Registry\Domain\FillSource;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * One contribution to a panel point in the registry (PRD 13.4): the contribution as the addon's
 * manifest declares it, the Composer package that contributes it, and what cms:build compiled for
 * it:
 *
 * - priority: the priority it renders at, the lowest first, and ordering: whether that is the
 *   addon's or the installation's (cbox-cms.panel.contributions).
 * - enabled and enabling: whether the host renders it, and why: the addon's default, the
 *   installation's settings (cbox-cms.panel.contributions, or a replacement another won in
 *   cbox-cms.panel.replacements), or the activation state (cbox-cms.panel.disabled), which the
 *   panel applies at each request without a rebuild.
 * - command: the command an action runs, or the form's command of a check or a flow step.
 * - query: the data query of a slot fill or a page.
 *
 * Its id's first segment is the addon's namespace, so the id's order is the order by namespace,
 * then by the rest of the id.
 */
#[Experimental]
final readonly class PanelFill
{
    public ContributionId $contribution;

    public string $package;

    public Scope $scope;

    /**
     * @throws InvalidRegistryEntry for a package that is not a package name, or an order from the activation state
     */
    public function __construct(
        public PanelContribution $declaration,
        string $package,
        public int $priority,
        public FillSource $ordering = FillSource::Addon,
        public bool $enabled = true,
        public FillSource $enabling = FillSource::Addon,
        public ?CommandRef $command = null,
        public ?CommandRef $query = null,
    ) {
        $this->contribution = $declaration->id();

        if ($ordering === FillSource::Activation) {
            throw new InvalidRegistryEntry(sprintf('The order of the contribution %s comes from the addon or the installation; the activation state only enables and disables.', $this->contribution->value));
        }

        $this->package = InvalidRegistryEntry::checkPackage($package);
        $this->scope = $declaration->scope();
    }

    public function addon(): AddonNamespace
    {
        return $this->contribution->namespace();
    }

    /**
     * The key a replacement replaces, or null for any other contribution.
     */
    public function key(): ?string
    {
        return $this->declaration instanceof ReplacementContribution ? $this->declaration->key : null;
    }

    /**
     * The same fill, disabled by the activation state.
     */
    public function deactivated(): self
    {
        return new self($this->declaration, $this->package, $this->priority, $this->ordering, false, FillSource::Activation, $this->command, $this->query);
    }

    /**
     * The same fill as the installation sets it: another priority, or another enabled state.
     */
    public function overridden(?int $priority, ?bool $enabled): self
    {
        return new self(
            $this->declaration,
            $this->package,
            $priority ?? $this->priority,
            $priority === null ? $this->ordering : FillSource::Installation,
            $enabled ?? $this->enabled,
            $enabled === null ? $this->enabling : FillSource::Installation,
            $this->command,
            $this->query,
        );
    }
}
