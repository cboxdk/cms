---
title: Error reference
weight: 61
description: Every error code of the kernel, with its HTTP status, CLI exit code and MCP response, whether a retry makes sense, and what to do.
---

# Error reference

`composer docs:errors` writes this page from the error catalog, `Cbox\Cms\Contracts\Errors\ErrorCode`, and `composer docs:check` fails when the page differs from what it writes. Change the catalog and run it again; do not edit the page by hand. [Error codes](../addons/errors.md) describes the catalog and its types.

Every error of the kernel has one of these codes. A code is stable and never renamed. Each surface answers a call that ends with a code as its entry says: the REST API with the HTTP status, the `cms:*` commands with the exit code, and the MCP server with the MCP response. Retry says whether sending the same call again later can succeed.

## Overview

| Code | HTTP | Exit | MCP | Retry |
|---|---|---|---|---|
| [`actor_not_active`](#actor_not_active) | 403 | 77 | tool_error | no |
| [`addon_service_actor_unavailable`](#addon_service_actor_unavailable) | 500 | 78 | internal_error | no |
| [`credential_expired`](#credential_expired) | 401 | 77 | tool_error | no |
| [`credential_malformed`](#credential_malformed) | 401 | 77 | tool_error | no |
| [`credential_revoked`](#credential_revoked) | 401 | 77 | tool_error | no |
| [`credential_unknown`](#credential_unknown) | 401 | 77 | tool_error | no |
| [`doctor_app_role_bypassrls`](#doctor_app_role_bypassrls) | 500 | 78 | internal_error | no |
| [`doctor_app_role_createrole`](#doctor_app_role_createrole) | 500 | 78 | internal_error | no |
| [`doctor_app_role_has_ddl`](#doctor_app_role_has_ddl) | 500 | 78 | internal_error | no |
| [`doctor_app_role_privileged_membership`](#doctor_app_role_privileged_membership) | 500 | 78 | internal_error | no |
| [`doctor_app_role_superuser`](#doctor_app_role_superuser) | 500 | 78 | internal_error | no |
| [`doctor_check_crashed`](#doctor_check_crashed) | 500 | 78 | internal_error | no |
| [`doctor_chromium_missing`](#doctor_chromium_missing) | 503 | 79 | internal_error | no |
| [`doctor_config_invalid`](#doctor_config_invalid) | 500 | 78 | internal_error | no |
| [`doctor_extension_missing`](#doctor_extension_missing) | 500 | 78 | internal_error | no |
| [`doctor_laravel_version`](#doctor_laravel_version) | 500 | 78 | internal_error | no |
| [`doctor_lc_messages_not_english`](#doctor_lc_messages_not_english) | 500 | 78 | internal_error | no |
| [`doctor_node_missing`](#doctor_node_missing) | 503 | 79 | internal_error | no |
| [`doctor_node_version`](#doctor_node_version) | 503 | 79 | internal_error | no |
| [`doctor_owner_credentials_exposed`](#doctor_owner_credentials_exposed) | 503 | 79 | internal_error | no |
| [`doctor_partition_runway_short`](#doctor_partition_runway_short) | 503 | 79 | internal_error | no |
| [`doctor_partition_table_unmanageable`](#doctor_partition_table_unmanageable) | 503 | 79 | internal_error | no |
| [`doctor_php_allow_url_fopen`](#doctor_php_allow_url_fopen) | 500 | 78 | internal_error | no |
| [`doctor_php_version`](#doctor_php_version) | 500 | 78 | internal_error | no |
| [`doctor_playwright_missing`](#doctor_playwright_missing) | 503 | 79 | internal_error | no |
| [`doctor_postgres_query_failed`](#doctor_postgres_query_failed) | 503 | 75 | internal_error | yes |
| [`doctor_postgres_refused`](#doctor_postgres_refused) | 500 | 78 | internal_error | no |
| [`doctor_postgres_unavailable`](#doctor_postgres_unavailable) | 503 | 75 | internal_error | yes |
| [`doctor_postgres_version`](#doctor_postgres_version) | 500 | 78 | internal_error | no |
| [`doctor_prepared_transactions_enabled`](#doctor_prepared_transactions_enabled) | 500 | 78 | internal_error | no |
| [`doctor_registry_cache_damaged`](#doctor_registry_cache_damaged) | 500 | 78 | internal_error | no |
| [`doctor_registry_cache_missing`](#doctor_registry_cache_missing) | 500 | 78 | internal_error | no |
| [`doctor_registry_cache_stale`](#doctor_registry_cache_stale) | 500 | 78 | internal_error | no |
| [`doctor_row_security_not_forced`](#doctor_row_security_not_forced) | 500 | 78 | internal_error | no |
| [`doctor_transaction_timeout_missing`](#doctor_transaction_timeout_missing) | 500 | 78 | internal_error | no |
| [`doctor_valkey_refused`](#doctor_valkey_refused) | 500 | 78 | internal_error | no |
| [`doctor_valkey_unavailable`](#doctor_valkey_unavailable) | 503 | 75 | internal_error | yes |
| [`doctor_vendor_manifest_missing`](#doctor_vendor_manifest_missing) | 500 | 78 | internal_error | no |
| [`dry_run`](#dry_run) | 200 | 0 | result | no |
| [`fake_check_failed`](#fake_check_failed) | 500 | 78 | internal_error | no |
| [`generate_column_name_too_long`](#generate_column_name_too_long) | 500 | 65 | internal_error | no |
| [`generate_duplicate_field_handle`](#generate_duplicate_field_handle) | 500 | 65 | internal_error | no |
| [`generate_duplicate_select_value`](#generate_duplicate_select_value) | 500 | 65 | internal_error | no |
| [`generate_duplicate_type_handle`](#generate_duplicate_type_handle) | 500 | 65 | internal_error | no |
| [`generate_duplicate_type_id`](#generate_duplicate_type_id) | 500 | 65 | internal_error | no |
| [`generate_extension_of_own_type`](#generate_extension_of_own_type) | 500 | 65 | internal_error | no |
| [`generate_extension_version_mismatch`](#generate_extension_version_mismatch) | 500 | 65 | internal_error | no |
| [`generate_invalid_case_name`](#generate_invalid_case_name) | 500 | 65 | internal_error | no |
| [`generate_invalid_config`](#generate_invalid_config) | 500 | 78 | internal_error | no |
| [`generate_invalid_output`](#generate_invalid_output) | 500 | 70 | internal_error | no |
| [`generate_min_above_max`](#generate_min_above_max) | 500 | 65 | internal_error | no |
| [`generate_min_items_above_max_items`](#generate_min_items_above_max_items) | 500 | 65 | internal_error | no |
| [`generate_min_length_above_max_length`](#generate_min_length_above_max_length) | 500 | 65 | internal_error | no |
| [`generate_name_collision`](#generate_name_collision) | 500 | 65 | internal_error | no |
| [`generate_output_unwritable`](#generate_output_unwritable) | 500 | 73 | internal_error | no |
| [`generate_scale_above_precision`](#generate_scale_above_precision) | 500 | 65 | internal_error | no |
| [`generate_schema_invalid`](#generate_schema_invalid) | 500 | 65 | internal_error | no |
| [`generate_schema_missing`](#generate_schema_missing) | 500 | 66 | internal_error | no |
| [`generate_schema_unsupported_version`](#generate_schema_unsupported_version) | 500 | 65 | internal_error | no |
| [`generate_schema_unwritable`](#generate_schema_unwritable) | 500 | 73 | internal_error | no |
| [`generate_too_many_fields`](#generate_too_many_fields) | 500 | 65 | internal_error | no |
| [`generate_unknown_extends_target`](#generate_unknown_extends_target) | 500 | 65 | internal_error | no |
| [`generate_unknown_field_type`](#generate_unknown_field_type) | 500 | 65 | internal_error | no |
| [`hook_budget_exceeded`](#hook_budget_exceeded) | 503 | 75 | internal_error | yes |
| [`hook_change_refused`](#hook_change_refused) | 500 | 70 | internal_error | no |
| [`idempotency_conflict`](#idempotency_conflict) | 409 | 65 | tool_error | no |
| [`idempotency_in_flight`](#idempotency_in_flight) | 409 | 75 | tool_error | yes |
| [`json_invalid`](#json_invalid) | 422 | 65 | tool_error | no |
| [`json_malformed`](#json_malformed) | 400 | 65 | tool_error | no |
| [`owner_credentials_exposed`](#owner_credentials_exposed) | 500 | 78 | internal_error | no |
| [`partition_lock_timeout`](#partition_lock_timeout) | 503 | 75 | internal_error | yes |
| [`partition_missing`](#partition_missing) | 503 | 75 | internal_error | yes |
| [`partition_owner_required`](#partition_owner_required) | 500 | 78 | internal_error | no |
| [`partition_table_unmanageable`](#partition_table_unmanageable) | 500 | 78 | internal_error | no |
| [`registry_cache_malformed`](#registry_cache_malformed) | 500 | 78 | internal_error | no |
| [`registry_cache_missing`](#registry_cache_missing) | 500 | 78 | internal_error | no |
| [`registry_cache_unwritable`](#registry_cache_unwritable) | 500 | 73 | internal_error | no |
| [`registry_class_in_two_roots`](#registry_class_in_two_roots) | 500 | 65 | internal_error | no |
| [`registry_class_not_loadable`](#registry_class_not_loadable) | 500 | 65 | internal_error | no |
| [`registry_duplicate_action`](#registry_duplicate_action) | 500 | 65 | internal_error | no |
| [`registry_duplicate_command`](#registry_duplicate_command) | 500 | 65 | internal_error | no |
| [`registry_duplicate_namespace`](#registry_duplicate_namespace) | 500 | 65 | internal_error | no |
| [`registry_duplicate_subscription`](#registry_duplicate_subscription) | 500 | 65 | internal_error | no |
| [`registry_incompatible_core_api`](#registry_incompatible_core_api) | 500 | 65 | internal_error | no |
| [`registry_invalid_attribute`](#registry_invalid_attribute) | 500 | 65 | internal_error | no |
| [`registry_invalid_manifest`](#registry_invalid_manifest) | 500 | 65 | internal_error | no |
| [`registry_invalid_scan_root`](#registry_invalid_scan_root) | 500 | 65 | internal_error | no |
| [`registry_not_a_concrete_class`](#registry_not_a_concrete_class) | 500 | 65 | internal_error | no |
| [`registry_not_a_hook`](#registry_not_a_hook) | 500 | 65 | internal_error | no |
| [`registry_not_a_subscriber`](#registry_not_a_subscriber) | 500 | 65 | internal_error | no |
| [`registry_not_an_action`](#registry_not_an_action) | 500 | 65 | internal_error | no |
| [`registry_not_final_readonly`](#registry_not_final_readonly) | 500 | 65 | internal_error | no |
| [`registry_reserved_namespace`](#registry_reserved_namespace) | 500 | 65 | internal_error | no |
| [`registry_undeclared_hook`](#registry_undeclared_hook) | 500 | 65 | internal_error | no |
| [`registry_undeclared_subscriber`](#registry_undeclared_subscriber) | 500 | 65 | internal_error | no |
| [`registry_unknown_action_command`](#registry_unknown_action_command) | 500 | 65 | internal_error | no |
| [`registry_unknown_event`](#registry_unknown_event) | 500 | 65 | internal_error | no |
| [`registry_unknown_hook_command`](#registry_unknown_hook_command) | 500 | 65 | internal_error | no |
| [`registry_unknown_lane`](#registry_unknown_lane) | 500 | 65 | internal_error | no |
| [`registry_unknown_surface`](#registry_unknown_surface) | 500 | 65 | internal_error | no |
| [`subscription_identity_invalid`](#subscription_identity_invalid) | 500 | 78 | internal_error | no |
| [`subscription_not_parked`](#subscription_not_parked) | 422 | 65 | tool_error | no |
| [`subscription_unknown`](#subscription_unknown) | 422 | 65 | tool_error | no |
| [`unauthorized`](#unauthorized) | 403 | 77 | tool_error | no |
| [`validation_above_maximum`](#validation_above_maximum) | 422 | 65 | tool_error | no |
| [`validation_below_minimum`](#validation_below_minimum) | 422 | 65 | tool_error | no |
| [`validation_duplicate_item`](#validation_duplicate_item) | 422 | 65 | tool_error | no |
| [`validation_failed`](#validation_failed) | 422 | 65 | tool_error | no |
| [`validation_hook_failed`](#validation_hook_failed) | 422 | 65 | tool_error | no |
| [`validation_invalid_format`](#validation_invalid_format) | 422 | 65 | tool_error | no |
| [`validation_invalid_rich_text`](#validation_invalid_rich_text) | 422 | 65 | tool_error | no |
| [`validation_not_an_option`](#validation_not_an_option) | 422 | 65 | tool_error | no |
| [`validation_required`](#validation_required) | 422 | 65 | tool_error | no |
| [`validation_rich_text_not_allowed`](#validation_rich_text_not_allowed) | 422 | 65 | tool_error | no |
| [`validation_too_few_items`](#validation_too_few_items) | 422 | 65 | tool_error | no |
| [`validation_too_long`](#validation_too_long) | 422 | 65 | tool_error | no |
| [`validation_too_many_digits`](#validation_too_many_digits) | 422 | 65 | tool_error | no |
| [`validation_too_many_items`](#validation_too_many_items) | 422 | 65 | tool_error | no |
| [`validation_too_short`](#validation_too_short) | 422 | 65 | tool_error | no |
| [`validation_unknown_field`](#validation_unknown_field) | 422 | 65 | tool_error | no |
| [`validation_wrong_type`](#validation_wrong_type) | 422 | 65 | tool_error | no |
| [`version_conflict`](#version_conflict) | 409 | 65 | tool_error | no |

## Codes

### actor_not_active

The actor, or an actor it acts on behalf of, is not active (PRD 5.16, invariant 37): its credentials are refused, it runs no command and reads nothing, the subscribers of a service actor do not run, and nothing was committed. Reactivate the actor, or call as an actor that is active.

- HTTP status: 403 Forbidden
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### addon_service_actor_unavailable

An addon's subscriber did not run, because the addon has no active service actor to run as (PRD 13.1, invariant 21): none is configured in cbox-cms.addons.service_actors, no actor has the configured id, or the actor is not an active service actor. It never runs as the system instead. Configure the service actor created when the addon's capabilities were approved, or reactivate it.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### credential_expired

The credential's expiry has passed, so it was refused and nothing was read or committed (PRD 5.16). Every credential has an expiry. Call again with a credential that is still valid.

- HTTP status: 401 Unauthorized
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### credential_malformed

The credential is not in the form of a credential, or its checksum does not match, so it was refused without a lookup (PRD 5.16). Check that the whole token was sent, without spaces or a missing part.

- HTTP status: 401 Unauthorized
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### credential_revoked

The credential was revoked: its actor's credential generation was counted up after it was issued, by a deactivation or a revocation of everything the actor held (PRD 5.16). Nothing was read or committed. Call again with a credential issued after that.

- HTTP status: 401 Unauthorized
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### credential_unknown

No credential has this token, so it was refused and nothing was read or committed (PRD 5.16). Call again with a credential that was issued by this installation.

- HTTP status: 401 Unauthorized
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### doctor_app_role_bypassrls

The app role, which the web and queue processes log in as, has BYPASSRLS, so the row level security that separates the actors does not hold for it (PRD 4.2). As a superuser, run ALTER ROLE <app role> NOBYPASSRLS, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_app_role_createrole

The app role has CREATEROLE, so it could create a role with more rights than it has itself (PRD 4.2). As a superuser, run ALTER ROLE <app role> NOCREATEROLE, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_app_role_has_ddl

The app role can change the schema: it, or a role it is a member of, owns relations, or it has CREATE on the database or a schema (PRD 4.2). Only the owner role runs DDL. Give the relations to the owner role, revoke the membership or the CREATE grant as the cause says, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_app_role_privileged_membership

The app role is a member of a role with rights it must not have: a superuser, a role with BYPASSRLS or CREATEROLE, a role that owns relations, the database or a schema, or a predefined role such as pg_read_all_data or pg_signal_backend (PRD 4.2). Revoke the membership with REVOKE <role> FROM <app role>, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_app_role_superuser

The app role is a superuser, so no privilege and no row level security limits it (PRD 4.2). As a superuser, run ALTER ROLE <app role> NOSUPERUSER, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_check_crashed

A check of cms:doctor did not finish: it threw, answered for another check or returned a skip, so the doctor cannot say whether that part is in order. This is a bug in the check. Report it with the cause, and run cms:doctor again after updating.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_chromium_missing

The browser tests need the Chromium build that the installed Playwright was made for, and it is not installed. Run npx playwright install chromium in the project, and again after the version of Playwright in package.json changes.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_config_invalid

A setting under cbox-cms.doctor, or the environment variable CBOX_CMS_MAINTENANCE_PROCESS, is invalid, or a check it names cannot be used, so cms:doctor cannot run its checks. Correct the setting the cause names, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_extension_missing

A Postgres extension the core's tables need, such as ltree, is not installed in the database, so the migrations have not run against it (PRD 4.2). Run the migrations as the owner role in the maintenance process, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_laravel_version

The installed Laravel is not the major version this cboxdk/cms is built for. Install the Laravel version that composer.json of cboxdk/cms requires, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_lc_messages_not_english

Postgres or the PHP process writes its messages in another language than English, and the kernel recognises some errors of Postgres by their English text, such as a missing partition. Set lc_messages to C for the app role and the owner role and as the server default, and give PHP an English message locale, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_node_missing

Node is not installed, or not on the PATH, and the development tools need it (cms:doctor --dev). Install Node in the version cbox-cms.doctor.node_minimum names or newer.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_node_version

The installed Node is older than cbox-cms.doctor.node_minimum, which the development tools need (cms:doctor --dev). Install a newer Node.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_owner_credentials_exposed

The owner connection, which may change the schema and passes the row level security, is configured in a process that serves HTTP, runs queued jobs, or is not declared the maintenance process (PRD 4.2). Remove the owner connection from that process's configuration, or declare the maintenance process with CBOX_CMS_MAINTENANCE_PROCESS=true.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_partition_runway_short

A partitioned table has partitions for fewer days ahead than cbox-cms.doctor.partition_runway_days, so writes will fail with partition_missing when the runway runs out. Check that the scheduler runs cms:partitions:maintain every hour in the maintenance process, or run it now.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_partition_table_unmanageable

A table in cbox-cms.database.partitions.tables cannot be managed as it is: it is missing, not partitioned by range, or has a DEFAULT partition. Run the migrations, or correct the table or its entry, then run cms:doctor again.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_php_allow_url_fopen

PHP's allow_url_fopen is on, so the file functions can fetch URLs past the guard that all outbound requests go through. Set allow_url_fopen = Off in php.ini, or start PHP with -d allow_url_fopen=0.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_php_version

The PHP version is older than cboxdk/cms needs. Install the PHP version docs/requirements.md names.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_playwright_missing

Playwright is not installed in the project, and the browser tests need it (cms:doctor --dev). Run npm ci in the project.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_postgres_query_failed

The doctor reached Postgres, but a query of a later check failed, for example because the server went away in between. Check that Postgres is running and that the app role may read the system catalogs, then run cms:doctor again.

- HTTP status: 503 Service Unavailable
- CLI exit code: 75 (EX_TEMPFAIL)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: yes, the same call may succeed later

### doctor_postgres_refused

Postgres answered, but refused the app role's login, for example because the password or the database is wrong. Correct the connection settings of the app role, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_postgres_unavailable

The doctor could not reach Postgres in time. Check that Postgres is running and that the host and port of the app role's connection are right, then run cms:doctor again.

- HTTP status: 503 Service Unavailable
- CLI exit code: 75 (EX_TEMPFAIL)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: yes, the same call may succeed later

### doctor_postgres_version

The Postgres server is older than version 17, the lowest version the kernel supports (GUARDRAILS 1.2). Upgrade Postgres.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_prepared_transactions_enabled

Postgres allows prepared transactions (max_prepared_transactions above 0). A prepared transaction that is never finished holds its locks and stops vacuum, and the kernel never uses one (PRD 4.2). Set max_prepared_transactions = 0 and restart Postgres.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_registry_cache_damaged

The registry cache in bootstrap/cache/cms cannot be read: a file is damaged, or the files come from different builds. Run cms:build.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_registry_cache_missing

The registry cache in bootstrap/cache/cms, which cms:build compiles from the installed code, does not exist. Run cms:build.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_registry_cache_stale

The registry cache is older than Composer's last change to vendor/, so it may miss a hook or run one that is gone. Run cms:build.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_row_security_not_forced

A table has row level security enabled but not forced, so its policies do not hold for the table's owner (PRD 4.2). Run ALTER TABLE <table> FORCE ROW LEVEL SECURITY as the owner role, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_transaction_timeout_missing

The app role has no transaction_timeout of its own, so a transaction that hangs holds back the event horizon and vacuum until someone ends it (PRD 4.2). As a superuser, run ALTER ROLE <app role> SET transaction_timeout = '5s', then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_valkey_refused

Valkey answered, but refused the connection, for example because the password is wrong. Correct the settings of the Redis connection cbox-cms.doctor.redis_connection names, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_valkey_unavailable

The doctor could not reach Valkey in time. Check that Valkey is running and that the host and port of the Redis connection are right, then run cms:doctor again.

- HTTP status: 503 Service Unavailable
- CLI exit code: 75 (EX_TEMPFAIL)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: yes, the same call may succeed later

### doctor_vendor_manifest_missing

Composer's vendor/composer/installed.json, or the file cbox-cms.doctor.vendor_manifest names, does not exist, so the doctor cannot tell whether the registry cache is current. Run composer install, or correct the setting.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### dry_run

The command ran as a dry run: the kernel computed the plan and the receipt and committed nothing (PRD 6.1). This is no failure. Send the command again without dry_run to commit it.

- HTTP status: 200 OK
- CLI exit code: 0 (EX_OK)
- MCP: a tool result
- Retry: no, the same call gives the same answer until something changes

### fake_check_failed

A FakeDoctorCheck of the testkit failed, because a test told it to. Only tests see this code.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_column_name_too_long

A field's column name is longer than 63 bytes, the limit Postgres has for a name. The column of an extension field is ext__<namespace>__<handle>. Give the field, or the namespace, a shorter handle.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_duplicate_field_handle

Two fields in one namespace have the same handle: the fields of a type, of a group, or the fields one owner adds to one type. Rename one of them.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_duplicate_select_value

Two options of one select field have the same value. Remove or rename one of them.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_duplicate_type_handle

Two blueprint files of one owner define a type with the same handle. Rename one of the types, or remove one file.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_duplicate_type_id

Two blueprint files define a type with the same type_id, the identity of a type. Give each type its own type_id.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_extension_of_own_type

An extension extends a type of its own owner. Only others extend a type; the owner adds its fields in the type's own file (PRD 11.12, 13.3).

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_extension_version_mismatch

Two extension files of one owner for one type declare different versions. The fields one owner adds to a type share one version: give both files the same one.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_invalid_case_name

A type's owner and handle give no valid PHP enum case, or two types of one owner give the same case. Rename one of the types.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_invalid_config

The configuration under cbox-cms.generators is missing a value or has an invalid one, or a package the generators need is not installed. Correct the setting, or install the package with composer require --dev, as the cause says.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_invalid_output

A generator produced a file outside its directory, or two files with the same path, so nothing was written. This is a bug in the generator. Report it with the cause.

- HTTP status: 500 Internal Server Error
- CLI exit code: 70 (EX_SOFTWARE)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_min_above_max

A field's min is greater than its max. Correct one of them.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_min_items_above_max_items

A field's min_items is greater than its max_items. Correct one of them.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_min_length_above_max_length

A field's min_length is greater than its max_length, or than the default max_length of its type. Correct one of them.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_name_collision

Two fields, options or extender namespaces of one type would get the same name in the type's generated PHP records or DTOs, such as the handles size_1 and size1, or a name PHP reserves; or two classes of the generated DTOs would get the same name. Rename one of them so they differ in more than underscores.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_output_unwritable

A generated file could not be written, or a stale one could not be removed. Check the permissions of the generated directories, then run cms:generate again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 73 (EX_CANTCREAT)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_scale_above_precision

A decimal field's scale is greater than its precision. Correct one of them.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_schema_invalid

A blueprint file is not valid YAML, or not a valid blueprint of the blueprint schema v1. Correct the file at the place the cause names.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_schema_missing

A schema root in cbox-cms.generators.roots, or a blueprint file below one, does not exist or cannot be read. Create the directory, or correct the setting.

- HTTP status: 500 Internal Server Error
- CLI exit code: 66 (EX_NOINPUT)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_schema_unsupported_version

A blueprint file is of a later version than this cboxdk/cms reads, or uses a value it does not know. Update cboxdk/cms.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_schema_unwritable

cms:schema:editor could not write the editor line into a blueprint file. Check the permissions of the file, then run the command again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 73 (EX_CANTCREAT)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_too_many_fields

A type has more than 200 top-level fields, its own and those its extensions add together, and each is a column of the type's table (PRD 11.6). Move fields into groups, or split the type.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_unknown_extends_target

An extension's extends names a type_id that no blueprint file below the schema roots defines. Correct the type_id, or add the schema root of the type's owner.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_unknown_field_type

A field's type is a <namespace>:<handle> that no installed module or addon registers. Correct the type, or install the addon that provides it.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### hook_budget_exceeded

A hook of an installed module or addon took longer than its time budget, or the hooks of the command together took longer than 100 ms (PRD 6.3, 13.6), so the command was rejected and nothing was committed. The overrun is recorded with the hook, its package and the time it took. Try again; when it keeps failing, the package that owns the hook has to make it faster or move its work to a subscriber.

- HTTP status: 503 Service Unavailable
- CLI exit code: 75 (EX_TEMPFAIL)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: yes, the same call may succeed later

### hook_change_refused

A transform hook of an installed module or addon asked to change something a hook may not change: a field its type does not declare, a field above the classification the actor may read, or a variant the plan writes no revision for (PRD 6.2 phase 4, invariant 12). Nothing was committed. This is a bug in the hook; report it to the package the error names.

- HTTP status: 500 Internal Server Error
- CLI exit code: 70 (EX_SOFTWARE)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### idempotency_conflict

The idempotency key was used before with other content (PRD 6.1), so the command was rejected and the first result was left as it was. Use a new key for a new command; send the same key only with the same content.

- HTTP status: 409 Conflict
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### idempotency_in_flight

Another call with the same idempotency key is still running, and it did not finish within the wait budget, cbox-cms.idempotency.wait_budget_ms (PRD 6.1). Nothing was committed by this call. Try again in a moment with the same key and content: you then get the first call's result.

- HTTP status: 409 Conflict
- CLI exit code: 75 (EX_TEMPFAIL)
- MCP: a tool result with isError set
- Retry: yes, the same call may succeed later

### json_invalid

The JSON document is well-formed, but it does not hold what its contract version says (GUARDRAILS 2.2): a field is missing, unknown, of the wrong type, classified above the caller's classification access, or breaks a rule of its blueprint. The error names the field. Correct that value and send the document again.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### json_malformed

The document is not a well-formed JSON object, or an object in it has the same key twice, so none of it was read (GUARDRAILS 2.2). Send one JSON object, encoded as UTF-8, with every key once in each object.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### owner_credentials_exposed

The core refused to boot a process that serves HTTP or runs queued jobs, because the owner connection is configured in it (PRD 4.2). Give the owner connection to the maintenance process alone, which runs the migrations and cms:partitions:maintain.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### partition_lock_timeout

Partition maintenance could not take a lock within its lock_timeout after every attempt, because other transactions held the table. The other tables were still maintained. Run cms:partitions:maintain again; the scheduler runs it every hour.

- HTTP status: 503 Service Unavailable
- CLI exit code: 75 (EX_TEMPFAIL)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: yes, the same call may succeed later

### partition_missing

No partition covers the time a row was written at, so the write was refused and nothing was committed. Partition maintenance has fallen behind: run cms:partitions:maintain in the maintenance process, check that the scheduler runs it every hour, and try again.

- HTTP status: 503 Service Unavailable
- CLI exit code: 75 (EX_TEMPFAIL)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: yes, the same call may succeed later

### partition_owner_required

Partition maintenance ran on a connection that is not the owner connection, cbox-cms.database.owner_connection, and only the owner role may change the partitions. Run cms:partitions:maintain in the maintenance process, which has the owner connection.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### partition_table_unmanageable

A table in cbox-cms.database.partitions.tables cannot be managed as it is: it is missing, not partitioned by range, has a DEFAULT partition, or Postgres refused a step on one of its partitions. The other tables were still maintained. Run the migrations, or correct the table or its entry as the cause says.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_cache_malformed

The registry cache in bootstrap/cache/cms is damaged, or its files come from different builds, so the kernel cannot read its actions, commands, hooks, schema contributions and subscribers. Run cms:build.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_cache_missing

The registry cache in bootstrap/cache/cms does not exist, so the kernel does not know its actions, commands, hooks, schema contributions and subscribers. Run cms:build; Composer runs it after every install.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_cache_unwritable

cms:build could not write the registry cache to bootstrap/cache/cms, or not remove an old file there, or waited too long for another build. Check the permissions of the directory, then run cms:build again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 73 (EX_CANTCREAT)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_class_in_two_roots

Two different scan roots contain the same class, so the registry cannot say which package declares it. Remove the class from one of the scan roots.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_class_not_loadable

A class in a scan root cannot be autoloaded, or loading it failed. Check its namespace against the autoloading rules of its package, and the error in the cause.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_duplicate_action

Two actions handle the same command or query. A command or query has one action: remove the #[Action] of all but one, or give the new shape its own version.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_duplicate_command

Two classes declare the same command or query name and version with #[Command] or #[Query]. Rename one of them, or give it another version.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_duplicate_namespace

Two addon manifests name the same namespace. A namespace belongs to one addon in the installation, because it holds the addon's extension fields, field types and types (PRD 13.1, 13.3). Remove one of the addons.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_duplicate_subscription

Two subscribers declare the same subscription name with #[Subscription]. The event log keeps a subscription's cursor under its name, so rename one of them.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_incompatible_core_api

An addon manifest needs a version of the kernel's API that this kernel does not satisfy: another major version, or a later minor version (PRD 13.1, 13.5). Install a version of the addon made for this kernel's API, or a kernel with the API it needs.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_invalid_attribute

The arguments of an #[Action], #[Command], #[Query], #[Hook] or #[Subscription] attribute are invalid, so it cannot be built. Correct the attribute as the cause says.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_invalid_manifest

An addon manifest cannot be built, its documentation or schema directory is not a readable directory, or two manifests name one package (PRD 13.1). Correct the manifest the service provider returns from addonManifest(), as the cause says.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_invalid_scan_root

A scan root that a service provider declares is not a readable directory. Correct the directory the provider returns from scanRoots().

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_not_a_concrete_class

An #[Action], #[Command], #[Query], #[Hook] or #[Subscription] attribute sits on an interface, a trait, an enum or an abstract class. Put it on a concrete class.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_not_a_hook

A #[Hook] sits on a class that does not implement the interface of its phase: AuthorizeHook for authorize, TransformHook for transform and ValidateHook for validate (GUARDRAILS 2.4). Implement the interface, or declare the phase the class implements.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_not_a_subscriber

A #[Subscription] sits on a class that does not implement Subscriber. Implement Cbox\Cms\Contracts\Subscribers\Subscriber, or remove the attribute.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_not_an_action

An #[Action] sits on a class that implements neither WriteAction nor QueryAction, or both (GUARDRAILS 2.1). Implement exactly one of them.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_not_final_readonly

A #[Command], #[Query], #[Action] or #[Subscription] sits on a class that is not a final readonly class (GUARDRAILS 2.1). Make the class final readonly.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_reserved_namespace

An addon manifest names the namespace app or ext. The application's own fields live under app, and ext holds every extender's namespace (PRD 11.12), so neither can be an addon's. Give the addon a name of its own.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_undeclared_hook

A #[Hook] of an addon's package runs for a command and phase that the addon's manifest does not allow (PRD 13.1, 6.3). Allow it in the manifest's hooks with an AllowedHook, or remove the hook.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_undeclared_subscriber

A #[Subscription] of an addon's package receives an event on a lane that the addon's manifest does not allow (PRD 13.1). Allow it in the manifest's subscriptions with an AllowedSubscription, or stop receiving the event.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_unknown_action_command

An action handles a class that is not a registered command (for a WriteAction) or query (for a QueryAction). Point #[Action(handles: ...)] at a class declared with #[Command] or #[Query], or declare the scan root of the package that has it.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_unknown_event

A #[Subscription] lists an event class that does not exist or does not implement Event, or whose type() fails. List the classes of the events the subscriber receives.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_unknown_hook_command

A hook runs for a command class that no scan root registers. Correct the command the #[Hook] names, or declare the scan root of the package that has it.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_unknown_lane

A #[Subscription] names a lane that is not a case of the Lane enum. Name Lane::Critical, Lane::Standard, Lane::External, Lane::Revalidate or Lane::Background.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_unknown_surface

An #[Action] lists a surface that is not a case of the Surface enum. List only Surface::Rest, Surface::Inertia, Surface::Mcp and Surface::Cli.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### subscription_identity_invalid

The event runner runs its subscribers as a service actor, never as the system (PRD 6.5 invariant 21), and cbox-cms.events.runner.service_actor names none, an actor that does not exist or one that is not of class service. No event was handled. Create a service actor and name its id there.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### subscription_not_parked

The aggregate is not parked for the subscription, so there is nothing to release (PRD 7.8). Nothing changed. List the parked aggregates with cms:events:parked and name one of them as <type>:<id>.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### subscription_unknown

No registered subscriber has the subscription named, so nothing was released (PRD 7.6). Check the name against the #[Subscription] of the subscriber, and run cms:build when the subscriber is new.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### unauthorized

The actor may not run this command on this target, so the command was rejected and nothing was committed. Ask for the right the command needs, or run it as an actor that has it.

- HTTP status: 403 Forbidden
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_above_maximum

The value is greater than the largest value its field allows: a number above its max, a date or a time after it. Nothing was committed. Send a value within the field's bounds; the error names the field and the bound.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_below_minimum

The value is less than the smallest value its field allows: a number below its min, a date or a time before it. Nothing was committed. Send a value within the field's bounds; the error names the field and the bound.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_duplicate_item

A list that holds each value at most once, such as the choices of a select field with several choices, has a value twice. Nothing was committed. Send each value once.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_failed

The command's content is invalid: a field is missing, has the wrong type or breaks a rule of its blueprint, so nothing was committed. Correct the fields the error lists, then send the command again.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_hook_failed

A validation hook of an installed module or addon found the value invalid by a rule of its own, beside the rules of the blueprint (PRD 6.2 phase 5). Nothing was committed. Correct the field the error names as its message says, then send the command again.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_invalid_format

The text is not in its field's format: an email address, or an absolute http or https URL. Nothing was committed. Send the value in the format the error names.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_invalid_rich_text

The rich text is not Portable Text as the field holds it (PRD 11.10): each block is an object of the type block with a key of its own and at least one span, each span has a key and its text, and a level belongs to a list item. Nothing was committed. Correct the value at the path the error names.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_not_an_option

The value is not one of the options of its select field. Nothing was committed. Send one of the options the error lists.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_required

The field needs a value, and the input leaves it out or gives null. Nothing was committed. An extension field that its blueprint marks required needs one only when the entry is released (PRD 11.12). Send a value for the field.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_rich_text_not_allowed

The rich text uses a style, a decorator mark, a kind of list or a kind of link that its field does not allow. Nothing was committed. Use only what the field allows; the error lists it.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_too_few_items

The list has fewer items than its field requires. Nothing was committed. Send at least the number of items the error names.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_too_long

The text has more characters than its field allows. Nothing was committed. Shorten it to the length the error names.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_too_many_digits

The decimal number has more digits before or after the point than its field stores, so it would be refused or rounded. Nothing was committed. Send a number with at most the digits the error names.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_too_many_items

The list has more items than its field allows (PRD 11.6). Nothing was committed. Send at most the number of items the error names.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_too_short

The text has fewer characters than its field requires. Nothing was committed. Send text of at least the length the error names.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_unknown_field

The input has a field that the type does not have, in the namespace it is given in: the owner's fields by handle, an extender's under ext and its namespace, a group's nested fields by handle. Nothing was committed. Remove the field, or send it where the type declares it.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### validation_wrong_type

The value is not of its field's type: text, a whole number, a decimal number written as a string, true or false, a date as YYYY-MM-DD, a date-time of RFC 3339 with its offset, an object or a list. Nothing was committed. Send a value of the type the error names.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### version_conflict

The target changed after the caller read it: its version is not the version the command expected, so nothing was committed and nothing was overwritten (PRD 6.1). Read the target again, apply the change to what is there now, and send the command with the new version.

- HTTP status: 409 Conflict
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes
