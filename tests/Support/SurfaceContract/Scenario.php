<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

/**
 * What a surface contract test sends through a surface (GUARDRAILS 2.1: "Kontrakttests dækker
 * feltfejl, versionskonflikt, dry-run og en kvittering der er committet men ikke nået sit
 * vent-niveau"). A field error comes in two forms: a document the command's generated codec
 * refuses at the path of a value, and a document the codec reads whose fields the kernel's
 * validation refuses at their paths below `fields`.
 */
enum Scenario: string
{
    /** The command's document without its first required property: json_invalid at its path. */
    case DocumentFieldError = 'document_field_error';

    /** A revision whose required field is missing: validation_failed with the field's path. */
    case FieldError = 'field_error';

    /** The commit finds an aggregate at another version than was read: version_conflict. */
    case VersionConflict = 'version_conflict';

    /** A dry run: the receipt dry_run, and nothing committed. */
    case DryRun = 'dry_run';

    /** Committed, but the wait level origin was not reached in time: committed_wait_timeout. */
    case WaitTimeout = 'wait_timeout';

    /**
     * Whether the kernel hands the command to the committer in this scenario.
     */
    public function commits(): bool
    {
        return $this === self::VersionConflict || $this === self::WaitTimeout;
    }
}
