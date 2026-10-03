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

    case AccessBootstrapDone = 'access_bootstrap_done';
    case AccessBootstrapProduction = 'access_bootstrap_production';
    case AccessBootstrapRoleConflict = 'access_bootstrap_role_conflict';
    case ActorNotActive = 'actor_not_active';
    case AddonServiceActorUnavailable = 'addon_service_actor_unavailable';
    case AgentVisibilityForbidden = 'agent_visibility_forbidden';
    case BreachedPasswordsUnavailable = 'breached_passwords_unavailable';
    case CredentialExpired = 'credential_expired';
    case CredentialMalformed = 'credential_malformed';
    case CredentialNotAllowed = 'credential_not_allowed';
    case CredentialRevoked = 'credential_revoked';
    case CredentialUnknown = 'credential_unknown';
    case DoctorAppRoleBypassrls = 'doctor_app_role_bypassrls';
    case DoctorAppRoleCreaterole = 'doctor_app_role_createrole';
    case DoctorAppRoleHasDdl = 'doctor_app_role_has_ddl';
    case DoctorAppRolePrivilegedMembership = 'doctor_app_role_privileged_membership';
    case DoctorAppRoleSuperuser = 'doctor_app_role_superuser';
    case DoctorArgon2idUnavailable = 'doctor_argon2id_unavailable';
    case DoctorCheckCrashed = 'doctor_check_crashed';
    case DoctorChromiumMissing = 'doctor_chromium_missing';
    case DoctorConfigInvalid = 'doctor_config_invalid';
    case DoctorCredentialStoreMissing = 'doctor_credential_store_missing';
    case DoctorCredentialStoreReadable = 'doctor_credential_store_readable';
    case DoctorEventLogUnreadable = 'doctor_event_log_unreadable';
    case DoctorEventsLag = 'doctor_events_lag';
    case DoctorEventsParked = 'doctor_events_parked';
    case DoctorExtensionMissing = 'doctor_extension_missing';
    case DoctorHorizonHeld = 'doctor_horizon_held';
    case DoctorIdentityConnectionRefused = 'doctor_identity_connection_refused';
    case DoctorIdentityConnectionSharedRole = 'doctor_identity_connection_shared_role';
    case DoctorIdentityConnectionUnavailable = 'doctor_identity_connection_unavailable';
    case DoctorIdentityRolePrivileged = 'doctor_identity_role_privileged';
    case DoctorIdleInTransactionTimeoutMissing = 'doctor_idle_in_transaction_timeout_missing';
    case DoctorLaravelVersion = 'doctor_laravel_version';
    case DoctorLcMessagesNotEnglish = 'doctor_lc_messages_not_english';
    case DoctorLoginPolicyInvalid = 'doctor_login_policy_invalid';
    case DoctorNodeMissing = 'doctor_node_missing';
    case DoctorNodeVersion = 'doctor_node_version';
    case DoctorOperatorInvalid = 'doctor_operator_invalid';
    case DoctorOperatorMissing = 'doctor_operator_missing';
    case DoctorOperatorUnreadable = 'doctor_operator_unreadable';
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
    case DoctorSessionCookieInsecure = 'doctor_session_cookie_insecure';
    case DoctorSessionCookieInvalid = 'doctor_session_cookie_invalid';
    case DoctorSnapshotHeld = 'doctor_snapshot_held';
    case DoctorTransactionTimeoutMissing = 'doctor_transaction_timeout_missing';
    case DoctorValkeyRefused = 'doctor_valkey_refused';
    case DoctorValkeyUnavailable = 'doctor_valkey_unavailable';
    case DoctorVendorManifestMissing = 'doctor_vendor_manifest_missing';
    case DryRun = 'dry_run';
    case EgressBlocked = 'egress_blocked';
    case EgressGuardDisabled = 'egress_guard_disabled';
    case EgressMailFailed = 'egress_mail_failed';
    case EgressRedirectRefused = 'egress_redirect_refused';
    case EgressUnavailable = 'egress_unavailable';
    case FakeCheckFailed = 'fake_check_failed';
    case FieldEncryptionUnavailable = 'field_encryption_unavailable';
    case GenerateColumnNameTooLong = 'generate_column_name_too_long';
    case GenerateDuplicateFieldHandle = 'generate_duplicate_field_handle';
    case GenerateDuplicateSelectValue = 'generate_duplicate_select_value';
    case GenerateDuplicateTypeHandle = 'generate_duplicate_type_handle';
    case GenerateDuplicateTypeId = 'generate_duplicate_type_id';
    case GenerateExtensionOfOwnType = 'generate_extension_of_own_type';
    case GenerateExtensionVersionMismatch = 'generate_extension_version_mismatch';
    case GenerateFieldChanged = 'generate_field_changed';
    case GenerateFieldNotQueryable = 'generate_field_not_queryable';
    case GenerateFieldRemoved = 'generate_field_removed';
    case GenerateInvalidCaseName = 'generate_invalid_case_name';
    case GenerateInvalidConfig = 'generate_invalid_config';
    case GenerateInvalidOutput = 'generate_invalid_output';
    case GenerateLockInvalid = 'generate_lock_invalid';
    case GenerateMinAboveMax = 'generate_min_above_max';
    case GenerateMinItemsAboveMaxItems = 'generate_min_items_above_max_items';
    case GenerateMinLengthAboveMaxLength = 'generate_min_length_above_max_length';
    case GenerateNameCollision = 'generate_name_collision';
    case GenerateOutputUnwritable = 'generate_output_unwritable';
    case GenerateRequiredFieldAdded = 'generate_required_field_added';
    case GenerateScaleAbovePrecision = 'generate_scale_above_precision';
    case GenerateSchemaInvalid = 'generate_schema_invalid';
    case GenerateSchemaMissing = 'generate_schema_missing';
    case GenerateSchemaUnsupportedVersion = 'generate_schema_unsupported_version';
    case GenerateSchemaUnwritable = 'generate_schema_unwritable';
    case GenerateTableChanged = 'generate_table_changed';
    case GenerateTableNameTooLong = 'generate_table_name_too_long';
    case GenerateTooManyFields = 'generate_too_many_fields';
    case GenerateTypeRemoved = 'generate_type_removed';
    case GenerateUnknownExtendsTarget = 'generate_unknown_extends_target';
    case GenerateUnknownFieldType = 'generate_unknown_field_type';
    case GrantEscalationRefused = 'grant_escalation_refused';
    case HookBudgetExceeded = 'hook_budget_exceeded';
    case HookChangeRefused = 'hook_change_refused';
    case HostNotConfigured = 'host_not_configured';
    case IdempotencyConflict = 'idempotency_conflict';
    case IdempotencyInFlight = 'idempotency_in_flight';
    case IdempotencyKeyRequired = 'idempotency_key_required';
    case InstallOwnerConnectionRequired = 'install_owner_connection_required';
    case InstallationOperatorMissing = 'installation_operator_missing';
    case JsonInvalid = 'json_invalid';
    case JsonMalformed = 'json_malformed';
    case LocalAccountExists = 'local_account_exists';
    case LocalAccountMissing = 'local_account_missing';
    case LoginAuthoritativeLink = 'login_authoritative_link';
    case LoginClassNotAllowed = 'login_class_not_allowed';
    case LoginConnectionNotAllowed = 'login_connection_not_allowed';
    case LoginFactorsUnavailable = 'login_factors_unavailable';
    case LoginIssuerMismatch = 'login_issuer_mismatch';
    case LoginLocalDisabled = 'login_local_disabled';
    case LoginMethodNotAllowed = 'login_method_not_allowed';
    case LoginPolicyInvalid = 'login_policy_invalid';
    case LoginRateLimited = 'login_rate_limited';
    case LoginRejected = 'login_rejected';
    case LoginStateMismatch = 'login_state_mismatch';
    case LoginTenantClaimMissing = 'login_tenant_claim_missing';
    case LoginTenantMismatch = 'login_tenant_mismatch';
    case MaintenanceProcessRequired = 'maintenance_process_required';
    case OwnerCredentialsExposed = 'owner_credentials_exposed';
    case PartitionLockTimeout = 'partition_lock_timeout';
    case PartitionMissing = 'partition_missing';
    case PartitionOwnerRequired = 'partition_owner_required';
    case PartitionTableUnmanageable = 'partition_table_unmanageable';
    case PasswordBreached = 'password_breached';
    case PasswordResetTokenInvalid = 'password_reset_token_invalid';
    case PasswordTooLong = 'password_too_long';
    case PasswordTooShort = 'password_too_short';
    case PathGone = 'path_gone';
    case PathNotFound = 'path_not_found';
    case PlacementSlugTaken = 'placement_slug_taken';
    case QueryOverBudget = 'query_over_budget';
    case RebuildIdentityInvalid = 'rebuild_identity_invalid';
    case RebuildSchemaVersionUnsupported = 'rebuild_schema_version_unsupported';
    case RebuildTypeUnknown = 'rebuild_type_unknown';
    case RegistryAddonNotAllowed = 'registry_addon_not_allowed';
    case RegistryCacheMalformed = 'registry_cache_malformed';
    case RegistryCacheMissing = 'registry_cache_missing';
    case RegistryCacheUnwritable = 'registry_cache_unwritable';
    case RegistryClassInTwoRoots = 'registry_class_in_two_roots';
    case RegistryClassNotLoadable = 'registry_class_not_loadable';
    case RegistryDuplicateAction = 'registry_duplicate_action';
    case RegistryDuplicateCommand = 'registry_duplicate_command';
    case RegistryDuplicateNamespace = 'registry_duplicate_namespace';
    case RegistryDuplicatePanelPoint = 'registry_duplicate_panel_point';
    case RegistryDuplicateSubscription = 'registry_duplicate_subscription';
    case RegistryIncompatibleCoreApi = 'registry_incompatible_core_api';
    case RegistryIncompatiblePanelApi = 'registry_incompatible_panel_api';
    case RegistryInvalidAttribute = 'registry_invalid_attribute';
    case RegistryInvalidManifest = 'registry_invalid_manifest';
    case RegistryInvalidScanRoot = 'registry_invalid_scan_root';
    case RegistryNotAConcreteClass = 'registry_not_a_concrete_class';
    case RegistryNotAHook = 'registry_not_a_hook';
    case RegistryNotASubscriber = 'registry_not_a_subscriber';
    case RegistryNotAnAction = 'registry_not_an_action';
    case RegistryNotFinalReadonly = 'registry_not_final_readonly';
    case RegistryPanelActionPrefillInvalid = 'registry_panel_action_prefill_invalid';
    case RegistryPanelBundleInvalid = 'registry_panel_bundle_invalid';
    case RegistryPanelCheckUnmirrored = 'registry_panel_check_unmirrored';
    case RegistryPanelCommandNotIssuable = 'registry_panel_command_not_issuable';
    case RegistryPanelDataQueryInvalid = 'registry_panel_data_query_invalid';
    case RegistryPanelDuplicateContribution = 'registry_panel_duplicate_contribution';
    case RegistryPanelExperimentalNotAccepted = 'registry_panel_experimental_not_accepted';
    case RegistryPanelFlowPathUnknown = 'registry_panel_flow_path_unknown';
    case RegistryPanelInternalPoint = 'registry_panel_internal_point';
    case RegistryPanelKindMismatch = 'registry_panel_kind_mismatch';
    case RegistryPanelNavTargetUnknown = 'registry_panel_nav_target_unknown';
    case RegistryPanelOverrideInvalid = 'registry_panel_override_invalid';
    case RegistryPanelPointDeprecated = 'registry_panel_point_deprecated';
    case RegistryPanelPointExperimental = 'registry_panel_point_experimental';
    case RegistryPanelPointWithoutDowncast = 'registry_panel_point_without_downcast';
    case RegistryPanelPointWithoutStability = 'registry_panel_point_without_stability';
    case RegistryPanelReplacementConflict = 'registry_panel_replacement_conflict';
    case RegistryPanelTighteningUndeclared = 'registry_panel_tightening_undeclared';
    case RegistryPanelUnknownCommand = 'registry_panel_unknown_command';
    case RegistryPanelUnknownPoint = 'registry_panel_unknown_point';
    case RegistryPanelUnownedTarget = 'registry_panel_unowned_target';
    case RegistryReservedNamespace = 'registry_reserved_namespace';
    case RegistrySurfaceWithoutCodec = 'registry_surface_without_codec';
    case RegistryUndeclaredHook = 'registry_undeclared_hook';
    case RegistryUndeclaredSubscriber = 'registry_undeclared_subscriber';
    case RegistryUnknownActionCommand = 'registry_unknown_action_command';
    case RegistryUnknownEvent = 'registry_unknown_event';
    case RegistryUnknownHookCommand = 'registry_unknown_hook_command';
    case RegistryUnknownLane = 'registry_unknown_lane';
    case RegistryUnknownSurface = 'registry_unknown_surface';
    case RequestHeaderInvalid = 'request_header_invalid';
    case ScimInvalidValue = 'scim_invalid_value';
    case ScimMutability = 'scim_mutability';
    case ScimReactivationRefused = 'scim_reactivation_refused';
    case ScimResourceNotFound = 'scim_resource_not_found';
    case ScimUniqueness = 'scim_uniqueness';
    case ScimVersionMismatch = 'scim_version_mismatch';
    case SessionCookieInsecure = 'session_cookie_insecure';
    case SessionCookieInvalid = 'session_cookie_invalid';
    case SignalAudienceMismatch = 'signal_audience_mismatch';
    case SignalEventUnsupported = 'signal_event_unsupported';
    case SignalExpired = 'signal_expired';
    case SignalIssuedInFuture = 'signal_issued_in_future';
    case SignalIssuerMismatch = 'signal_issuer_mismatch';
    case SignalLogoutEventMissing = 'signal_logout_event_missing';
    case SignalNoncePresent = 'signal_nonce_present';
    case SignalReplayed = 'signal_replayed';
    case SignalSubjectMissing = 'signal_subject_missing';
    case SignalSubjectUnsupported = 'signal_subject_unsupported';
    case SiteLocalesDrift = 'site_locales_drift';
    case StepUpRequired = 'step_up_required';
    case SubscriptionIdentityInvalid = 'subscription_identity_invalid';
    case SubscriptionNotParked = 'subscription_not_parked';
    case SubscriptionUnknown = 'subscription_unknown';
    case TypeNotReleasable = 'type_not_releasable';
    case Unauthorized = 'unauthorized';
    case ValidationAboveMaximum = 'validation_above_maximum';
    case ValidationBelowMinimum = 'validation_below_minimum';
    case ValidationDuplicateItem = 'validation_duplicate_item';
    case ValidationFailed = 'validation_failed';
    case ValidationHookFailed = 'validation_hook_failed';
    case ValidationInvalidFormat = 'validation_invalid_format';
    case ValidationInvalidRichText = 'validation_invalid_rich_text';
    case ValidationNotAnOption = 'validation_not_an_option';
    case ValidationRequired = 'validation_required';
    case ValidationRichTextNotAllowed = 'validation_rich_text_not_allowed';
    case ValidationTooFewItems = 'validation_too_few_items';
    case ValidationTooLong = 'validation_too_long';
    case ValidationTooManyDigits = 'validation_too_many_digits';
    case ValidationTooManyItems = 'validation_too_many_items';
    case ValidationTooShort = 'validation_too_short';
    case ValidationUnknownField = 'validation_unknown_field';
    case ValidationWrongType = 'validation_wrong_type';
    case VersionConflict = 'version_conflict';

    /**
     * What the code means on each surface, whether a retry makes sense, and the explanation.
     */
    public function entry(): ErrorEntry
    {
        return match ($this) {
            self::AccessBootstrapDone => $this->caller(
                HttpStatus::Conflict,
                ExitCode::NoPerm,
                'The one-time access bootstrap was refused, because a staff member holds a grant already (PRD 5.10, 5.16), so it has done its work once. Nothing was committed. Give further access in the panel as a staff member whose roles allow it; the escalation guard holds every grant to what its issuer has.',
            ),
            self::AccessBootstrapProduction => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'The one-time access bootstrap does not run in the production environment (PRD 5.10): full access in production comes only from a role that only actors on the emergency access list can assign, and that list is not built yet. Nothing was committed. Bootstrap access in an environment that is not production.',
            ),
            self::AccessBootstrapRoleConflict => $this->caller(
                HttpStatus::Conflict,
                ExitCode::DataErr,
                'The one-time access bootstrap was refused, because a role has the handle in cbox-cms.access.bootstrap_role already and it is not the full role the bootstrap grants: its ceiling is below sensitive, or it lacks a command or query of the registry (PRD 5.10). Nothing was committed. Name another handle in cbox-cms.access.bootstrap_role.',
            ),
            self::ActorNotActive => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'The actor, or an actor it acts on behalf of, is not active (PRD 5.16, invariant 37): its credentials are refused, it runs no command and reads nothing, the subscribers of a service actor do not run, and nothing was committed. Reactivate the actor, or call as an actor that is active.',
            ),
            self::AddonServiceActorUnavailable => $this->violation(
                'An addon\'s subscriber did not run, because the addon has no active service actor to run as (PRD 13.1, invariant 21): none is configured in cbox-cms.addons.service_actors, no actor has the configured id, or the actor is not an active service actor. It never runs as the system instead. Configure the service actor created when the addon\'s capabilities were approved, or reactivate it.',
            ),
            self::AgentVisibilityForbidden => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'An agent or a token may not make content public (invariant 18): the command would release a revision, open a placement\'s window or otherwise change what the public sees, so nothing was committed. An agent can prepare the change, such as a hidden placement or a draft; a person makes it public.',
            ),
            self::BreachedPasswordsUnavailable => $this->dependency(
                'Whether the password is known from data breaches could not be checked, because the service the check asks did not answer or answered something it could not read (PRD 5.16). The check fails closed, so the password was neither accepted nor refused as breached, and nothing was committed. Try again in a moment; if it keeps failing, check the counters cms.egress.failures and cms.identity.breached_passwords.checks.',
            ),
            self::CredentialExpired => $this->credential(
                'The credential\'s expiry has passed, so it was refused and nothing was read or committed (PRD 5.16). Every credential has an expiry. Call again with a credential that is still valid.',
            ),
            self::CredentialMalformed => $this->credential(
                'The credential is not in the form of a credential, or its checksum does not match, so it was refused without a lookup (PRD 5.16). Check that the whole token was sent, without spaces or a missing part.',
            ),
            self::CredentialNotAllowed => $this->credential(
                'The session was refused because the login policy of this environment no longer allows how it was obtained: its actor class, its login connection or its login method is no longer allowed, or local login was switched off (PRD 5.16). Nothing was read or committed. Log in again through a way the policy allows.',
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
            self::DoctorArgon2idUnavailable => $this->violation(
                'This PHP has no Argon2id (PASSWORD_ARGON2ID), which the local accounts hash their passwords with (PRD 5.16). Install PHP with libargon2 or libsodium support, such as the php-baseimages images, then run cms:doctor again.',
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
            self::DoctorCredentialStoreMissing => $this->violation(
                'The schema cms_identity of the credential store does not exist in the database, so the local accounts have nowhere to keep their credentials (PRD 5.16). Create it as the owner role with the identity role\'s grants, as docs/security/credential-store.md says, run the migrations, then run cms:doctor again.',
            ),
            self::DoctorCredentialStoreReadable => $this->violation(
                'The app role has a privilege on the credential store, the schema cms_identity or one of its tables, so the web and queue processes could read or change credentials (PRD 5.16). As the owner role or a superuser, revoke what the cause names from the app role and from PUBLIC, then run cms:doctor again.',
            ),
            self::DoctorEventLogUnreadable => $this->readiness(
                'The doctor could not read the event log\'s cursors, events or parked aggregates, or the subscriptions of the registry cache, so it cannot say how far the subscribers are behind (PRD 7.12). Check that the core\'s migrations have run and that cms:build has written the registry cache, then run cms:doctor again.',
            ),
            self::DoctorEventsLag => $this->readiness(
                'A subscription has an event it has not handled that is older than the lag target of its lane, such as 500 ms for the critical lane (PRD 7.6, 7.12). Either no runner is running the lane (cms:events:run), or a transaction that is still open holds back the transaction horizon below which the runners read (PRD 7.4). Start the lane\'s runner, or end the transaction that postgres.oldest_xact names.',
            ),
            self::DoctorEventsParked => $this->readiness(
                'A subscription has parked aggregates: events it failed to handle as often as it may, whose later events wait with them (PRD 7.8). List them with cms:events:parked, fix the cause the subscriber failed on, and release them with cms:events:release.',
            ),
            self::DoctorExtensionMissing => $this->violation(
                'A Postgres extension the core\'s tables need, such as ltree, is not installed in the database, so the migrations have not run against it (PRD 4.2). Run the migrations as the owner role in the maintenance process, then run cms:doctor again.',
            ),
            self::DoctorHorizonHeld => $this->readiness(
                'A transaction has held a transaction id for longer than the command budget, so the transaction horizon, below which the event runners read, cannot pass it and no subscriber sees the events committed after it began (PRD 4.2, 7.4). End the transaction the cause names, with pg_terminate_backend(<pid>) as its role or a superuser if it hangs, and find what keeps it open.',
            ),
            self::DoctorIdentityConnectionRefused => $this->violation(
                'Postgres answered, but refused the login of the identity connection, which cbox-cms.identity.connection names, or the connection is not configured as Postgres. Correct the connection\'s settings and the identity role\'s password, then run cms:doctor again.',
            ),
            self::DoctorIdentityConnectionSharedRole => $this->violation(
                'The identity connection logs in as the app role or the owner role, so the credential store is not isolated from the processes that use that role (PRD 5.16). Give the identity connection a role of its own, as docs/security/credential-store.md says, then run cms:doctor again.',
            ),
            self::DoctorIdentityConnectionUnavailable => $this->dependency(
                'The doctor could not reach Postgres in time on the identity connection, which cbox-cms.identity.connection names. Check that Postgres is running and that the host and port of the connection are right, then run cms:doctor again.',
            ),
            self::DoctorIdentityRolePrivileged => $this->violation(
                'The identity role is a superuser, has BYPASSRLS or CREATEROLE, or is a member of a role with more power, so it reaches more than the credential store (PRD 5.16, 4.2). As a superuser, take the attribute or the membership the cause names away, then run cms:doctor again.',
            ),
            self::DoctorIdleInTransactionTimeoutMissing => $this->violation(
                'The app role has no idle_in_transaction_session_timeout of its own, so a session that begins a transaction and then waits holds its locks, the event horizon and vacuum until something else ends it (PRD 7.4). As a superuser, run ALTER ROLE <app role> SET idle_in_transaction_session_timeout = \'5s\', then run cms:doctor again.',
            ),
            self::DoctorLaravelVersion => $this->violation(
                'The installed Laravel is not the major version this cboxdk/cms is built for. Install the Laravel version that composer.json of cboxdk/cms requires, then run cms:doctor again.',
            ),
            self::DoctorLcMessagesNotEnglish => $this->violation(
                'Postgres or the PHP process writes its messages in another language than English, and the kernel recognises some errors of Postgres by their English text, such as a missing partition. Set lc_messages to C for the app role and the owner role and as the server default, and give PHP an English message locale, then run cms:doctor again.',
            ),
            self::DoctorLoginPolicyInvalid => $this->violation(
                'The login policy of this environment, cbox-cms.identity.policy, cannot be used: a key is missing or has a value of another form, or the policy lets members of staff log in locally with a password alone in an environment other than local and testing, where PRD 5.16 requires a passkey or two factors. The web processes refuse to boot with it. Correct the key the cause names, as docs/security/login-policy.md describes it, then run cms:doctor again.',
            ),
            self::DoctorNodeMissing => $this->readiness(
                'Node is not installed, or not on the PATH, and the development tools need it (cms:doctor --dev). Install Node in the version cbox-cms.doctor.node_minimum names or newer.',
            ),
            self::DoctorNodeVersion => $this->readiness(
                'The installed Node is older than cbox-cms.doctor.node_minimum, which the development tools need (cms:doctor --dev). Install a newer Node.',
            ),
            self::DoctorOperatorInvalid => $this->readiness(
                'The installation operator, the service actor the maintenance commands run as, is not an active service actor (PRD 5.16), so no maintenance command can run. Find out who changed it from the audit; an operator is created once, by cms:install.',
            ),
            self::DoctorOperatorMissing => $this->readiness(
                'The installation has no operator yet, the service actor the maintenance commands run as (PRD 5.16). Run cms:install in the maintenance process, after the migrations and cms:partitions:maintain.',
            ),
            self::DoctorOperatorUnreadable => $this->readiness(
                'The doctor could not read the installation operator, usually because the core\'s migrations have not run. Run them as the owner role in the maintenance process, then run cms:doctor again.',
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
            self::DoctorSessionCookieInsecure => $this->violation(
                'The session cookie of this environment is not safe for an environment other than local and testing (PRD 5.16): it is not Secure, its name lacks the __Host- prefix, or it allows SameSite=None. The web processes refuse to boot with it. Set cbox-cms.identity.session.cookie for the environment to the name __Host-cms_session with secure true and same_site lax or strict, or remove the entry so the default applies, then run cms:doctor again.',
            ),
            self::DoctorSessionCookieInvalid => $this->violation(
                'The setting cbox-cms.identity.session.cookie is invalid for this environment, so no session cookie can be set: a name that is not a cookie token, a __Host- name without secure, a same_site other than lax, strict or none, or a value of the wrong type (PRD 5.16). Correct the key the cause names, then run cms:doctor again.',
            ),
            self::DoctorSnapshotHeld => $this->readiness(
                'A session of this database has held a snapshot for longer than the command budget, so vacuum cannot remove the rows that changed after it was taken, and the tables and their indexes grow (PRD 4.2). End the transaction the cause names, and run long reads on a replica, never on the primary.',
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
            self::EgressBlocked => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The egress gateway refused to send the outbound request (GUARDRAILS 3, PRD 7.14): the SSRF guard found that its URL points at a private, reserved or cloud metadata address or a blocked host, uses another scheme than https, or carries credentials. Nothing was sent. Use a public https URL.',
            ),
            self::EgressGuardDisabled => $this->violation(
                'The egress gateway sent nothing, because the policy of its SSRF guard is switched off: ssrf.enforce or ssrf.pin_dns of cboxdk/laravel-ssrf is false (GUARDRAILS 3). Without them the gateway cannot promise that a request never reaches a private address. Turn both on in config/ssrf.php or the environment (SSRF_ENFORCE).',
            ),
            self::EgressMailFailed => $this->dependency(
                'A mail was not handed to the mail transport of the installation\'s mailer, which the operator configures in mail.default and mail.mailers: the transport refused it, did not answer, or the mail has no sender, mail.from (PRD 5.16). Nothing was sent. Check the mailer\'s settings and that its host is reachable, then try again.',
            ),
            self::EgressRedirectRefused => new ErrorEntry(
                $this,
                HttpStatus::ServiceUnavailable,
                ExitCode::Unavailable,
                McpResponse::InternalError,
                false,
                'The destination of an outbound request answered with a redirect, which the egress gateway never follows, because a redirect can point at an address the SSRF guard would refuse (PRD 7.14). The request counts as failed. Use the URL the destination redirects to, if it is a public https URL.',
            ),
            self::EgressUnavailable => $this->dependency(
                'The destination of an outbound request did not answer within the egress gateway\'s timeouts, cbox-cms.egress.connect_timeout_ms and timeout_ms, or the connection failed (PRD 7.14). Try again later; if it keeps failing, check that the destination is up and reachable from the server.',
            ),
            self::FakeCheckFailed => $this->violation(
                'A FakeDoctorCheck of the testkit failed, because a test told it to. Only tests see this code.',
            ),
            self::FieldEncryptionUnavailable => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The field is classified confidential or above, so it is stored only as ciphertext under a scope or subject key (PRD 12.2), and this installation has no key management yet (PRD 12.3). The kernel refuses the value rather than store it in plain text, and nothing was committed. Leave the field out or send null.',
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
            self::GenerateFieldChanged => $this->refusedInput(
                'A field\'s column type, NOT NULL, CHECK constraints or index differ from its type\'s schema lock, the table the committed migrations build. Until schema evolution comes (B3), an existing column never changes: undo the change, or add a new optional field instead.',
            ),
            self::GenerateFieldNotQueryable => $this->refusedInput(
                'A field is declared filterable or sortable, but its field type has no order the typed query builder can compare: rich text, a group, or a select that allows several options. Remove filterable and sortable from the field, or model the value as its own field of a type that compares (PRD 8.8).',
            ),
            self::GenerateFieldRemoved => $this->refusedInput(
                'A field whose column is in its type\'s schema lock is no longer in the schema. Until schema evolution comes (B3), a type table only grows: put the field back, and stop using it instead.',
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
            self::GenerateLockInvalid => $this->refusedInput(
                'A schema lock (<table>.lock) in the migrations directory is not one cms:generate wrote: it is not valid JSON, lacks a value, has an unknown format, or its file name does not match its table. Restore the committed file with git, then run cms:generate again.',
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
            self::GenerateNameCollision => $this->refusedInput(
                'Two fields, options or extender namespaces of one type would get the same name in the type\'s generated PHP records or DTOs, such as the handles size_1 and size1, or a name PHP reserves; or two classes of the generated DTOs would get the same name. Rename one of them so they differ in more than underscores.',
            ),
            self::GenerateOutputUnwritable => $this->tooling(
                ExitCode::CantCreat,
                'A generated file could not be written, or a stale one could not be removed. Check the permissions of the generated directories, then run cms:generate again.',
            ),
            self::GenerateRequiredFieldAdded => $this->refusedInput(
                'A field added to a type that already has a table is required, so its column would be NOT NULL on the rows that exist. Until schema evolution comes (B3), add the field as optional.',
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
            self::GenerateTableChanged => $this->refusedInput(
                'A type\'s type_id, stages or localization differ from its schema lock, which would change its table\'s key or system columns. Until schema evolution comes (B3), undo the change, or define a new type.',
            ),
            self::GenerateTableNameTooLong => $this->refusedInput(
                'A type\'s table name, <owner>__<handle>, is longer than 54 bytes, so the names of its row level security policies would pass Postgres\' limit of 63 bytes. Give the type a shorter handle.',
            ),
            self::GenerateTooManyFields => $this->refusedInput(
                'A type has more than 200 top-level fields, its own and those its extensions add together, and each is a column of the type\'s table (PRD 11.6). Move fields into groups, or split the type.',
            ),
            self::GenerateTypeRemoved => $this->refusedInput(
                'A type whose table has a schema lock in the migrations directory is no longer in the schema. Until schema evolution comes (B3), a type is never removed or renamed: put its blueprint back.',
            ),
            self::GenerateUnknownExtendsTarget => $this->refusedInput(
                'An extension\'s extends names a type_id that no blueprint file below the schema roots defines. Correct the type_id, or add the schema root of the type\'s owner.',
            ),
            self::GenerateUnknownFieldType => $this->refusedInput(
                'A field\'s type is a <namespace>:<handle> that no installed module or addon registers. Correct the type, or install the addon that provides it.',
            ),
            self::GrantEscalationRefused => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'An actor can only give the roles and grants it holds itself, on the nodes where it holds them (PRD 5.10, invariant 31): the grant would give a permission of the role that the issuing actor does not hold on the node in the grant\'s locales, or a classification access above its own there, so nothing was committed. Ask an actor who holds the role\'s permissions on the node to grant it, or grant a role within your own rights.',
            ),
            self::HookBudgetExceeded => new ErrorEntry(
                $this,
                HttpStatus::ServiceUnavailable,
                ExitCode::TempFail,
                McpResponse::InternalError,
                true,
                'A hook of an installed module or addon took longer than its time budget, or the hooks of the command together took longer than 100 ms (PRD 6.3, 13.6), so the command was rejected and nothing was committed. The overrun is recorded with the hook, its package and the time it took. Try again; when it keeps failing, the package that owns the hook has to make it faster or move its work to a subscriber.',
            ),
            self::HookChangeRefused => new ErrorEntry(
                $this,
                HttpStatus::InternalServerError,
                ExitCode::Software,
                McpResponse::InternalError,
                false,
                'A transform hook of an installed module or addon asked to change something a hook may not change: a field its type does not declare, a field above the classification the actor may read, or a variant the plan writes no revision for (PRD 6.2 phase 4, invariant 12). Nothing was committed. This is a bug in the hook; report it to the package the error names.',
            ),
            self::HostNotConfigured => $this->caller(
                HttpStatus::MisdirectedRequest,
                ExitCode::NoHost,
                'No configured site is served at the host the request names, so nothing was resolved (PRD 8.10 point 7). Only the hosts in cbox-cms.sites resolve, and a request\'s own Host or X-Forwarded-Host is never trusted instead. Ask for a host a site is served at, or add the host to its site.',
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
            self::IdempotencyKeyRequired => new ErrorEntry(
                $this,
                HttpStatus::BadRequest,
                ExitCode::Usage,
                McpResponse::ToolError,
                false,
                'A command through REST needs an idempotency key (PRD 6.1), and the request has no Idempotency-Key header, or one that is not 1 to 255 visible ASCII characters. Nothing ran and nothing was committed. Send the command again with a key of your own in Idempotency-Key, and the same key when you repeat it.',
            ),
            self::InstallOwnerConnectionRequired => $this->violation(
                'cms:install creates the installation operator as the owner role, and this process has no owner connection, or the connection it names is not the owner role\'s (PRD 4.2). Run cms:install in the maintenance process, with cbox-cms.database.owner_connection naming the owner role\'s connection.',
            ),
            self::InstallationOperatorMissing => $this->violation(
                'A maintenance command runs as the installation operator, and the installation has none yet (PRD 5.16). Run cms:install in the maintenance process first.',
            ),
            self::JsonInvalid => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The JSON document is well-formed, but it does not hold what its contract version says (GUARDRAILS 2.2): a field is missing, unknown, of the wrong type, classified above the caller\'s classification access, or breaks a rule of its blueprint. The error names the field. Correct that value and send the document again.',
            ),
            self::JsonMalformed => new ErrorEntry(
                $this,
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                McpResponse::ToolError,
                false,
                'The document is not a well-formed JSON object, or an object in it has the same key twice, so none of it was read (GUARDRAILS 2.2). Send one JSON object, encoded as UTF-8, with every key once in each object.',
            ),
            self::LocalAccountExists => $this->caller(
                HttpStatus::Conflict,
                ExitCode::DataErr,
                'A local account was not created: its login identifier, the email address in lower case, is the login of another local account already, or the actor has a local account (PRD 5.16, "Lokale konti"). Every login belongs to one account and every account to one actor. Nothing was written. Use another email address, or reset the password of the account that exists.',
            ),
            self::LocalAccountMissing => $this->caller(
                HttpStatus::NotFound,
                ExitCode::NoUser,
                'The actor has no local account, so its password cannot be changed or reset (PRD 5.16). Nothing was written. An actor that logs in only through a federated connection has no local account.',
            ),
            self::LoginAuthoritativeLink => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'The login was refused by the login policy: it came through the local connection, and the actor is linked to a connection marked authoritative, whose identity provider owns the actor\'s access, so the actor has no local login methods (PRD 5.16, invariant 38). No session was issued. Log in through the authoritative connection; the person is only told that the login failed.',
            ),
            self::LoginClassNotAllowed => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'The login was refused by the login policy: the actor is a service actor, and a service actor never logs in; it only holds service credentials (PRD 5.16). No session was issued. Use the service actor\'s credential, or log in as a person.',
            ),
            self::LoginConnectionNotAllowed => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'The login was refused by the login policy: the login policy of the actor\'s class does not list the connection it came through (PRD 5.16, cbox-cms.identity.policy.<class>.connections). No session was issued. Log in through a connection the policy lists, or add the connection to the policy.',
            ),
            self::LoginFactorsUnavailable => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'The login was refused by the login policy: the policy of the actor\'s class requires factors the login did not give, such as a passkey or two factors for a local staff login, or MFA shown in amr or acr for a federated one (PRD 5.16, cbox-cms.identity.policy.<class>.local_factors, federated_amr and federated_acr). No session was issued. Log in with the factors the policy requires. Only in local and testing may the environment\'s policy allow a password alone for staff.',
            ),
            self::LoginIssuerMismatch => $this->credential(
                'The login was refused: the token came from another issuer than the one its login connection is pinned to (PRD 5.16), so no session was issued and no actor was found or created. A token is trusted only from the issuer its connection names. Log in again through the connection of your organisation; if the issuer of the connection has changed, correct the connection\'s configuration.',
            ),
            self::LoginLocalDisabled => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'The login was refused by the login policy: local login is switched off for the actor\'s class in this environment, so only federated connections give a session (PRD 5.16, cbox-cms.identity.policy.<class>.local_login). No session was issued. Log in through the federated connection of your organisation.',
            ),
            self::LoginMethodNotAllowed => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'The login was refused by the login policy: the policy of the actor\'s class does not allow the login method, or the method does not belong to the connection it came through, such as a password on a federated connection (PRD 5.16, cbox-cms.identity.policy.<class>.methods). No session was issued. Log in with a method the policy allows.',
            ),
            self::LoginPolicyInvalid => $this->violation(
                'The login policy in cbox-cms.identity.policy is invalid: a key is missing or has a value of another form, such as an unknown method, a lifetime below one minute or an inactivity timeout longer than the absolute lifetime, or it lets members of staff log in locally with a password alone in an environment other than local and testing, where PRD 5.16 requires a passkey or two factors. No login is decided while it is invalid, and a process that serves HTTP refuses to boot with it; cms:doctor\'s identity.login_policy says the same. Correct the policy as docs/security/login-policy.md describes it.',
            ),
            self::LoginRateLimited => new ErrorEntry(
                $this,
                HttpStatus::TooManyRequests,
                ExitCode::TempFail,
                McpResponse::ToolError,
                true,
                'The login was refused before any password was checked: too many logins that did not succeed came for the same email address, or from the same IP address, within the window of cbox-cms.identity.login.throttle (PRD 5.16). No session was issued. Wait until the window has passed and log in again; the person is told to wait, never whether the account exists.',
            ),
            self::LoginRejected => $this->credential(
                'The login was refused: the identity provider or the credential check did not accept it, such as a wrong password, an unknown account or an error the provider sent back (PRD 5.16). No session was issued. Check the credentials and log in again; the person is only told that the login failed.',
            ),
            self::LoginStateMismatch => $this->credential(
                'The login was refused: what came back does not belong to the login this browser started, by its state, its connection or its flow (PRD 5.16). It may be a planted or replayed response, or the session that held the pending login has expired. Nothing was checked further and no session was issued. Start the login again from the login page.',
            ),
            self::LoginTenantClaimMissing => $this->credential(
                'The login was refused: the login connection is pinned to a tenant of its issuer, and the token does not carry the claim that names the tenant, such as tid for Microsoft Entra ID or hd for Google (PRD 5.16). The tenant is read only from the token, never from the request, so the login cannot be admitted. Log in with an account of the pinned tenant, such as a Workspace account rather than a personal one.',
            ),
            self::LoginTenantMismatch => $this->credential(
                'The login was refused: the token\'s tenant claim names another tenant than the one the login connection is pinned to (PRD 5.16), so no session was issued. An issuer with several tenants is trusted only for the pinned tenant. Log in with an account of the organisation the connection is for.',
            ),
            self::MaintenanceProcessRequired => $this->violation(
                'The command runs only in the maintenance process (PRD 4.2, 5.16), the console process that has the owner connection and runs the migrations, and this process serves HTTP, runs queued jobs or has no owner connection. Nothing ran. Run it from the console of the maintenance process, with cbox-cms.database.owner_connection naming the owner role\'s connection.',
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
            self::PasswordBreached => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The password was refused: it is known from data breaches, so someone could guess it from the lists of leaked passwords (PRD 5.16, "Lokale konti"). Nothing was written. Choose another password, such as a sentence of several unrelated words, that you have not used anywhere else.',
            ),
            self::PasswordResetTokenInvalid => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The password reset link was refused: its token is unknown, has been used already or has expired (PRD 5.16). Nothing was changed. Ask for a new link from the page that resets a password; a link sets a password once and expires.',
            ),
            self::PasswordTooLong => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The password was refused: it is longer than 1024 bytes in UTF-8, the most a local account takes, so hashing it cannot be used to slow the server down (PRD 5.16). Nothing was written. Choose a shorter password.',
            ),
            self::PasswordTooShort => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The password was refused: it has fewer than 12 characters, the least a local account takes (PRD 5.16, "Lokale konti"). Nothing was written. Choose a longer password, such as a sentence of several unrelated words.',
            ),
            self::PathGone => $this->caller(
                HttpStatus::Gone,
                ExitCode::NoInput,
                'What the path showed was withdrawn (PRD 6.6, invariant 7): the entry is no longer active, or its variant or its placement was taken down, and no scheduled change shows it again. Only a reinstatement does.',
            ),
            self::PathNotFound => $this->caller(
                HttpStatus::NotFound,
                ExitCode::NoInput,
                'Nothing is shown at the path in the language on the site now (PRD 5.9, 6.6): no route, placement or published content answers it, or its window has not opened or has ended. A publication, a new placement or an opening window can show something there later.',
            ),
            self::PlacementSlugTaken => $this->caller(
                HttpStatus::Conflict,
                ExitCode::DataErr,
                'Another placement that is not withdrawn has this slug below the same node in the same language, and a URL must name one placement (PRD 5.9, invariant 15), so nothing was committed. Choose another slug, or change the other placement first.',
            ),
            self::QueryOverBudget => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The read costs more than the budget of its principal, cbox-cms.queries.budgets (PRD 6.2, 8.8), so it was rejected before anything was read. The cost comes from the rows the read may return, how deep it reads and the relations it expands. Ask for fewer rows or a smaller selection, or call with a credential whose budget allows the read.',
            ),
            self::RebuildIdentityInvalid => $this->violation(
                'A rebuild of a type\'s read model runs as a service actor, never as the system (PRD 5.10, 6.5 invariant 21), and cbox-cms.rebuild.service_actor names none, an actor that does not exist or one that is not of class service. Nothing was rebuilt. Create a service actor, grant it a role on the nodes whose entries it rebuilds, and name its id there.',
            ),
            self::RebuildSchemaVersionUnsupported => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'A rebuild of a type\'s read model found a revision or head snapshot written under another schema version than the type\'s current one (PRD 4.1, 11.6, invariant 22). Upcasting a payload to the current version comes with the upcasters, so the rebuild reads payloads at the current version only. The chunk that found it rolled back, and the chunks before it stay rebuilt. Run the rebuild again once the payload can be read at the current version; it resumes at that chunk.',
            ),
            self::RebuildTypeUnknown => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'No type of this installation has the name given to the rebuild, so nothing was rebuilt. Name the type as <owner>:<handle>, the name cms:generate gives it.',
            ),
            self::RegistryCacheMalformed => $this->violation(
                'The registry cache in bootstrap/cache/cms is damaged, or its files come from different builds, so the kernel cannot read its actions, commands, hooks, schema contributions and subscribers. Run cms:build.',
            ),
            self::RegistryCacheMissing => $this->violation(
                'The registry cache in bootstrap/cache/cms does not exist, so the kernel does not know its actions, commands, hooks, schema contributions and subscribers. Run cms:build; Composer runs it after every install.',
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
            self::RegistryDuplicateAction => $this->refusedInput(
                'Two actions handle the same command or query. A command or query has one action: remove the #[Action] of all but one, or give the new shape its own version.',
            ),
            self::RegistryDuplicateCommand => $this->refusedInput(
                'Two classes declare the same command or query name and version with #[Command] or #[Query]. Rename one of them, or give it another version.',
            ),
            self::RegistryDuplicateNamespace => $this->refusedInput(
                'Two addon manifests name the same namespace. A namespace belongs to one addon in the installation, because it holds the addon\'s extension fields, field types and types (PRD 13.1, 13.3). Remove one of the addons.',
            ),
            self::RegistryDuplicatePanelPoint => $this->refusedInput(
                'Two classes declare the same panel point name and version with #[PanelPoint]. A point\'s name and version belong to one props class: give the new props the next version, or rename one of the points.',
            ),
            self::RegistryDuplicateSubscription => $this->refusedInput(
                'Two subscribers declare the same subscription name with #[Subscription]. The event log keeps a subscription\'s cursor under its name, so rename one of them.',
            ),
            self::RegistryIncompatibleCoreApi => $this->refusedInput(
                'An addon manifest needs a version of the kernel\'s API that this kernel does not satisfy: another major version, or a later minor version (PRD 13.1, 13.5). Install a version of the addon made for this kernel\'s API, or a kernel with the API it needs.',
            ),
            self::RegistryInvalidAttribute => $this->refusedInput(
                'The arguments of an #[Action], #[Command], #[Query], #[Hook], #[Subscription] or #[PanelPoint] attribute are invalid, so it cannot be built. Correct the attribute as the cause says.',
            ),
            self::RegistryInvalidManifest => $this->refusedInput(
                'An addon manifest cannot be built, its documentation or schema directory is not a readable directory, or two manifests name one package (PRD 13.1). Correct the manifest the service provider returns from addonManifest(), as the cause says.',
            ),
            self::RegistryInvalidScanRoot => $this->refusedInput(
                'A scan root that a service provider declares is not a readable directory. Correct the directory the provider returns from scanRoots().',
            ),
            self::RegistryNotAConcreteClass => $this->refusedInput(
                'An #[Action], #[Command], #[Query], #[Hook], #[Subscription] or #[PanelPoint] attribute sits on an interface, a trait, an enum or an abstract class. Put it on a concrete class.',
            ),
            self::RegistryNotASubscriber => $this->refusedInput(
                'A #[Subscription] sits on a class that does not implement Subscriber. Implement Cbox\\Cms\\Contracts\\Subscribers\\Subscriber, or remove the attribute.',
            ),
            self::RegistryNotAHook => $this->refusedInput(
                'A #[Hook] sits on a class that does not implement the interface of its phase: AuthorizeHook for authorize, TransformHook for transform and ValidateHook for validate (GUARDRAILS 2.4). Implement the interface, or declare the phase the class implements.',
            ),
            self::RegistryNotAnAction => $this->refusedInput(
                'An #[Action] sits on a class that implements neither WriteAction nor QueryAction, or both (GUARDRAILS 2.1). Implement exactly one of them.',
            ),
            self::RegistryNotFinalReadonly => $this->refusedInput(
                'A #[Command], #[Query], #[Action], #[Subscription] or #[PanelPoint] sits on a class that is not a final readonly class (GUARDRAILS 2.1). Make the class final readonly.',
            ),
            self::RegistryPanelPointWithoutDowncast => $this->refusedInput(
                'A panel point has more than one version, and the props class of an older version does not implement DowncastsFromNewest. The panel builds the props of the newest version only, and an older version keeps working through its declared downcast from them (PRD 13.4). Implement Cbox\\Cms\\Contracts\\PanelPoints\\DowncastsFromNewest on the older props class, with the newest props class as its template.',
            ),
            self::RegistryPanelPointWithoutStability => $this->refusedInput(
                'A #[PanelPoint] class carries none, or more than one, of #[Stable], #[Experimental] and #[Internal]. The attribute on the props class is the point\'s stability (GUARDRAILS 2.3), so give it exactly one.',
            ),
            self::RegistryAddonNotAllowed => $this->refusedInput(
                'An installed addon\'s Composer package is not on the installation\'s allowlist of addons, cbox-cms.addons.allowed (PRD 13.8), or the allowlist is not a list of package names. An addon fails at build, never at run time. Review the addon and add its package to cbox-cms.addons.allowed, or remove the package.',
            ),
            self::RegistryIncompatiblePanelApi => $this->refusedInput(
                'An addon\'s panel contributions need a version of the panel\'s API (PanelContributions::$sdk, read as ^major.minor) that this panel does not satisfy (PRD 13.4). Install a version of the addon made for this panel\'s API, or a panel whose API the addon needs.',
            ),
            self::RegistryPanelActionPrefillInvalid => $this->refusedInput(
                'An action prefills a property of its command from a JSON pointer into the point\'s props, and the pointer is not in the point\'s props schema, the property is not in the command\'s schema, the point or the command has no schema the build can read, or the value the pointer holds is of a type the property does not take. Point the prefill at a value of the props that the property takes.',
            ),
            self::RegistryPanelBundleInvalid => $this->refusedInput(
                'An addon\'s panel bundle does not match: panel-manifest.json is missing or not a document of panel-bundle.v1.json, a file it lists is missing or has another SHA-384, a stylesheet has a rule outside the @layer cms.addon, the entry is not one of its scripts, it imports a module the panel does not share, its contributions differ from the manifest\'s contributions that run code, or the manifest has such contributions and names no bundle (PRD 13.4). Build the addon\'s UI again from its manifest, and do not edit the built files.',
            ),
            self::RegistryPanelCheckUnmirrored => $this->refusedInput(
                'A form check with severity error, or a decorator that tightens the disabled reason, blocks a submit in the panel, and it names no ValidateHook or AuthorizeHook of its own addon on the same command in mirrors (the mirror rule, PRD 13.4); a decorator must also be scoped to that one command. Mirror the rule with a hook that enforces it on the server, so it holds over REST, MCP and the CLI too, or lower the check to a warning.',
            ),
            self::RegistryPanelCommandNotIssuable => $this->refusedInput(
                'An action runs a command that its addon\'s AddonCapabilities::$issues does not list, or a command in issues is not a registered #[Command] whose #[Action] lists Surface::Inertia (PRD 13.4). The panel runs commands through the Inertia profile and offers only what the manifest declares: list the command class in issues, and expose its action on Inertia.',
            ),
            self::RegistryPanelDataQueryInvalid => $this->refusedInput(
                'A slot fill or a page reads its data with a class that is not a registered #[Query] of its own addon, that has no codec, or whose required input the panel cannot take from the point\'s props by name with a type it takes (PRD 13.4). Read through a query of the addon whose input the props give.',
            ),
            self::RegistryPanelDuplicateContribution => $this->refusedInput(
                'A panel contribution\'s id is not in its addon\'s namespace, another contribution in the installation has it too, two pages of one addon have one path, or an addon in the namespace cms, the core\'s own, contributes to the panel (PRD 13.4). Give every contribution an id <namespace>.<local> of its own addon and every page its own path.',
            ),
            self::RegistryPanelExperimentalNotAccepted => $this->refusedInput(
                'A contribution goes to an experimental panel point, which may change in a minor release of the panel\'s API, and its addon\'s PanelContributions::$acceptsExperimental does not list the point (PRD 13.4). Add the point\'s id to acceptsExperimental to opt in, or contribute to a stable point.',
            ),
            self::RegistryPanelFlowPathUnknown => $this->refusedInput(
                'A flow step patches a path its command\'s schema does not have, the command has no schema the build can read, or the path is neither below ext.<namespace> of the step\'s addon nor a path of a command the addon declares (PRD 13.4). The server\'s transform hooks change everything else; patch only the addon\'s own paths.',
            ),
            self::RegistryPanelInternalPoint => $this->refusedInput(
                'A contribution, or an addon\'s acceptsExperimental, names an #[Internal] panel point: the core\'s own wiring, which no addon contributes to (PRD 13.4). Contribute to a stable or experimental point; cms:panel:points lists them with their stability.',
            ),
            self::RegistryPanelKindMismatch => $this->refusedInput(
                'A contribution is of another kind than the panel point it contributes to, such as a SlotFill at an action point (PRD 13.4). Contribute with the class of the point\'s kind, which cms:panel:points shows.',
            ),
            self::RegistryPanelNavTargetUnknown => $this->refusedInput(
                'A nav entry links to a page its addon does not contribute (PRD 13.4). Point NavContribution::$page at the id of a PageContribution of the same addon.',
            ),
            self::RegistryPanelOverrideInvalid => $this->refusedInput(
                'cbox-cms.panel.contributions or cbox-cms.panel.replacements is not of its form, or names a point, contribution or key it does not hold: a priority or enabled for a contribution the point does not have, or a winner that is no replacement of that key (PRD 13.4). Correct the setting against what cms:panel:fills lists.',
            ),
            self::RegistryPanelPointDeprecated => $this->warning(
                'A warning, not a failure: a contribution goes to a deprecated panel point, which is removed in the release the warning names (PRD 13.4). Move the contribution to the point\'s replacement before that release.',
            ),
            self::RegistryPanelPointExperimental => $this->warning(
                'A warning, not a failure: a contribution goes to an experimental panel point that its addon accepts, and the point may change in a minor release of the panel\'s API (PRD 13.4). Check the addon against each release of the panel.',
            ),
            self::RegistryPanelReplacementConflict => $this->refusedInput(
                'Two or more replacements claim one key of a replaceable panel point, and exactly one replacement wins a key (PRD 13.4). Name the winner in cbox-cms.panel.replacements, [point id => [key => contribution id]], or remove all but one.',
            ),
            self::RegistryPanelTighteningUndeclared => $this->refusedInput(
                'A decorator tightens a prop that its panel point does not let decorators tighten (#[PanelPoint] tightens). A decorator may only tighten what its point declares (PRD 13.4); tighten only those props.',
            ),
            self::RegistryPanelUnknownCommand => $this->refusedInput(
                'A form check or flow step is for a command form, or a contribution\'s scope names a command or the permission of a command or query, that no scan root registers (PRD 13.4). Name a registered command and version, `<name>@<version>`, or a registered command\'s or query\'s name.',
            ),
            self::RegistryPanelUnknownPoint => $this->refusedInput(
                'A panel contribution, or an addon\'s acceptsExperimental, names a point id that no #[PanelPoint] declares, or text that is not a point id `<name>@<version>` (PRD 13.4). Name a declared point; cms:panel:points lists them.',
            ),
            self::RegistryPanelUnownedTarget => $this->refusedInput(
                'A replacement replaces a key its addon does not own at a panel point that lets addons replace only their own (Ownership::Own): a field type its manifest does not contribute, or a command or class no scan root of its package declares (PRD 13.4). Replace only the addon\'s own field types, commands and value classes.',
            ),
            self::RegistryReservedNamespace => $this->refusedInput(
                'An addon manifest names the namespace app or ext. The application\'s own fields live under app, and ext holds every extender\'s namespace (PRD 11.12), so neither can be an addon\'s. Give the addon a name of its own.',
            ),
            self::RegistrySurfaceWithoutCodec => $this->refusedInput(
                'An action is exposed on REST, but no codec reads its command or query, so cms:build cannot describe or serve it (GUARDRAILS 2.1, 2.2). Register the command\'s CommandCodec under the container tag cbox-cms.command-codecs, or the query\'s QueryCodec under cbox-cms.query-codecs, each with its JSON Schema, and run cms:build again.',
            ),
            self::RegistryUndeclaredHook => $this->refusedInput(
                'A #[Hook] of an addon\'s package runs for a command and phase that the addon\'s manifest does not allow (PRD 13.1, 6.3). Allow it in the manifest\'s hooks with an AllowedHook, or remove the hook.',
            ),
            self::RegistryUndeclaredSubscriber => $this->refusedInput(
                'A #[Subscription] of an addon\'s package receives an event on a lane that the addon\'s manifest does not allow (PRD 13.1). Allow it in the manifest\'s subscriptions with an AllowedSubscription, or stop receiving the event.',
            ),
            self::RegistryUnknownActionCommand => $this->refusedInput(
                'An action handles a class that is not a registered command (for a WriteAction) or query (for a QueryAction). Point #[Action(handles: ...)] at a class declared with #[Command] or #[Query], or declare the scan root of the package that has it.',
            ),
            self::RegistryUnknownEvent => $this->refusedInput(
                'A #[Subscription] lists an event class that does not exist or does not implement Event, or whose type() fails. List the classes of the events the subscriber receives.',
            ),
            self::RegistryUnknownHookCommand => $this->refusedInput(
                'A hook runs for a command class that no scan root registers. Correct the command the #[Hook] names, or declare the scan root of the package that has it.',
            ),
            self::RegistryUnknownLane => $this->refusedInput(
                'A #[Subscription] names a lane that is not a case of the Lane enum. Name Lane::Critical, Lane::Standard, Lane::External, Lane::Revalidate or Lane::Background.',
            ),
            self::RegistryUnknownSurface => $this->refusedInput(
                'An #[Action] lists a surface that is not a case of the Surface enum. List only Surface::Rest, Surface::Inertia, Surface::Mcp and Surface::Cli.',
            ),
            self::SessionCookieInsecure => $this->violation(
                'The identity module refused to boot a process that serves HTTP, because the session cookie of this environment is not safe outside local and testing (PRD 5.16): it is not Secure, its name lacks the __Host- prefix, or it allows SameSite=None. Run cms:doctor, whose identity.session_cookie says which, and set cbox-cms.identity.session.cookie for the environment to __Host-cms_session with secure true.',
            ),
            self::SessionCookieInvalid => $this->violation(
                'The setting cbox-cms.identity.session.cookie is invalid for this environment, so the identity module cannot set a session cookie (PRD 5.16). Correct the key the message names; cms:doctor\'s identity.session_cookie says the same.',
            ),
            self::RequestHeaderInvalid => new ErrorEntry(
                $this,
                HttpStatus::BadRequest,
                ExitCode::Usage,
                McpResponse::ToolError,
                false,
                'A header of the envelope of a REST command, Cbox-Wait-Level, Cbox-Dry-Run or Cbox-Correlation-Id, does not hold what the envelope allows (PRD 6.1): a wait level other than commit, origin, edge, verified or propagated, a dry run other than true or false, or a correlation id that is not 1 to 128 visible ASCII characters. Nothing ran. Correct the header the error names, or leave it out for its default.',
            ),
            self::ScimInvalidValue => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The SCIM call was refused: a member of the group is not a user of the connection whose token made the call (PRD 5.16, RFC 7644 scimType invalidValue). A connection\'s token reaches only its own users and groups, so a user of another connection, a local account or an unknown id cannot be a member. Nothing was committed. Provision the user through the same connection first.',
            ),
            self::ScimMutability => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The SCIM call was refused: it would change the externalId of a user or group (PRD 5.16, RFC 7644 scimType mutability). A user\'s externalId carries the same immutable value as the connection\'s declared claim of the ID token, sub by default, so it is the key of the IdP identity and never changes. Nothing was committed. Send the externalId the resource has, or delete the resource and create it again.',
            ),
            self::ScimReactivationRefused => $this->caller(
                HttpStatus::Conflict,
                ExitCode::DataErr,
                'The SCIM call was refused: it sets active=true for an actor that another source than this connection deactivated, such as a security event, a local command or the inactivity rule (PRD 5.16). Only the source that deactivated an actor may reactivate it, so the actor stays deactivated and nothing was committed. A person reactivates it with actor.reactivate, with four eyes and step-up.',
            ),
            self::ScimResourceNotFound => $this->caller(
                HttpStatus::NotFound,
                ExitCode::DataErr,
                'The SCIM call was refused: the connection whose token made it has no user or group with the id (PRD 5.16, RFC 7644 3.12). The resource never existed, was deleted, which a repeated DELETE meets, or belongs to another connection, whose resources a token never reaches. Nothing was committed. Look the resource up through the same connection.',
            ),
            self::ScimUniqueness => $this->caller(
                HttpStatus::Conflict,
                ExitCode::DataErr,
                'The SCIM call was refused: another resource of the connection already has the externalId or the userName of the user, or the displayName of the group, compared without regard to case where RFC 7643 says so (PRD 5.16, RFC 7644 scimType uniqueness). A create that repeats the state of the existing resource is not refused; it changes nothing. Nothing was committed. Use the existing resource, or give the new one another value.',
            ),
            self::ScimVersionMismatch => $this->caller(
                HttpStatus::PreconditionFailed,
                ExitCode::DataErr,
                'The SCIM call was refused: its If-Match names another version than the resource\'s current one in the CMS (PRD 5.16, RFC 7644 3.14), because something changed the resource since the identity provider read it. Nothing was committed. Read the resource again and send the change with its current version.',
            ),
            self::SignalAudienceMismatch => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The signal was refused: the token\'s aud claim does not list the audience the connection is pinned to, the CMS\'s client id at the identity provider for a logout token or the stream\'s audience for a security event (PRD 5.16). Nothing was ended or changed. Check the client id or the stream configured at the identity provider.',
            ),
            self::SignalEventUnsupported => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The security event was refused: its event type is not one the core acts on, CAEP session-revoked or credential-change, or RISC account-disabled, account-enabled, account-purged or credential-compromise (PRD 5.16). Nothing was ended or changed. Configure the stream at the transmitter to send only those events.',
            ),
            self::SignalExpired => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The back-channel logout was refused: the logout token\'s exp has passed, beyond the 60 seconds the clocks may differ (PRD 5.16). Nothing was ended. The identity provider sends a new logout token; if it keeps happening, check the clocks of the identity provider and the CMS.',
            ),
            self::SignalIssuedInFuture => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The signal was refused: the token\'s iat is more than 60 seconds after the receiver\'s time (PRD 5.16). Nothing was ended or changed. Check the clocks of the identity provider and the CMS.',
            ),
            self::SignalIssuerMismatch => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The signal was refused: the token\'s iss is not the issuer the connection is pinned to (PRD 5.16), compared exactly, so a back-channel logout or a security event from another issuer ends and changes nothing. Configure the identity provider to send the connection\'s signals, or correct the connection\'s issuer.',
            ),
            self::SignalLogoutEventMissing => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The back-channel logout was refused: the logout token\'s events claim does not hold the event http://schemas.openid.net/event/backchannel-logout, so it is not a logout token (OpenID Connect Back-Channel Logout 1.0, PRD 5.16). Nothing was ended.',
            ),
            self::SignalNoncePresent => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The back-channel logout was refused: the logout token carries a nonce claim, which a logout token never does, so it may be an ID token sent in its place (OpenID Connect Back-Channel Logout 1.0, PRD 5.16). Nothing was ended.',
            ),
            self::SignalReplayed => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The back-channel logout was refused: its logout token\'s jti was received before from the same issuer (PRD 5.16), so the logout has had its effect and a replay ends nothing more. The next login goes to the identity provider in any case.',
            ),
            self::SignalSubjectMissing => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The back-channel logout was refused: the logout token names neither a subject (sub) nor an IdP session (sid), so it says nothing about whose sessions end (OpenID Connect Back-Channel Logout 1.0, PRD 5.16). Nothing was ended.',
            ),
            self::SignalSubjectUnsupported => $this->caller(
                HttpStatus::BadRequest,
                ExitCode::DataErr,
                'The security event was refused: it does not name its subject as an iss_sub of the connection\'s issuer (RFC 9493, PRD 5.16). The core finds an actor only by its IdP identity, the connection, the issuer and the subject, never by an email address or a phone number alone. Nothing was ended or changed. Configure the transmitter to send iss_sub subjects.',
            ),
            self::SiteLocalesDrift => $this->caller(
                HttpStatus::Conflict,
                ExitCode::DataErr,
                'The site is registered already, and the locales cbox-cms.sites configures for it are not the locales it publishes in (PRD 11.14). cms:sites:sync registers sites the database lacks and never rewrites one that exists, so nothing of this site was changed; the other configured sites were still synced. Revert the site\'s locales in the configuration to the ones the error lists, or wait for the locale commands of a later block, which add and remove a site\'s locales through the pipeline.',
            ),
            self::StepUpRequired => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'The command needs step-up, a fresh authentication of a person in an interactive session (PRD 5.16), such as a grant of an administrative role, one whose permissions include grant.*, role.* or actor.deactivate. Nothing was committed. Step-up is not built yet, so such a grant is refused on every surface; the first administrator gets the role from the one-time access bootstrap in the maintenance process.',
            ),
            self::SubscriptionIdentityInvalid => $this->violation(
                'The event runner runs its subscribers as a service actor, never as the system (PRD 6.5 invariant 21), and cbox-cms.events.runner.service_actor names none, an actor that does not exist or one that is not of class service. No event was handled. Create a service actor and name its id there.',
            ),
            self::SubscriptionNotParked => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The aggregate is not parked for the subscription, so there is nothing to release (PRD 7.8). Nothing changed. List the parked aggregates with cms:events:parked and name one of them as <type>:<id>.',
            ),
            self::SubscriptionUnknown => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'No registered subscriber has the subscription named, so nothing was released (PRD 7.6). Check the name against the #[Subscription] of the subscriber, and run cms:build when the subscriber is new.',
            ),
            self::TypeNotReleasable => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The type\'s entries have no revision to release (PRD 4.1, 5.6, 6.4): a type with stages none is public as soon as it is saved, and a type whose history is audit-only or none keeps no revisions for the head to point at. Nothing was committed. Save the entry instead, or give the type stages draft-release and history full.',
            ),
            self::Unauthorized => $this->caller(
                HttpStatus::Forbidden,
                ExitCode::NoPerm,
                'The actor may not run this command on this target, or this read, so the call was rejected: nothing was committed and nothing was read. Ask for the right the command or read needs, or call as an actor that has it.',
            ),
            self::ValidationAboveMaximum => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The value is greater than the largest value its field allows: a number above its max, a date or a time after it. Nothing was committed. Send a value within the field\'s bounds; the error names the field and the bound.',
            ),
            self::ValidationBelowMinimum => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The value is less than the smallest value its field allows: a number below its min, a date or a time before it. Nothing was committed. Send a value within the field\'s bounds; the error names the field and the bound.',
            ),
            self::ValidationDuplicateItem => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'A list that holds each value at most once, such as the choices of a select field with several choices, has a value twice. Nothing was committed. Send each value once.',
            ),
            self::ValidationFailed => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The command\'s content is invalid: a field is missing, has the wrong type or breaks a rule of its blueprint, so nothing was committed. Correct the fields the error lists, then send the command again.',
            ),
            self::ValidationHookFailed => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'A validation hook of an installed module or addon found the value invalid by a rule of its own, beside the rules of the blueprint (PRD 6.2 phase 5). Nothing was committed. Correct the field the error names as its message says, then send the command again.',
            ),
            self::ValidationInvalidFormat => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The text is not in its field\'s format: an email address, or an absolute http or https URL. Nothing was committed. Send the value in the format the error names.',
            ),
            self::ValidationInvalidRichText => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The rich text is not Portable Text as the field holds it (PRD 11.10): each block is an object of the type block with a key of its own and at least one span, each span has a key and its text, and a level belongs to a list item. Nothing was committed. Correct the value at the path the error names.',
            ),
            self::ValidationNotAnOption => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The value is not one of the options of its select field. Nothing was committed. Send one of the options the error lists.',
            ),
            self::ValidationRequired => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The field needs a value, and the input leaves it out or gives null. Nothing was committed. An extension field that its blueprint marks required needs one only when the entry is released (PRD 11.12). Send a value for the field.',
            ),
            self::ValidationRichTextNotAllowed => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The rich text uses a style, a decorator mark, a kind of list or a kind of link that its field does not allow. Nothing was committed. Use only what the field allows; the error lists it.',
            ),
            self::ValidationTooFewItems => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The list has fewer items than its field requires. Nothing was committed. Send at least the number of items the error names.',
            ),
            self::ValidationTooLong => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The text has more characters than its field allows. Nothing was committed. Shorten it to the length the error names.',
            ),
            self::ValidationTooManyDigits => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The decimal number has more digits before or after the point than its field stores, so it would be refused or rounded. Nothing was committed. Send a number with at most the digits the error names.',
            ),
            self::ValidationTooManyItems => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The list has more items than its field allows (PRD 11.6). Nothing was committed. Send at most the number of items the error names.',
            ),
            self::ValidationTooShort => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The text has fewer characters than its field requires. Nothing was committed. Send text of at least the length the error names.',
            ),
            self::ValidationUnknownField => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The input has a field that the type does not have, in the namespace it is given in: the owner\'s fields by handle, an extender\'s under ext and its namespace, a group\'s nested fields by handle. Nothing was committed. Remove the field, or send it where the type declares it.',
            ),
            self::ValidationWrongType => $this->caller(
                HttpStatus::UnprocessableContent,
                ExitCode::DataErr,
                'The value is not of its field\'s type: text, a whole number, a decimal number written as a string, true or false, a date as YYYY-MM-DD, a date-time of RFC 3339 with its offset, an object or a list. Nothing was committed. Send a value of the type the error names.',
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
     * Something a tool tells the installation without failing, such as a warning of cms:build.
     */
    private function warning(string $explanation): ErrorEntry
    {
        return new ErrorEntry($this, HttpStatus::Ok, ExitCode::Ok, McpResponse::Result, false, $explanation);
    }

    /**
     * cms:generate refused a blueprint, or cms:build the declarations of actions, commands, queries
     * and hooks; the schema or the code has to change.
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
