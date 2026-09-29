<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Errors;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The error catalog (PRD 6.1, GUARDRAILS 2.1, 7.2): every error code of the kernel, each a stable
 * name that is never renamed, and entry() with what the code means on each surface.
 *
 * A code is lowercase words joined by underscores, PATTERN. A case is named after its code in
 * PascalCase: idempotency_conflict is IdempotencyConflict. The error reference in
 * ErrorEntry::DOCS_PAGE is generated from these entries by composer docs:errors, and gate 10 fails
 * when it differs. A code that a class declares as a CODE constant or an error-code enum case must
 * have a case here, and every case must be used by the kernel's code; the Arch suite checks both.
 *
 * The CLI exit codes of the doctor's codes are what cms:doctor exits with when that failure decides
 * the run: 78 for a blocking violation, 75 for a blocking dependency that cannot be reached, 79 for
 * a check that only decides readiness (DoctorExitCode).
 */
#[Experimental]
enum ErrorCode: string
{
    /** Lowercase words and digits joined by underscores, starting with a letter. */
    public const string PATTERN = '/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

    case ActorNotActive = 'actor_not_active';
    case CredentialExpired = 'credential_expired';
    case CredentialMalformed = 'credential_malformed';
    case CredentialRevoked = 'credential_revoked';
    case CredentialUnknown = 'credential_unknown';
    case DoctorAppRoleBypassrls = 'doctor_app_role_bypassrls';
    case DoctorAppRoleCreaterole = 'doctor_app_role_createrole';
    case DoctorAppRoleHasDdl = 'doctor_app_role_has_ddl';
    case DoctorAppRolePrivilegedMembership = 'doctor_app_role_privileged_membership';
    case DoctorAppRoleSuperuser = 'doctor_app_role_superuser';
    case DoctorCheckCrashed = 'doctor_check_crashed';
    case DoctorChromiumMissing = 'doctor_chromium_missing';
    case DoctorConfigInvalid = 'doctor_config_invalid';
    case DoctorLaravelVersion = 'doctor_laravel_version';
    case DoctorLcMessagesNotEnglish = 'doctor_lc_messages_not_english';
    case DoctorNodeMissing = 'doctor_node_missing';
    case DoctorNodeVersion = 'doctor_node_version';
    case DoctorOwnerCredentialsExposed = 'doctor_owner_credentials_exposed';
    case DoctorPartitionRunwayShort = 'doctor_partition_runway_short';
    case DoctorPartitionTableUnmanageable = 'doctor_partition_table_unmanageable';
    case DoctorPhpAllowUrlFopen = 'doctor_php_allow_url_fopen';
    case DoctorPhpVersion = 'doctor_php_version';
    case DoctorPlaywrightMissing = 'doctor_playwright_missing';
    case DoctorPostgresQueryFailed = 'doctor_postgres_query_failed';
    case DoctorPostgresRefused = 'doctor_postgres_refused';
    case DoctorPostgresUnavailable = 'doctor_postgres_unavailable';
    case DoctorPostgresVersion = 'doctor_postgres_version';
    case DoctorPreparedTransactionsEnabled = 'doctor_prepared_transactions_enabled';
    case DoctorRegistryCacheDamaged = 'doctor_registry_cache_damaged';
    case DoctorRegistryCacheMissing = 'doctor_registry_cache_missing';
    case DoctorRegistryCacheStale = 'doctor_registry_cache_stale';
    case DoctorRowSecurityNotForced = 'doctor_row_security_not_forced';
    case DoctorTransactionTimeoutMissing = 'doctor_transaction_timeout_missing';
    case DoctorValkeyRefused = 'doctor_valkey_refused';
    case DoctorValkeyUnavailable = 'doctor_valkey_unavailable';
    case DoctorVendorManifestMissing = 'doctor_vendor_manifest_missing';
    case DryRun = 'dry_run';
    case FakeCheckFailed = 'fake_check_failed';
    case GenerateColumnNameTooLong = 'generate_column_name_too_long';
    case GenerateDuplicateFieldHandle = 'generate_duplicate_field_handle';
    case GenerateDuplicateSelectValue = 'generate_duplicate_select_value';
    case GenerateDuplicateTypeHandle = 'generate_duplicate_type_handle';
    case GenerateDuplicateTypeId = 'generate_duplicate_type_id';
    case GenerateExtensionOfOwnType = 'generate_extension_of_own_type';
    case GenerateExtensionVersionMismatch = 'generate_extension_version_mismatch';
    case GenerateInvalidCaseName = 'generate_invalid_case_name';
    case GenerateInvalidConfig = 'generate_invalid_config';
    case GenerateInvalidOutput = 'generate_invalid_output';
    case GenerateMinAboveMax = 'generate_min_above_max';
    case GenerateMinItemsAboveMaxItems = 'generate_min_items_above_max_items';
    case GenerateMinLengthAboveMaxLength = 'generate_min_length_above_max_length';
    case GenerateOutputUnwritable = 'generate_output_unwritable';
    case GenerateScaleAbovePrecision = 'generate_scale_above_precision';
    case GenerateSchemaInvalid = 'generate_schema_invalid';
    case GenerateSchemaMissing = 'generate_schema_missing';
    case GenerateSchemaUnsupportedVersion = 'generate_schema_unsupported_version';
    case GenerateSchemaUnwritable = 'generate_schema_unwritable';
    case GenerateTooManyFields = 'generate_too_many_fields';
    case GenerateUnknownExtendsTarget = 'generate_unknown_extends_target';
    case GenerateUnknownFieldType = 'generate_unknown_field_type';
    case IdempotencyConflict = 'idempotency_conflict';
    case IdempotencyInFlight = 'idempotency_in_flight';
    case OwnerCredentialsExposed = 'owner_credentials_exposed';
    case PartitionLockTimeout = 'partition_lock_timeout';
    case PartitionMissing = 'partition_missing';
    case PartitionOwnerRequired = 'partition_owner_required';
    case PartitionTableUnmanageable = 'partition_table_unmanageable';
    case RegistryCacheMalformed = 'registry_cache_malformed';
    case RegistryCacheMissing = 'registry_cache_missing';
    case RegistryCacheUnwritable = 'registry_cache_unwritable';
    case RegistryClassInTwoRoots = 'registry_class_in_two_roots';
    case RegistryClassNotLoadable = 'registry_class_not_loadable';
    case RegistryDuplicateCommand = 'registry_duplicate_command';
    case RegistryInvalidAttribute = 'registry_invalid_attribute';
    case RegistryInvalidScanRoot = 'registry_invalid_scan_root';
    case RegistryNotAConcreteClass = 'registry_not_a_concrete_class';
    case RegistryNotFinalReadonly = 'registry_not_final_readonly';
    case RegistryUnknownHookCommand = 'registry_unknown_hook_command';
    case Unauthorized = 'unauthorized';
    case ValidationFailed = 'validation_failed';
    case VersionConflict = 'version_conflict';

    /**
     * What the code means on each surface, whether a retry makes sense, and the explanation.
     */
    public function entry(): ErrorEntry
    {
        return match ($this) {
            self::ActorNotActive => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'The actor, or an actor it acts on behalf of, is not active (PRD 5.16, invariant 37): its credentials are refused, it runs no command and reads nothing, and nothing was committed. Reactivate the actor, or call as an actor that is active.',
            ),
            self::CredentialExpired => $this->credential(
                'The credential\'s expiry has passed, so it was refused and nothing was read or committed (PRD 5.16). Every credential has an expiry. Call again with a credential that is still valid.',
            ),
            self::CredentialMalformed => $this->credential(
                'The credential is not in the form of a credential, or its checksum does not match, so it was refused without a lookup (PRD 5.16). Check that the whole token was sent, without spaces or a missing part.',
            ),
            self::CredentialRevoked => $this->credential(
                'The credential was revoked: its actor\'s credential generation was counted up after it was issued, by a deactivation or a revocation of everything the actor held (PRD 5.16). Nothing was read or committed. Call again with a credential issued after that.',
            ),
            self::CredentialUnknown => $this->credential(
                'No credential has this token, so it was refused and nothing was read or committed (PRD 5.16). Call again with a credential that was issued by this installation.',
            ),
            self::DoctorAppRoleBypassrls => $this->violation(
                'The app role, which the web and queue processes log in as, has BYPASSRLS, so the row level security that separates the actors does not hold for it (PRD 4.2). As a superuser, run ALTER ROLE <app role> NOBYPASSRLS, then run cms:doctor again.',
            ),
            self::DoctorAppRoleCreaterole => $this->violation(
                'The app role has CREATEROLE, so it could create a role with more rights than it has itself (PRD 4.2). As a superuser, run ALTER ROLE <app role> NOCREATEROLE, then run cms:doctor again.',
            ),
            self::DoctorAppRoleHasDdl => $this->violation(
                'The app role can change the schema: it, or a role it is a member of, owns relations, or it has CREATE on the database or a schema (PRD 4.2). Only the owner role runs DDL. Give the relations to the owner role, revoke the membership or the CREATE grant as the cause says, then run cms:doctor again.',
            ),
            self::DoctorAppRolePrivilegedMembership => $this->violation(
                'The app role is a member of a role with rights it must not have: a superuser, a role with BYPASSRLS or CREATEROLE, a role that owns relations, the database or a schema, or a predefined role such as pg_read_all_data or pg_signal_backend (PRD 4.2). Revoke the membership with REVOKE <role> FROM <app role>, then run cms:doctor again.',
            ),
            self::DoctorAppRoleSuperuser => $this->violation(
                'The app role is a superuser, so no privilege and no row level security limits it (PRD 4.2). As a superuser, run ALTER ROLE <app role> NOSUPERUSER, then run cms:doctor again.',
            ),
            self::DoctorCheckCrashed => $this->violation(
                'A check of cms:doctor did not finish: it threw, answered for another check or returned a skip, so the doctor cannot say whether that part is in order. This is a bug in the check. Report it with the cause, and run cms:doctor again after updating.',
            ),
            self::DoctorChromiumMissing => $this->readiness(
                'The browser tests need the Chromium build that the installed Playwright was made for, and it is not installed. Run npx playwright install chromium in the project, and again after the version of Playwright in package.json changes.',
            ),
            self::DoctorConfigInvalid => $this->violation(
                'A setting under cbox-cms.doctor, or the environment variable CBOX_CMS_MAINTENANCE_PROCESS, is invalid, or a check it names cannot be used, so cms:doctor cannot run its checks. Correct the setting the cause names, then run cms:doctor again.',
            ),
            self::DoctorLaravelVersion => $this->violation(
                'The installed Laravel is not the major version this cboxdk/cms is built for. Install the Laravel version that composer.json of cboxdk/cms requires, then run cms:doctor again.',
            ),
            self::DoctorLcMessagesNotEnglish => $this->violation(
                'Postgres or the PHP process writes its messages in another language than English, and the kernel recognises some errors of Postgres by their English text, such as a missing partition. Set lc_messages to C for the app role and the owner role and as the server default, and give PHP an English message locale, then run cms:doctor again.',
            ),
            self::DoctorNodeMissing => $this->readiness(
                'Node is not installed, or not on the PATH, and the development tools need it (cms:doctor --dev). Install Node in the version cbox-cms.doctor.node_minimum names or newer.',
            ),
            self::DoctorNodeVersion => $this->readiness(
                'The installed Node is older than cbox-cms.doctor.node_minimum, which the development tools need (cms:doctor --dev). Install a newer Node.',
            ),
            self::DoctorOwnerCredentialsExposed => $this->readiness(
                'The owner connection, which may change the schema and passes the row level security, is configured in a process that serves HTTP, runs queued jobs, or is not declared the maintenance process (PRD 4.2). Remove the owner connection from that process\'s configuration, or declare the maintenance process with CBOX_CMS_MAINTENANCE_PROCESS=true.',
            ),
            self::DoctorPartitionRunwayShort => $this->readiness(
                'A partitioned table has partitions for fewer days ahead than cbox-cms.doctor.partition_runway_days, so writes will fail with partition_missing when the runway runs out. Check that the scheduler runs cms:partitions:maintain every hour in the maintenance process, or run it now.',
            ),
            self::DoctorPartitionTableUnmanageable => $this->readiness(
                'A table in cbox-cms.database.partitions.tables cannot be managed as it is: it is missing, not partitioned by range, or has a DEFAULT partition. Run the migrations, or correct the table or its entry, then run cms:doctor again.',
            ),
            self::DoctorPhpAllowUrlFopen => $this->violation(
                'PHP\'s allow_url_fopen is on, so the file functions can fetch URLs past the guard that all outbound requests go through. Set allow_url_fopen = Off in php.ini, or start PHP with -d allow_url_fopen=0.',
            ),
            self::DoctorPhpVersion => $this->violation(
                'The PHP version is older than cboxdk/cms needs. Install the PHP version docs/requirements.md names.',
            ),
            self::DoctorPlaywrightMissing => $this->readiness(
                'Playwright is not installed in the project, and the browser tests need it (cms:doctor --dev). Run npm ci in the project.',
            ),
            self::DoctorPostgresQueryFailed => $this->dependency(
                'The doctor reached Postgres, but a query of a later check failed, for example because the server went away in between. Check that Postgres is running and that the app role may read the system catalogs, then run cms:doctor again.',
            ),
            self::DoctorPostgresRefused => $this->violation(
                'Postgres answered, but refused the app role\'s login, for example because the password or the database is wrong. Correct the connection settings of the app role, then run cms:doctor again.',
            ),
            self::DoctorPostgresUnavailable => $this->dependency(
                'The doctor could not reach Postgres in time. Check that Postgres is running and that the host and port of the app role\'s connection are right, then run cms:doctor again.',
            ),
            self::DoctorPostgresVersion => $this->violation(
                'The Postgres server is older than version 17, the lowest version the kernel supports (GUARDRAILS 1.2). Upgrade Postgres.',
            ),
            self::DoctorPreparedTransactionsEnabled => $this->violation(
                'Postgres allows prepared transactions (max_prepared_transactions above 0). A prepared transaction that is never finished holds its locks and stops vacuum, and the kernel never uses one (PRD 4.2). Set max_prepared_transactions = 0 and restart Postgres.',
            ),
            self::DoctorRegistryCacheDamaged => $this->violation(
                'The registry cache in bootstrap/cache/cms cannot be read: a file is damaged, or the files come from different builds. Run cms:build.',
            ),
            self::DoctorRegistryCacheMissing => $this->violation(
                'The registry cache in bootstrap/cache/cms, which cms:build compiles from the installed code, does not exist. Run cms:build.',
            ),
            self::DoctorRegistryCacheStale => $this->violation(
                'The registry cache is older than Composer\'s last change to vendor/, so it may miss a hook or run one that is gone. Run cms:build.',
            ),
            self::DoctorRowSecurityNotForced => $this->violation(
                'A table has row level security enabled but not forced, so its policies do not hold for the table\'s owner (PRD 4.2). Run ALTER TABLE <table> FORCE ROW LEVEL SECURITY as the owner role, then run cms:doctor again.',
            ),
            self::DoctorTransactionTimeoutMissing => $this->violation(
                'The app role has no transaction_timeout of its own, so a transaction that hangs holds back the event horizon and vacuum until someone ends it (PRD 4.2). As a superuser, run ALTER ROLE <app role> SET transaction_timeout = \'5s\', then run cms:doctor again.',
            ),
            self::DoctorValkeyRefused => $this->violation(
                'Valkey answered, but refused the connection, for example because the password is wrong. Correct the settings of the Redis connection cbox-cms.doctor.redis_connection names, then run cms:doctor again.',
            ),
            self::DoctorValkeyUnavailable => $this->dependency(
                'The doctor could not reach Valkey in time. Check that Valkey is running and that the host and port of the Redis connection are right, then run cms:doctor again.',
            ),
            self::DoctorVendorManifestMissing => $this->violation(
                'Composer\'s vendor/composer/installed.json, or the file cbox-cms.doctor.vendor_manifest names, does not exist, so the doctor cannot tell whether the registry cache is current. Run composer install, or correct the setting.',
            ),
            self::DryRun => new ErrorEntry(
                $this,
                HttpStatus::Ok,
                ExitCode::Ok,
                McpResponse::Result,
                false,
                'The command ran as a dry run: the kernel computed the plan and the receipt and committed nothing (PRD 6.1). This is no failure. Send the command again without dry_run to commit it.',
            ),
            self::FakeCheckFailed => $this->violation(
                'A FakeDoctorCheck of the testkit failed, because a test told it to. Only tests see this code.',
            ),
            self::GenerateColumnNameTooLong => $this->refusedInput(
                'A field\'s column name is longer than 63 bytes, the limit Postgres has for a name. The column of an extension field is ext__<namespace>__<handle>. Give the field, or the namespace, a shorter handle.',
            ),
            self::GenerateDuplicateFieldHandle => $this->refusedInput(
                'Two fields in one namespace have the same handle: the fields of a type, of a group, or the fields one owner adds to one type. Rename one of them.',
            ),
            self::GenerateDuplicateSelectValue => $this->refusedInput(
                'Two options of one select field have the same value. Remove or rename one of them.',
            ),
            self::GenerateDuplicateTypeHandle => $this->refusedInput(
                'Two blueprint files of one owner define a type with the same handle. Rename one of the types, or remove one file.',
            ),
            self::GenerateDuplicateTypeId => $this->refusedInput(
                'Two blueprint files define a type with the same type_id, the identity of a type. Give each type its own type_id.',
            ),
            self::GenerateExtensionOfOwnType => $this->refusedInput(
                'An extension extends a type of its own owner. Only others extend a type; the owner adds its fields in the type\'s own file (PRD 11.12, 13.3).',
            ),
            self::GenerateExtensionVersionMismatch => $this->refusedInput(
                'Two extension files of one owner for one type declare different versions. The fields one owner adds to a type share one version: give both files the same one.',
            ),
            self::GenerateInvalidCaseName => $this->refusedInput(
                'A type\'s owner and handle give no valid PHP enum case, or two types of one owner give the same case. Rename one of the types.',
            ),
            self::GenerateInvalidConfig => $this->tooling(
                ExitCode::Config,
                'The configuration under cbox-cms.generators is missing a value or has an invalid one, or a package the generators need is not installed. Correct the setting, or install the package with composer require --dev, as the cause says.',
            ),
            self::GenerateInvalidOutput => $this->tooling(
                ExitCode::Software,
                'A generator produced a file outside its directory, or two files with the same path, so nothing was written. This is a bug in the generator. Report it with the cause.',
            ),
            self::GenerateMinAboveMax => $this->refusedInput(
                'A field\'s min is greater than its max. Correct one of them.',
            ),
            self::GenerateMinItemsAboveMaxItems => $this->refusedInput(
                'A field\'s min_items is greater than its max_items. Correct one of them.',
            ),
            self::GenerateMinLengthAboveMaxLength => $this->refusedInput(
                'A field\'s min_length is greater than its max_length, or than the default max_length of its type. Correct one of them.',
            ),
            self::GenerateOutputUnwritable => $this->tooling(
                ExitCode::CantCreat,
                'A generated file could not be written, or a stale one could not be removed. Check the permissions of the generated directories, then run cms:generate again.',
            ),
            self::GenerateScaleAbovePrecision => $this->refusedInput(
                'A decimal field\'s scale is greater than its precision. Correct one of them.',
            ),
            self::GenerateSchemaInvalid => $this->refusedInput(
                'A blueprint file is not valid YAML, or not a valid blueprint of the blueprint schema v1. Correct the file at the place the cause names.',
            ),
            self::GenerateSchemaMissing => $this->tooling(
                ExitCode::NoInput,
                'A schema root in cbox-cms.generators.roots, or a blueprint file below one, does not exist or cannot be read. Create the directory, or correct the setting.',
            ),
            self::GenerateSchemaUnsupportedVersion => $this->refusedInput(
                'A blueprint file is of a later version than this cboxdk/cms reads, or uses a value it does not know. Update cboxdk/cms.',
            ),
            self::GenerateSchemaUnwritable => $this->tooling(
                ExitCode::CantCreat,
                'cms:schema:editor could not write the editor line into a blueprint file. Check the permissions of the file, then run the command again.',
            ),
            self::GenerateTooManyFields => $this->refusedInput(
                'A type has more than 200 top-level fields, its own and those its extensions add together, and each is a column of the type\'s table (PRD 11.6). Move fields into groups, or split the type.',
            ),
            self::GenerateUnknownExtendsTarget => $this->refusedInput(
                'An extension\'s extends names a type_id that no blueprint file below the schema roots defines. Correct the type_id, or add the schema root of the type\'s owner.',
            ),
            self::GenerateUnknownFieldType => $this->refusedInput(
                'A field\'s type is a <namespace>:<handle> that no installed module or addon registers. Correct the type, or install the addon that provides it.',
            ),
            self::IdempotencyConflict => $this->caller(
                HttpStatus::Conflict,
                ExitCode::DataErr,
                'The idempotency key was used before with other content (PRD 6.1), so the command was rejected and the first result was left as it was. Use a new key for a new command; send the same key only with the same content.',
            ),
            self::IdempotencyInFlight => new ErrorEntry(
                $this,
                HttpStatus::Conflict,
                ExitCode::TempFail,
                McpResponse::ToolError,
                true,
                'Another call with the same idempotency key is still running, and it did not finish within the wait budget, cbox-cms.idempotency.wait_budget_ms (PRD 6.1). Nothing was committed by this call. Try again in a moment with the same key and content: you then get the first call\'s result.',
            ),
            self::OwnerCredentialsExposed => $this->violation(
                'The core refused to boot a process that serves HTTP or runs queued jobs, because the owner connection is configured in it (PRD 4.2). Give the owner connection to the maintenance process alone, which runs the migrations and cms:partitions:maintain.',
            ),
            self::PartitionLockTimeout => new ErrorEntry(
                $this,
                HttpStatus::ServiceUnavailable,
                ExitCode::TempFail,
                McpResponse::InternalError,
                true,
                'Partition maintenance could not take a lock within its lock_timeout after every attempt, because other transactions held the table. The other tables were still maintained. Run cms:partitions:maintain again; the scheduler runs it every hour.',
            ),
            self::PartitionMissing => new ErrorEntry(
                $this,
                HttpStatus::ServiceUnavailable,
                ExitCode::TempFail,
                McpResponse::InternalError,
                true,
                'No partition covers the time a row was written at, so the write was refused and nothing was committed. Partition maintenance has fallen behind: run cms:partitions:maintain in the maintenance process, check that the scheduler runs it every hour, and try again.',
            ),
            self::PartitionOwnerRequired => $this->violation(
                'Partition maintenance ran on a connection that is not the owner connection, cbox-cms.database.owner_connection, and only the owner role may change the partitions. Run cms:partitions:maintain in the maintenance process, which has the owner connection.',
            ),
            self::PartitionTableUnmanageable => $this->violation(
                'A table in cbox-cms.database.partitions.tables cannot be managed as it is: it is missing, not partitioned by range, has a DEFAULT partition, or Postgres refused a step on one of its partitions. The other tables were still maintained. Run the migrations, or correct the table or its entry as the cause says.',
            ),
            self::RegistryCacheMalformed => $this->violation(
                'The registry cache in bootstrap/cache/cms is damaged, or its files come from different builds, so the kernel cannot read its commands and hooks. Run cms:build.',
            ),
            self::RegistryCacheMissing => $this->violation(
                'The registry cache in bootstrap/cache/cms does not exist, so the kernel does not know its commands and hooks. Run cms:build; Composer runs it after every install.',
            ),
            self::RegistryCacheUnwritable => $this->tooling(
                ExitCode::CantCreat,
                'cms:build could not write the registry cache to bootstrap/cache/cms, or not remove an old file there, or waited too long for another build. Check the permissions of the directory, then run cms:build again.',
            ),
            self::RegistryClassInTwoRoots => $this->refusedInput(
                'Two different scan roots contain the same class, so the registry cannot say which package declares it. Remove the class from one of the scan roots.',
            ),
            self::RegistryClassNotLoadable => $this->refusedInput(
                'A class in a scan root cannot be autoloaded, or loading it failed. Check its namespace against the autoloading rules of its package, and the error in the cause.',
            ),
            self::RegistryDuplicateCommand => $this->refusedInput(
                'Two classes declare the same command name and version with #[Command]. Rename one of the commands, or give it another version.',
            ),
            self::RegistryInvalidAttribute => $this->refusedInput(
                'The arguments of a #[Command] or #[Hook] attribute are invalid, so it cannot be built. Correct the attribute as the cause says.',
            ),
            self::RegistryInvalidScanRoot => $this->refusedInput(
                'A scan root that a service provider declares is not a readable directory. Correct the directory the provider returns from scanRoots().',
            ),
            self::RegistryNotAConcreteClass => $this->refusedInput(
                'A #[Command] or #[Hook] attribute sits on an interface, a trait, an enum or an abstract class. Put it on a concrete class.',
            ),
            self::RegistryNotFinalReadonly => $this->refusedInput(
                'A #[Command] sits on a class that is not a final readonly class (GUARDRAILS 2.1). Make the command class final readonly.',
            ),
            self::RegistryUnknownHookCommand => $this->refusedInput(
                'A hook runs for a command class that no scan root registers. Correct the command the #[Hook] names, or declare the scan root of the package that has it.',
            ),
            self::Unauthorized => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'The actor may not run this command on this target, so the command was rejected and nothing was committed. Ask for the right the command needs, or run it as an actor that has it.',
            ),
            self::ValidationFailed => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The command\'s content is invalid: a field is missing, has the wrong type or breaks a rule of its blueprint, so nothing was committed. Correct the fields the error lists, then send the command again.',
            ),
            self::VersionConflict => $this->caller(
                HttpStatus::Conflict,
                ExitCode::DataErr,
                'The target changed after the caller read it: its version is not the version the command expected, so nothing was committed and nothing was overwritten (PRD 6.1). Read the target again, apply the change to what is there now, and send the command with the new version.',
            ),
        };
    }

    /**
     * The section of the error reference for the code (ErrorEntry::docs()).
     */
    public function docs(): string
    {
        return $this->entry()->docs();
    }

    /**
     * A command that the caller's input or rights stopped. The agent can act on the tool error;
     * sending the same call again gives the same answer.
     */
    private function caller(HttpStatus $http, ExitCode $exit, string $explanation): ErrorEntry
    {
        return new ErrorEntry($this, $http, $exit, McpResponse::ToolError, false, $explanation);
    }

    /**
     * A credential that does not verify. The caller is not known, and sending the same credential
     * again gives the same answer.
     */
    private function credential(string $explanation): ErrorEntry
    {
        return new ErrorEntry($this, HttpStatus::Unauthorized, ExitCode::NoPerm, McpResponse::ToolError, false, $explanation);
    }

    /**
     * The configuration is invalid or the runtime contract is broken; someone has to change it.
     */
    private function violation(string $explanation): ErrorEntry
    {
        return new ErrorEntry($this, HttpStatus::InternalServerError, ExitCode::Config, McpResponse::InternalError, false, $explanation);
    }

    /**
     * A dependency could not be reached right now; trying again later may help.
     */
    private function dependency(string $explanation): ErrorEntry
    {
        return new ErrorEntry($this, HttpStatus::ServiceUnavailable, ExitCode::TempFail, McpResponse::InternalError, true, $explanation);
    }

    /**
     * A check that decides readiness, not whether the kernel may start, failed.
     */
    private function readiness(string $explanation): ErrorEntry
    {
        return new ErrorEntry($this, HttpStatus::ServiceUnavailable, ExitCode::NotReady, McpResponse::InternalError, false, $explanation);
    }

    /**
     * cms:generate refused a blueprint, or cms:build the declarations of commands and hooks; the
     * schema or the code has to change.
     */
    private function refusedInput(string $explanation): ErrorEntry
    {
        return new ErrorEntry($this, HttpStatus::InternalServerError, ExitCode::DataErr, McpResponse::InternalError, false, $explanation);
    }

    /**
     * A build tool could not read its configuration or input, or write its output.
     */
    private function tooling(ExitCode $exit, string $explanation): ErrorEntry
    {
        return new ErrorEntry($this, HttpStatus::InternalServerError, $exit, McpResponse::InternalError, false, $explanation);
    }
}
