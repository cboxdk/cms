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

    /** A #[Command], #[Query] or #[Action] sits on a class that is not a final readonly class (GUARDRAILS 2.1). */
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
}
