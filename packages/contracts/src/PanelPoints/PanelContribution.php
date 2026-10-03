<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One contribution an addon's PanelContributions make to a panel point (PRD 13.4). The classes
 * that implement it are the kinds of contribution: SlotFill, ActionContribution,
 * NavContribution, PageContribution, DecoratorContribution, ReplacementContribution, FormCheck,
 * FlowStep, ObserverContribution, ProviderContribution and LoginNotice. An addon builds them in
 * its manifest; it does not implement this interface.
 *
 * - id(): the contribution's id, `<namespace>.<local>` with the addon's namespace, unique in the
 *   installation. A contribution that runs code is registered in the addon's bundle under it.
 * - point(): the id of the point it contributes to, `<name>@<version>`.
 * - kind(): the kind of point it fits; cms:build refuses it at a point of another kind.
 * - priority(): its place among the point's contributions, the lowest first; DEFAULT_PRIORITY
 *   puts it after the core's own, which are at 100, 200 and so on.
 * - scope(): where it applies (Scope).
 * - runsCode(): whether the addon's bundle carries code for it. An action, a nav entry and a
 *   login notice are data the host renders, and run no code of the addon.
 *
 * cms:build checks that the id is in the addon's namespace and once in the installation
 * (registry_panel_duplicate_contribution), and that the point is declared
 * (registry_panel_unknown_point); the point is a string, so a manifest with a wrong one still
 * builds and the build reports it with its code.
 */
#[Experimental]
interface PanelContribution
{
    /** The priority of a contribution that gives none: after the core's own. */
    public const int DEFAULT_PRIORITY = 1000;

    /** The highest priority a contribution may have. */
    public const int MAX_PRIORITY = 1000000;

    public function id(): ContributionId;

    public function point(): string;

    public function kind(): PointKind;

    public function priority(): int;

    public function scope(): Scope;

    public function runsCode(): bool;
}
