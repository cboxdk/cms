<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Why cms:build refused to write the registry. Each case is an error code (GUARDRAILS 7.2).
 */
#[Experimental]
enum BuildErrorCode: string
{
    /** A declared scan root is not a readable directory. */
    case InvalidScanRoot = 'registry_invalid_scan_root';

    /** A class in a scan root cannot be autoloaded, or loading it failed. */
    case ClassNotLoadable = 'registry_class_not_loadable';

    /** An attribute's arguments are invalid, so it cannot be built. */
    case InvalidAttribute = 'registry_invalid_attribute';

    /** An attribute sits on an interface, trait, enum or abstract class. */
    case NotAConcreteClass = 'registry_not_a_concrete_class';

    /** A #[Hook] sits on a class that does not implement the interface of its phase. */
    case NotAHook = 'registry_not_a_hook';

    /** A #[Command], #[Query], #[Action], #[Subscription] or #[PanelPoint] sits on a class that is not a final readonly class (GUARDRAILS 2.1). */
    case NotFinalReadonly = 'registry_not_final_readonly';

    /** Two different scan roots contain the same class. */
    case ClassInTwoRoots = 'registry_class_in_two_roots';

    /** Two classes declare the same command or query name and version. */
    case DuplicateCommand = 'registry_duplicate_command';

    /** A hook runs for a command class that no scan root registers. */
    case UnknownHookCommand = 'registry_unknown_hook_command';

    /** An #[Action] sits on a class that implements neither WriteAction nor QueryAction, or both. */
    case NotAnAction = 'registry_not_an_action';

    /**
     * An action handles a class that is not a registered command (a write action) or query (a query
     * action): the class does not exist, lacks #[Command] or #[Query], or no scan root registers it.
     */
    case UnknownActionCommand = 'registry_unknown_action_command';

    /** Two actions handle the same command or query. */
    case DuplicateAction = 'registry_duplicate_action';

    /** An #[Action] lists a surface that is not a case of Surface. */
    case UnknownSurface = 'registry_unknown_surface';

    /** An action is exposed on REST, but no codec reads its command or query, so its route cannot be described or served. */
    case SurfaceWithoutCodec = 'registry_surface_without_codec';

    /** A #[Subscription] sits on a class that does not implement Subscriber. */
    case NotASubscriber = 'registry_not_a_subscriber';

    /** A #[Subscription] lists an event class that does not exist or does not implement Event, or whose type() fails. */
    case UnknownEvent = 'registry_unknown_event';

    /** A #[Subscription] names a lane that is not a case of Lane. */
    case UnknownLane = 'registry_unknown_lane';

    /** Two subscribers declare the same subscription name. */
    case DuplicateSubscription = 'registry_duplicate_subscription';

    /** An addon manifest cannot be built, or its documentation or schema directory is not a readable directory, or two manifests name one package. */
    case InvalidManifest = 'registry_invalid_manifest';

    /** An addon manifest names the reserved namespace app or ext (PRD 11.12). */
    case ReservedNamespace = 'registry_reserved_namespace';

    /** Two addon manifests name the same namespace (PRD 13.1, 13.3). */
    case DuplicateNamespace = 'registry_duplicate_namespace';

    /** An addon manifest needs a version of the kernel's API that this kernel does not satisfy. */
    case IncompatibleCoreApi = 'registry_incompatible_core_api';

    /** A #[Hook] of an addon's package runs for a command and phase its manifest does not allow. */
    case UndeclaredHook = 'registry_undeclared_hook';

    /** A #[Subscription] of an addon's package receives an event on a lane its manifest does not allow. */
    case UndeclaredSubscriber = 'registry_undeclared_subscriber';

    /** Two classes declare the same panel point name and version. */
    case DuplicatePanelPoint = 'registry_duplicate_panel_point';

    /** A #[PanelPoint] class carries none, or more than one, of #[Stable], #[Experimental] and #[Internal] (GUARDRAILS 2.3). */
    case PanelPointWithoutStability = 'registry_panel_point_without_stability';

    /** A panel point has more than one version, and an older version's props class does not implement DowncastsFromNewest. */
    case PanelPointWithoutDowncast = 'registry_panel_point_without_downcast';

    /** An addon manifest's package is not on the installation's allowlist of addons, cbox-cms.addons.allowed (PRD 13.8). */
    case AddonNotAllowed = 'registry_addon_not_allowed';

    /** An addon's panel contributions need a version of the panel's API that this panel does not satisfy (PRD 13.4). */
    case IncompatiblePanelApi = 'registry_incompatible_panel_api';

    /** A panel contribution names a point id that no #[PanelPoint] declares, or that is not a point id. */
    case PanelUnknownPoint = 'registry_panel_unknown_point';

    /** A panel contribution, or an addon's acceptsExperimental, names an #[Internal] point, the core's own wiring. */
    case PanelInternalPoint = 'registry_panel_internal_point';

    /** A panel contribution is of another kind than the point it contributes to. */
    case PanelKindMismatch = 'registry_panel_kind_mismatch';

    /** A panel contribution goes to an experimental point the addon's acceptsExperimental does not list. */
    case PanelExperimentalNotAccepted = 'registry_panel_experimental_not_accepted';

    /** A panel contribution's id is not `<namespace>.<local>` in the addon's namespace, or another contribution, or page path, has it too. */
    case PanelDuplicateContribution = 'registry_panel_duplicate_contribution';

    /** A replacement replaces a key the addon does not own at a point with Ownership::Own. */
    case PanelUnownedTarget = 'registry_panel_unowned_target';

    /** Two replacements claim one key of a point, and cbox-cms.panel.replacements names no winner. */
    case PanelReplacementConflict = 'registry_panel_replacement_conflict';

    /** A blocking form check, or a decorator that tightens the disabled reason, mirrors no hook of its addon on its command. */
    case PanelCheckUnmirrored = 'registry_panel_check_unmirrored';

    /** A flow step patches a path its command's schema does not have, or one the addon does not own. */
    case PanelFlowPathUnknown = 'registry_panel_flow_path_unknown';

    /** An action's command is not in the addon's issues, or a command in issues is not a registered command exposed on Inertia. */
    case PanelCommandNotIssuable = 'registry_panel_command_not_issuable';

    /** An action's prefill names a pointer the point's props do not have, a property the command does not have, or types that do not fit. */
    case PanelActionPrefillInvalid = 'registry_panel_action_prefill_invalid';

    /** A contribution's data query is not a #[Query] of the addon with a codec, or its input cannot be taken from the point's props. */
    case PanelDataQueryInvalid = 'registry_panel_data_query_invalid';

    /** An addon's panel bundle is missing, unreadable or does not match its manifest: a file is missing or has another hash, a stylesheet is not in the addon's layer, an import is not a shared module, or the contributions differ. */
    case PanelBundleInvalid = 'registry_panel_bundle_invalid';

    /** A form check, a flow step or a contribution's scope names a command or query no scan root registers. */
    case PanelUnknownCommand = 'registry_panel_unknown_command';

    /** cbox-cms.panel.contributions or cbox-cms.panel.replacements is malformed or names a point, contribution or key that does not exist. */
    case PanelOverrideInvalid = 'registry_panel_override_invalid';

    /** A decorator tightens a prop its point does not let decorators tighten. */
    case PanelTighteningUndeclared = 'registry_panel_tightening_undeclared';

    /** A nav entry links to a page its addon does not contribute. */
    case PanelNavTargetUnknown = 'registry_panel_nav_target_unknown';

    /** A panel theme cannot be used: an addon ships one without the capability uiTheme, cbox-cms.panel.themes names a theme twice or one that is not there, or a theme file is not of theme.v1.json's form, or the composed theme makes a pointer target smaller than 24 pixels. */
    case PanelThemeInvalid = 'registry_panel_theme_invalid';

    /** The selected panel themes, composed, draw a contrast pair of the token catalogue below WCAG 2.2 AA in a mode. */
    case PanelThemeContrast = 'registry_panel_theme_contrast';
}
