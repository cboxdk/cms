<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\DecoratorContribution;
use Cbox\Cms\Contracts\PanelPoints\FlowStep;
use Cbox\Cms\Contracts\PanelPoints\FormCheck;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\Ownership;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Contracts\PanelPoints\PointDeprecation;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\ReplacementContribution;
use Cbox\Cms\Contracts\PanelPoints\ReplacementKey;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonBundle;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonPanel;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildWarning;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleFile;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleManifest;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleSigning;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledBundle;
use Cbox\Cms\Core\Registry\Domain\Dto\ContractShapes;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredHook;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\IssuedCommand;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelCompilation;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\QueryEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\SchemaNode;
use Cbox\Cms\Core\Registry\Domain\Dto\SignaturePolicy;

/**
 * Compiles the addons' panel contributions into the panel registry (PRD 13.4, 13.1): every
 * contribution is held to the points the scan roots declare, to its addon's manifest, to the
 * commands, queries and hooks the build registered and to their JSON Schemas, then ordered and
 * enabled as the installation's settings say. Each refusal is a problem with its code, and the
 * build fails on any. It is pure: it reads only what it is given.
 *
 * Per addon: the panel API version it needs (registry_incompatible_panel_api), the experimental
 * points it accepts, and the commands it issues, each a registered command exposed on Inertia
 * (registry_panel_command_not_issuable). Per contribution: an id in the addon's namespace, once in
 * the installation (registry_panel_duplicate_contribution), at a declared point
 * (registry_panel_unknown_point) that is not internal (registry_panel_internal_point), of the
 * point's kind (registry_panel_kind_mismatch), accepted when the point is experimental
 * (registry_panel_experimental_not_accepted), with a scope of registered commands and queries
 * (registry_panel_unknown_command), and the checks of its kind: an action's command and prefill,
 * a data query, a nav entry's page, a decorator's tightening and mirror, a check's mirror, a
 * step's paths, and a replacement's ownership. Then the bundle, then the replacements of each key
 * (registry_panel_replacement_conflict), then the installation's overrides
 * (registry_panel_override_invalid).
 *
 * It warns about every contribution to an experimental or a deprecated point.
 *
 * The core's own contributions, in the namespace cms (DeclaresCoreContributions), are compiled with
 * the addons' and held to the same rules, less those that limit an addon to what it owns: the core
 * runs any registered command exposed on Inertia and reads any query of cboxdk/cms, contributes to
 * experimental and #[Internal] points without opting in, replaces any key, patches any path, and
 * mirrors nothing, because the kernel enforces its own rules on the server. They have no bundle:
 * the panel's own JavaScript registers those that run code.
 */
#[Experimental]
final readonly class PanelCompiler
{
    /** The core's own contributions' namespace; no addon contributes in it. */
    public const string CORE_NAMESPACE = 'cms';

    /** The package of the core's own contributions. */
    public const string CORE_PACKAGE = 'cboxdk/cms';

    /**
     * @param  list<PanelPointEntry>  $points  as the scan found them
     * @param  list<AddonManifest>  $manifests  one per package, those the installation allows
     * @param  list<ActionEntry>  $actions  the compiled actions
     * @param  array<string, AddonBundle>  $bundles  by package
     * @param  list<PanelContribution>  $core  the core's own contributions, in the namespace cms
     */
    public function compile(
        array $points,
        array $manifests,
        Discovery $discovery,
        array $actions,
        BuildSettings $settings,
        ContractShapes $shapes,
        array $bundles,
        array $core = [],
    ): PanelCompilation {
        $context = new PanelContext($points, $discovery, $actions, $shapes);
        $problems = [];
        $warnings = [];
        $fills = [];
        $addons = [];
        $owners = [];

        usort($manifests, static fn (AddonManifest $a, AddonManifest $b): int => strcmp($a->namespace->value, $b->namespace->value));

        foreach ($manifests as $manifest) {
            $issues = $this->issues($manifest, $context, $problems);
            $panel = $manifest->panel;
            $compiled = null;

            if ($panel instanceof PanelContributions) {
                $compiled = $this->addonPanel($manifest, $panel, $context, $bundles[$manifest->package] ?? null, $settings, $owners, $fills, $problems, $warnings);
            }

            $addons[] = new AddonEntry($manifest->namespace, $manifest->package, $manifest->coreApi, $manifest->capabilities->reads, $issues, $manifest->capabilities->uiTheme, $compiled);
        }

        foreach ($core as $contribution) {
            $fill = $this->contribution(null, null, $contribution, $context, [], $owners, $problems, $warnings);

            if ($fill instanceof PanelFill) {
                $fills[$contribution->point()][] = $fill;
            }
        }

        $fills = $this->chooseReplacements($fills, $context, $settings, $problems);
        $fills = $this->override($fills, $context, $settings, $problems);

        $entries = array_map(
            static fn (PanelPointEntry $point): PanelPointEntry => new PanelPointEntry($point->declaration, $point->class, $point->package, $point->stability, $fills[$point->id()->toString()] ?? []),
            $context->points,
        );

        return new PanelCompilation($entries, $addons, [...$settings->problems, ...$problems], $warnings);
    }

    /**
     * The commands the addon's panel UI may issue, each a registered command exposed on Inertia.
     *
     * @param  list<BuildProblem>  $problems
     * @return list<IssuedCommand>
     */
    private function issues(AddonManifest $manifest, PanelContext $context, array &$problems): array
    {
        $issues = [];

        foreach ($manifest->capabilities->issues as $class) {
            $command = $context->commandOfClass($class);

            if (! $command instanceof CommandEntry || ! $context->exposedOnInertia($command)) {
                $problems[] = new BuildProblem(BuildErrorCode::PanelCommandNotIssuable, sprintf(
                    'Addon "%s" (%s) issues %s, which is %s. The panel runs a command through the Inertia profile, so list in issues only a #[Command] class whose #[Action] lists Surface::Inertia.',
                    $manifest->namespace->value,
                    $manifest->package,
                    $class,
                    $command instanceof CommandEntry ? sprintf('the command %s@%d, not exposed on Inertia', $command->name->value, $command->version) : 'not a command any scan root registers',
                ));

                continue;
            }

            $issues[] = new IssuedCommand(new CommandRef($command->name, $command->version), $command->class);
        }

        return $issues;
    }

    /**
     * Checks one addon's panel contributions and adds its fills.
     *
     * @param  array<string, string>  $owners  the addon of each contribution id seen so far
     * @param  array<string, list<PanelFill>>  $fills  by point id
     * @param  list<BuildProblem>  $problems
     * @param  list<BuildWarning>  $warnings
     */
    private function addonPanel(
        AddonManifest $manifest,
        PanelContributions $panel,
        PanelContext $context,
        ?AddonBundle $bundle,
        BuildSettings $settings,
        array &$owners,
        array &$fills,
        array &$problems,
        array &$warnings,
    ): AddonPanel {
        $addon = $manifest->namespace->value;
        $panelApi = PanelApiVersion::current();

        if (! $panel->sdk->satisfiedBy($panelApi)) {
            $problems[] = new BuildProblem(BuildErrorCode::IncompatiblePanelApi, sprintf(
                'Addon "%s" (%s) needs the panel API %s, and this panel has %s. Install a version of the addon made for panel API %s, or a panel whose API it needs.',
                $addon,
                $manifest->package,
                $panel->sdk->constraint(),
                $panelApi->toString(),
                $panelApi->constraint(),
            ));
        }

        $accepted = [];

        foreach ($panel->acceptsExperimental as $text) {
            $point = $this->acceptedPoint($manifest, $text, $context, $problems);

            if ($point instanceof PointId) {
                $accepted[] = $point;
            }
        }

        if ($addon === self::CORE_NAMESPACE) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelDuplicateContribution, sprintf(
                'Addon "%s" (%s) contributes to the panel in the namespace cms, which holds the core\'s own contributions. Give the addon a namespace of its own.',
                $addon,
                $manifest->package,
            ));

            return new AddonPanel($panel->sdk, $accepted, null);
        }

        $pages = [];
        $paths = [];

        foreach ($panel->contributions as $contribution) {
            if ($contribution instanceof PageContribution) {
                $pages[$contribution->id->value] = true;

                if (isset($paths[$contribution->path])) {
                    $problems[] = new BuildProblem(BuildErrorCode::PanelDuplicateContribution, sprintf(
                        'The pages %s and %s of addon "%s" (%s) both have the path "%s". Give each page of the addon its own path below x/%s/.',
                        $paths[$contribution->path],
                        $contribution->id->value,
                        $addon,
                        $manifest->package,
                        $contribution->path,
                        $addon,
                    ));
                }

                $paths[$contribution->path] ??= $contribution->id->value;
            }
        }

        $code = [];

        foreach ($panel->contributions as $contribution) {
            $fill = $this->contribution($manifest, $panel, $contribution, $context, $pages, $owners, $problems, $warnings);

            if ($contribution->runsCode()) {
                $code[] = $contribution->id()->value;
            }

            if ($fill instanceof PanelFill) {
                $fills[$contribution->point()][] = $fill;
            }
        }

        sort($code, SORT_STRING);

        return new AddonPanel($panel->sdk, $accepted, $this->bundle($manifest, $panel, $bundle, $code, $settings, $problems));
    }

    /**
     * @param  list<BuildProblem>  $problems
     */
    private function acceptedPoint(AddonManifest $manifest, string $text, PanelContext $context, array &$problems): ?PointId
    {
        $entry = $context->point($text);

        if (! $entry instanceof PanelPointEntry) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelUnknownPoint, sprintf(
                'Addon "%s" (%s) accepts the experimental point "%s", which %s. List the id of a declared point in acceptsExperimental, such as "account.me.sections@1"; cms:panel:points lists them.',
                $manifest->namespace->value,
                $manifest->package,
                $text,
                $context->pointIdOf($text) instanceof PointId ? 'no #[PanelPoint] declares' : 'is not a point id',
            ));

            return null;
        }

        if ($entry->stability === PointStability::Internal) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelInternalPoint, sprintf(
                'Addon "%s" (%s) accepts the point %s, which is #[Internal]: the core\'s own wiring, which no addon contributes to. Remove it from acceptsExperimental.',
                $manifest->namespace->value,
                $manifest->package,
                $text,
            ));

            return null;
        }

        return $entry->id();
    }

    /**
     * Checks one contribution, and gives its fill, or null when it cannot be placed at a point. A
     * contribution without a manifest is the core's own.
     *
     * @param  array<string, true>  $pages  the ids of the addon's pages
     * @param  array<string, string>  $owners
     * @param  list<BuildProblem>  $problems
     * @param  list<BuildWarning>  $warnings
     */
    private function contribution(
        ?AddonManifest $manifest,
        ?PanelContributions $panel,
        PanelContribution $contribution,
        PanelContext $context,
        array $pages,
        array &$owners,
        array &$problems,
        array &$warnings,
    ): ?PanelFill {
        $addon = $manifest instanceof AddonManifest ? $manifest->namespace->value : self::CORE_NAMESPACE;
        $package = $manifest instanceof AddonManifest ? $manifest->package : self::CORE_PACKAGE;
        $contributionId = $contribution->id();
        $id = $contributionId->value;
        $named = $manifest instanceof AddonManifest
            ? sprintf('The contribution %s of addon "%s" (%s)', $id, $addon, $package)
            : sprintf('The core\'s contribution %s (%s)', $id, $package);

        if ($contributionId->namespace()->value !== $addon) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelDuplicateContribution, sprintf('%s is in the namespace "%s". %s contributions are in %s namespace: name it "%s.<local>".', $named, $contributionId->namespace()->value, $manifest instanceof AddonManifest ? 'An addon\'s' : 'The core\'s', $manifest instanceof AddonManifest ? 'its own' : 'the', $addon));

            return null;
        }

        if (isset($owners[$id])) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelDuplicateContribution, sprintf('%s is listed more than once in %s. A contribution id names one contribution in the installation: give each its own.', $named, $owners[$id]));

            return null;
        }

        $owners[$id] = $package;
        $point = $context->point($contribution->point());

        if (! $point instanceof PanelPointEntry) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelUnknownPoint, sprintf(
                '%s contributes to "%s", which %s. Point it at the id of a declared point, such as "account.me.sections@1"; cms:panel:points lists them.',
                $named,
                $contribution->point(),
                $context->pointIdOf($contribution->point()) instanceof PointId ? 'no #[PanelPoint] declares' : 'is not a point id',
            ));

            return null;
        }

        $pointId = $point->id()->toString();

        if ($point->stability === PointStability::Internal && $manifest instanceof AddonManifest) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelInternalPoint, sprintf('%s contributes to %s, which is #[Internal]: the core\'s own wiring, which no addon contributes to.', $named, $pointId));

            return null;
        }

        if ($point->declaration->kind !== $contribution->kind()) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelKindMismatch, sprintf(
                '%s is a %s contribution, and %s is a point of kind %s. Contribute to it with the class of its kind, or to a point of kind %s.',
                $named,
                $contribution->kind()->value,
                $pointId,
                $point->declaration->kind->value,
                $contribution->kind()->value,
            ));

            return null;
        }

        if ($point->stability === PointStability::Experimental && $panel instanceof PanelContributions) {
            if (! $panel->accepts($point->id())) {
                $problems[] = new BuildProblem(BuildErrorCode::PanelExperimentalNotAccepted, sprintf(
                    '%s contributes to %s, which is experimental and may change in a minor release of the panel API. Add "%s" to acceptsExperimental to opt in, or contribute to a stable point.',
                    $named,
                    $pointId,
                    $pointId,
                ));
            } else {
                $warnings[] = new BuildWarning(BuildWarning::CODE_POINT_EXPERIMENTAL, sprintf('%s contributes to %s, which is experimental and may change in a minor release of the panel API.', $named, $pointId));
            }
        }

        $deprecation = $point->declaration->deprecated;

        if ($deprecation instanceof PointDeprecation && $manifest instanceof AddonManifest) {
            $warnings[] = new BuildWarning(BuildWarning::CODE_POINT_DEPRECATED, sprintf(
                '%s contributes to %s, which is deprecated since panel API %s and is removed in %s.%s',
                $named,
                $pointId,
                $deprecation->since,
                $deprecation->removeIn,
                $deprecation->replacement === null ? ' It has no replacement.' : sprintf(' Move it to %s.', $deprecation->replacement),
            ));
        }

        $this->scope($contribution, $named, $context, $problems);
        $command = null;
        $query = null;

        if ($contribution instanceof SlotFill || $contribution instanceof PageContribution || $contribution instanceof ReplacementContribution) {
            $query = $contribution->data === null ? null : $this->dataQuery($manifest, $contribution->data, $named, $point, $context, $problems);
        }

        if ($contribution instanceof ActionContribution) {
            $command = $this->action($manifest, $contribution, $named, $point, $context, $problems);
        }

        if ($contribution instanceof NavContribution && $manifest instanceof AddonManifest && ! isset($pages[$contribution->page])) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelNavTargetUnknown, sprintf(
                '%s links to the page "%s", which the addon does not contribute. Point it at the id of a PageContribution of the addon.',
                $named,
                $contribution->page,
            ));
        }

        if ($contribution instanceof DecoratorContribution) {
            $this->decorator($manifest, $contribution, $named, $point, $context, $problems);
        }

        if ($contribution instanceof ReplacementContribution && $manifest instanceof AddonManifest) {
            $this->replacement($manifest, $contribution, $named, $point, $context, $problems);
        }

        if ($contribution instanceof FormCheck) {
            $command = $this->formCommand($contribution->command, $named, $context, $problems);

            if ($command instanceof CommandRef && $manifest instanceof AddonManifest && ($contribution->severity === Severity::Error || $contribution->mirrors !== null)) {
                $this->mirror($manifest, $contribution->mirrors, $command, $named, 'blocks the submit with an error', $context, $problems);
            }
        }

        if ($contribution instanceof FlowStep) {
            $command = $this->formCommand($contribution->command, $named, $context, $problems);

            if ($command instanceof CommandRef) {
                $this->flowPaths($manifest, $contribution, $command, $named, $context, $problems);
            }
        }

        return new PanelFill($contribution, $package, $contribution->priority(), command: $command, query: $query);
    }

    /**
     * The commands and the permission the contribution's scope names are registered.
     *
     * @param  list<BuildProblem>  $problems
     */
    private function scope(PanelContribution $contribution, string $named, PanelContext $context, array &$problems): void
    {
        $scope = $contribution->scope();

        foreach ($scope->commands as $command) {
            if (! $context->command($command) instanceof CommandEntry) {
                $problems[] = new BuildProblem(BuildErrorCode::PanelUnknownCommand, sprintf('%s is scoped to the command %s, which no scan root registers. Scope it to a registered command and version.', $named, $command->toString()));
            }
        }

        if ($scope->requires instanceof CommandName && ! $context->knowsName($scope->requires)) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelUnknownCommand, sprintf('%s requires the permission %s, which is no command or query any scan root registers. Require the name of a registered command or query.', $named, $scope->requires->value));
        }
    }

    /**
     * The form's command of a check or a step, when it is a registered command.
     *
     * @param  list<BuildProblem>  $problems
     */
    private function formCommand(string $text, string $named, PanelContext $context, array &$problems): ?CommandRef
    {
        try {
            $command = CommandRef::fromString($text);
        } catch (InvalidPanelPoint $invalid) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelUnknownCommand, sprintf('%s names the command form "%s". %s', $named, $text, $invalid->getMessage()));

            return null;
        }

        if (! $context->command($command) instanceof CommandEntry) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelUnknownCommand, sprintf('%s is for the command form %s, which no scan root registers. Name a registered command and version.', $named, $text));

            return null;
        }

        return $command;
    }

    /**
     * Checks an action's command and prefill, and gives the command it runs.
     *
     * @param  list<BuildProblem>  $problems
     */
    private function action(?AddonManifest $manifest, ActionContribution $action, string $named, PanelPointEntry $point, PanelContext $context, array &$problems): ?CommandRef
    {
        $command = $context->commandOfClass($action->command);
        $mayIssue = ! $manifest instanceof AddonManifest || $manifest->capabilities->mayIssue($action->command);

        if (! $mayIssue || ! $command instanceof CommandEntry || ! $context->exposedOnInertia($command)) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelCommandNotIssuable, sprintf(
                '%s runs %s, which %s. An action runs a registered command exposed on Inertia that the addon lists in AddonCapabilities::$issues.',
                $named,
                $action->command,
                $mayIssue ? 'is not a registered command exposed on Inertia' : 'the addon\'s capabilities do not list in issues',
            ));

            return null;
        }

        $ref = new CommandRef($command->name, $command->version);

        if ($action->prefill === []) {
            return $ref;
        }

        $props = $context->shapes->point($point->id());
        $document = $context->shapes->command($command->name, $command->version);

        foreach ($action->prefill as $property => $pointer) {
            $source = $props?->pointer($pointer);
            $target = $document?->member($property);
            $wrong = match (true) {
                ! $props instanceof SchemaNode => sprintf('the point %s has no props schema the build can read', $point->id()->toString()),
                ! $source instanceof SchemaNode => sprintf('the props of %s have nothing at %s', $point->id()->toString(), $pointer),
                ! $document instanceof SchemaNode => sprintf('the command %s has no schema the build can read', $ref->toString()),
                ! $target instanceof SchemaNode || ! array_key_exists($property, $document->properties) => sprintf('the command %s has no property %s', $ref->toString(), $property),
                ! $source->fitsInto($target) => sprintf('%s holds %s, and %s takes %s', $pointer, $source->describe(), $property, $target->describe()),
                default => null,
            };

            if ($wrong !== null) {
                $problems[] = new BuildProblem(BuildErrorCode::PanelActionPrefillInvalid, sprintf('%s prefills %s from %s, and %s. Prefill a property of the command from a pointer into the point\'s props that holds a value it takes.', $named, $property, $pointer, $wrong));
            }
        }

        return $ref;
    }

    /**
     * Checks a data query, and gives it when it is a #[Query] of the addon.
     *
     * @param  list<BuildProblem>  $problems
     */
    private function dataQuery(?AddonManifest $manifest, string $class, string $named, PanelPointEntry $point, PanelContext $context, array &$problems): ?CommandRef
    {
        $query = $context->queryOfClass($class);

        if (! $query instanceof QueryEntry || $query->package !== ($manifest instanceof AddonManifest ? $manifest->package : self::CORE_PACKAGE)) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelDataQueryInvalid, sprintf(
                '%s reads its data with %s, which is %s. A contribution reads only through a #[Query] of its own addon, run as the viewer.',
                $named,
                $class,
                $query instanceof QueryEntry ? sprintf('a query of %s', $query->package) : 'not a query any scan root registers',
            ));

            return null;
        }

        $ref = new CommandRef($query->name, $query->version);
        $input = $context->shapes->query($query->name, $query->version);

        if (! $input instanceof SchemaNode) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelDataQueryInvalid, sprintf('%s reads its data with %s, which has no codec, so the panel cannot run it. Generate its codec with its JSON Schemas.', $named, $ref->toString()));

            return $ref;
        }

        $props = $context->shapes->point($point->id()) ?? new SchemaNode([JsonKind::Object], closed: true);

        foreach ($input->required as $name) {
            $prop = $props->properties[$name] ?? null;
            $wanted = $input->properties[$name] ?? SchemaNode::any();

            if (! $prop instanceof SchemaNode || ! $prop->fitsInto($wanted)) {
                $problems[] = new BuildProblem(BuildErrorCode::PanelDataQueryInvalid, sprintf(
                    '%s reads its data with %s, whose input %s cannot be taken from the props of %s: %s. The panel takes a query\'s input from the point\'s props by name.',
                    $named,
                    $ref->toString(),
                    $name,
                    $point->id()->toString(),
                    $prop instanceof SchemaNode ? sprintf('the props hold %s, and the query takes %s', $prop->describe(), $wanted->describe()) : 'the props have no member of that name',
                ));
            }
        }

        return $ref;
    }

    /**
     * Checks what a decorator tightens, and the mirror of a disabled reason.
     *
     * @param  list<BuildProblem>  $problems
     */
    private function decorator(?AddonManifest $manifest, DecoratorContribution $decorator, string $named, PanelPointEntry $point, PanelContext $context, array &$problems): void
    {
        foreach ($decorator->tightens as $tighten) {
            if (! in_array($tighten, $point->declaration->tightens, true)) {
                $problems[] = new BuildProblem(BuildErrorCode::PanelTighteningUndeclared, sprintf(
                    '%s tightens %s, which %s does not let a decorator tighten (it lets %s). Tighten only what the point declares.',
                    $named,
                    $tighten->value,
                    $point->id()->toString(),
                    $point->declaration->tightens === [] ? 'nothing' : implode(', ', array_map(static fn (Tighten $declared): string => $declared->value, $point->declaration->tightens)),
                ));
            }
        }

        if (! $manifest instanceof AddonManifest || (! in_array(Tighten::DisabledReason, $decorator->tightens, true) && $decorator->mirrors === null)) {
            return;
        }

        $commands = $decorator->scope->commands;

        if (count($commands) !== 1) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelCheckUnmirrored, sprintf(
                '%s blocks the submit with a disabled reason, and its scope names %d commands. A block holds only where a hook of the addon enforces it on the server, so scope the decorator to the one command its mirrored hook runs for.',
                $named,
                count($commands),
            ));

            return;
        }

        $this->mirror($manifest, $decorator->mirrors, $commands[0], $named, 'blocks the submit with a disabled reason', $context, $problems);
    }

    /**
     * The mirror rule (PRD 13.4): what blocks a client submit is enforced on the server by a
     * ValidateHook or AuthorizeHook of the same addon on the same command, so the rule holds over
     * every surface.
     *
     * @param  list<BuildProblem>  $problems
     */
    private function mirror(AddonManifest $manifest, ?string $hookClass, CommandRef $command, string $named, string $blocks, PanelContext $context, array &$problems): void
    {
        $hook = $hookClass === null ? null : $context->hookOfClass($hookClass);
        $hookCommand = $hook instanceof DiscoveredHook ? $context->commandOfClass($hook->commandClass) : null;

        $wrong = match (true) {
            $hookClass === null => 'it mirrors no hook',
            ! $hook instanceof DiscoveredHook => sprintf('it mirrors %s, which is no #[Hook] any scan root registers', $hookClass),
            $hook->package !== $manifest->package => sprintf('it mirrors %s, a hook of %s, not of the addon', $hook->class, $hook->package),
            $hook->phase === Phase::Transform => sprintf('it mirrors %s, a transform hook, which neither validates nor authorizes', $hook->class),
            ! $hookCommand instanceof CommandEntry || ! $hookCommand->name->equals($command->name) || $hookCommand->version !== $command->version => sprintf(
                'it mirrors %s, which runs for %s, not %s',
                $hook->class,
                $hookCommand instanceof CommandEntry ? $hookCommand->name->value.'@'.$hookCommand->version : $hook->commandClass,
                $command->toString(),
            ),
            default => null,
        };

        if ($wrong !== null) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelCheckUnmirrored, sprintf(
                '%s %s on %s, and %s. Name in mirrors the ValidateHook or AuthorizeHook of the addon that enforces the same rule on %s, so it holds over REST, MCP and the CLI too; or lower the severity to a warning.',
                $named,
                $blocks,
                $command->toString(),
                $wrong,
                $command->toString(),
            ));
        }
    }

    /**
     * Each path a flow step patches is in its command's schema and is the addon's to change.
     *
     * @param  list<BuildProblem>  $problems
     */
    private function flowPaths(?AddonManifest $manifest, FlowStep $step, CommandRef $command, string $named, PanelContext $context, array &$problems): void
    {
        $schema = $context->shapes->command($command->name, $command->version);
        $entry = $context->command($command);
        $ownsCommand = ! $manifest instanceof AddonManifest || ($entry instanceof CommandEntry && $entry->package === $manifest->package);
        $namespace = $manifest instanceof AddonManifest ? $manifest->namespace->value : self::CORE_NAMESPACE;

        foreach ($step->patches as $patch) {
            $path = FieldPath::fromString($patch);
            $wrong = match (true) {
                ! $schema instanceof SchemaNode => sprintf('the command %s has no schema the build can read', $command->toString()),
                ! $schema->at($path) instanceof SchemaNode => sprintf('the schema of %s has no %s', $command->toString(), $patch),
                ! $ownsCommand && ! $this->ownedPath($path, $namespace) => sprintf('%s is not below ext.%s, and %s is not a command of the addon', $patch, $namespace, $command->toString()),
                default => null,
            };

            if ($wrong !== null) {
                $problems[] = new BuildProblem(BuildErrorCode::PanelFlowPathUnknown, sprintf(
                    '%s patches %s, and %s. A step patches only paths of its command\'s schema below ext.%s, or any path of a command its addon declares; the server\'s transform hooks change everything else.',
                    $named,
                    $patch,
                    $wrong,
                    $namespace,
                ));
            }
        }
    }

    /**
     * Whether the path runs through `ext.<namespace>` to at least one segment below it.
     */
    private function ownedPath(FieldPath $path, string $namespace): bool
    {
        $segments = $path->segments;
        $counter = count($segments);

        for ($index = 0; $index + 2 < $counter; $index++) {
            if ($segments[$index] === 'ext' && $segments[$index + 1] === $namespace) {
                return true;
            }
        }

        return false;
    }

    /**
     * A replacement's key is of the kind its point is keyed by, and, at a point with
     * Ownership::Own, the addon's.
     *
     * @param  list<BuildProblem>  $problems
     */
    private function replacement(AddonManifest $manifest, ReplacementContribution $replacement, string $named, PanelPointEntry $point, PanelContext $context, array &$problems): void
    {
        if ($point->declaration->ownership !== Ownership::Own) {
            return;
        }

        $key = $replacement->key;
        $owned = match ($point->declaration->keyedBy) {
            ReplacementKey::FieldType => array_any($manifest->schema->fieldTypes, static fn (ContributedFieldType $type): bool => $type->value === $key),
            ReplacementKey::Command => $this->ownsCommand($manifest, $key, $context),
            ReplacementKey::ValueClass => $context->discovery->packageOf($key) === $manifest->package,
            null => false,
        };

        if (! $owned) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelUnownedTarget, sprintf(
                '%s replaces "%s" at %s, which only replaces what an addon owns, and "%s" is not %s of the addon. Replace only the addon\'s own %s.',
                $named,
                $key,
                $point->id()->toString(),
                $key,
                match ($point->declaration->keyedBy) {
                    ReplacementKey::FieldType => 'a field type its manifest contributes',
                    ReplacementKey::Command => 'a command and version a scan root of its package declares',
                    default => 'a class a scan root of its package declares',
                },
                match ($point->declaration->keyedBy) {
                    ReplacementKey::FieldType => 'field types, "<namespace>:<handle>"',
                    ReplacementKey::Command => 'commands, "<name>@<version>"',
                    default => 'classes',
                },
            ));
        }
    }

    private function ownsCommand(AddonManifest $manifest, string $key, PanelContext $context): bool
    {
        try {
            $command = $context->command(CommandRef::fromString($key));
        } catch (InvalidPanelPoint) {
            return false;
        }

        return $command instanceof CommandEntry && $command->package === $manifest->package;
    }

    /**
     * Checks an addon's bundle against its manifest and contributions, and gives what the registry
     * keeps of it.
     *
     * @param  list<string>  $code  the ids of the contributions that run code, sorted
     * @param  list<BuildProblem>  $problems
     */
    private function bundle(AddonManifest $manifest, PanelContributions $panel, ?AddonBundle $bundle, array $code, BuildSettings $settings, array &$problems): ?CompiledBundle
    {
        $named = sprintf('The panel bundle of addon "%s" (%s)', $manifest->namespace->value, $manifest->package);

        if ($panel->bundle === null) {
            if ($code !== []) {
                $problems[] = new BuildProblem(BuildErrorCode::PanelBundleInvalid, sprintf(
                    'Addon "%s" (%s) contributes %s, which run code, and names no bundle. Build the addon\'s UI and name its directory, which holds panel-manifest.json, in PanelContributions::$bundle.',
                    $manifest->namespace->value,
                    $manifest->package,
                    implode(', ', $code),
                ));
            }

            return null;
        }

        if (! $bundle instanceof AddonBundle) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelBundleInvalid, sprintf('%s in %s was not read.', $named, $panel->bundle));

            return null;
        }

        foreach ($bundle->problems as $problem) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelBundleInvalid, sprintf('%s in %s: %s', $named, $panel->bundle, $problem));
        }

        $read = $bundle->manifest;

        if (! $read instanceof BundleManifest) {
            return null;
        }

        $wrong = [];
        $scripts = array_values(array_filter($read->files, static fn (BundleFile $file): bool => $file->kind === BundleFileKind::Script));

        if (! array_any($scripts, static fn (BundleFile $file): bool => $file->path->equals($read->entry))) {
            $wrong[] = sprintf('its entry %s is not a script the manifest lists', $read->entry->value);
        }

        foreach ($read->externals as $external) {
            if (! SharedExternals::allows($external)) {
                $wrong[] = sprintf('it imports "%s", which is not a module the panel shares (%s and %s with its subpaths)', $external, implode(', ', SharedExternals::REACT), SharedExternals::SDK);
            }
        }

        $listed = array_map(static fn (ContributionId $id): string => $id->value, $read->contributions);
        sort($listed, SORT_STRING);

        if ($listed !== $code) {
            $missing = array_values(array_diff($code, $listed));
            $extra = array_values(array_diff($listed, $code));
            $wrong[] = sprintf(
                'it registers code for %s, and the manifest\'s contributions that run code are %s%s%s',
                $listed === [] ? 'no contribution' : implode(', ', $listed),
                $code === [] ? 'none' : implode(', ', $code),
                $missing === [] ? '' : sprintf('; missing: %s', implode(', ', $missing)),
                $extra === [] ? '' : sprintf('; not in the manifest: %s', implode(', ', $extra)),
            );
        }

        foreach ($wrong as $message) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelBundleInvalid, sprintf('%s in %s: %s. Build the bundle again from the addon\'s manifest.', $named, $panel->bundle, $message));
        }

        $refusal = $settings->signatures instanceof SignaturePolicy && $bundle->signing instanceof BundleSigning
            ? BundleSignatures::refusal($manifest->package, $bundle->signing, $settings->signatures)
            : null;

        if ($refusal !== null) {
            $problems[] = new BuildProblem(BuildErrorCode::PanelBundleUnsigned, sprintf('%s in %s: %s', $named, $panel->bundle, $refusal));
        }

        return new CompiledBundle($read->entry, $read->files);
    }

    /**
     * One replacement wins each key of a replaceable point: the only one, or the one
     * cbox-cms.panel.replacements names; the others stay listed, disabled by the installation.
     *
     * @param  array<string, list<PanelFill>>  $fills
     * @param  list<BuildProblem>  $problems
     * @return array<string, list<PanelFill>>
     */
    private function chooseReplacements(array $fills, PanelContext $context, BuildSettings $settings, array &$problems): array
    {
        $choices = [];

        foreach ($settings->replacements as $choice) {
            $point = $context->point($choice->point->toString());
            $candidates = array_values(array_filter($fills[$choice->point->toString()] ?? [], static fn (PanelFill $fill): bool => $fill->key() === $choice->key));
            $wrong = match (true) {
                ! $point instanceof PanelPointEntry => 'no #[PanelPoint] declares the point',
                $point->declaration->kind !== PointKind::Replacement => sprintf('the point is of kind %s, not replacement', $point->declaration->kind->value),
                ! array_any($candidates, static fn (PanelFill $fill): bool => $fill->contribution->equals($choice->winner)) => sprintf('%s is not a replacement of that key', $choice->winner->value),
                isset($choices[$choice->point->toString()][$choice->key]) => 'the key is chosen twice',
                default => null,
            };

            if ($wrong !== null) {
                $problems[] = new BuildProblem(BuildErrorCode::PanelOverrideInvalid, sprintf(
                    'cbox-cms.panel.replacements chooses %s for "%s" at %s, and %s. Choose one of the replacements cms:panel:fills lists for the key.',
                    $choice->winner->value,
                    $choice->key,
                    $choice->point->toString(),
                    $wrong,
                ));

                continue;
            }

            $choices[$choice->point->toString()][$choice->key] = $choice->winner;
        }

        foreach ($fills as $pointId => $pointFills) {
            $byKey = [];

            foreach ($pointFills as $index => $fill) {
                $key = $fill->key();

                if ($key !== null) {
                    $byKey[$key][] = $index;
                }
            }

            foreach ($byKey as $key => $indexes) {
                $key = (string) $key;
                $winner = $choices[$pointId][$key] ?? null;

                if ($winner === null) {
                    if (count($indexes) > 1) {
                        $claims = array_map(static fn (int $index): string => sprintf('%s (%s)', $pointFills[$index]->contribution->value, $pointFills[$index]->package), $indexes);
                        sort($claims, SORT_STRING);

                        $problems[] = new BuildProblem(BuildErrorCode::PanelReplacementConflict, sprintf(
                            'The replacements %s all replace "%s" at %s, and one replacement wins a key. Name the winner in cbox-cms.panel.replacements, [\'%s\' => [\'%s\' => \'<contribution id>\']], or remove all but one.',
                            implode(' and ', $claims),
                            $key,
                            $pointId,
                            $pointId,
                            $key,
                        ));
                    }

                    continue;
                }

                foreach ($indexes as $index) {
                    $fill = $pointFills[$index];
                    $fills[$pointId][$index] = new PanelFill($fill->declaration, $fill->package, $fill->priority, $fill->ordering, $fill->contribution->equals($winner), FillSource::Installation, $fill->command, $fill->query);
                }
            }
        }

        return $fills;
    }

    /**
     * Applies cbox-cms.panel.contributions: another priority or enabled state per contribution.
     *
     * @param  array<string, list<PanelFill>>  $fills
     * @param  list<BuildProblem>  $problems
     * @return array<string, list<PanelFill>>
     */
    private function override(array $fills, PanelContext $context, BuildSettings $settings, array &$problems): array
    {
        $seen = [];

        foreach ($settings->overrides as $override) {
            $pointId = $override->point->toString();
            $key = $pointId.' '.$override->contribution->value;
            $index = null;

            foreach ($fills[$pointId] ?? [] as $position => $fill) {
                if ($fill->contribution->equals($override->contribution)) {
                    $index = $position;
                }
            }

            $wrong = match (true) {
                ! $context->point($pointId) instanceof PanelPointEntry => 'no #[PanelPoint] declares the point',
                $index === null => sprintf('%s is no contribution to it', $override->contribution->value),
                isset($seen[$key]) => 'it is set twice',
                $override->priority !== null && ($override->priority < 0 || $override->priority > PanelContribution::MAX_PRIORITY) => sprintf('the priority %d is not from 0 to %d', $override->priority, PanelContribution::MAX_PRIORITY),
                $override->priority === null && $override->enabled === null => 'it sets neither priority nor enabled',
                default => null,
            };

            if ($wrong !== null) {
                $problems[] = new BuildProblem(BuildErrorCode::PanelOverrideInvalid, sprintf(
                    'cbox-cms.panel.contributions sets %s at %s, and %s. Set priority or enabled for a contribution cms:panel:fills lists at the point.',
                    $override->contribution->value,
                    $pointId,
                    $wrong,
                ));

                continue;
            }

            $seen[$key] = true;
            $pointFills = $fills[$pointId];
            $pointFills[$index] = $pointFills[$index]->overridden($override->priority, $override->enabled);
            $fills[$pointId] = array_values($pointFills);
        }

        return $fills;
    }
}
