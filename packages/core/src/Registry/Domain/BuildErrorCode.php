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

    /** Two different scan roots contain the same class. */
    case ClassInTwoRoots = 'registry_class_in_two_roots';

    /** Two classes declare the same command name and version. */
    case DuplicateCommand = 'registry_duplicate_command';

    /** A hook runs for a command class that no scan root registers. */
    case UnknownHookCommand = 'registry_unknown_hook_command';
}
