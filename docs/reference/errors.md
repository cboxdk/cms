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
| [`access_bootstrap_done`](#access_bootstrap_done) | 409 | 77 | tool_error | no |
| [`access_bootstrap_production`](#access_bootstrap_production) | 403 | 77 | tool_error | no |
| [`access_bootstrap_role_conflict`](#access_bootstrap_role_conflict) | 409 | 65 | tool_error | no |
| [`actor_not_active`](#actor_not_active) | 403 | 77 | tool_error | no |
| [`addon_service_actor_unavailable`](#addon_service_actor_unavailable) | 500 | 78 | internal_error | no |
| [`agent_visibility_forbidden`](#agent_visibility_forbidden) | 403 | 77 | tool_error | no |
| [`breached_passwords_unavailable`](#breached_passwords_unavailable) | 503 | 75 | internal_error | yes |
| [`credential_expired`](#credential_expired) | 401 | 77 | tool_error | no |
| [`credential_malformed`](#credential_malformed) | 401 | 77 | tool_error | no |
| [`credential_not_allowed`](#credential_not_allowed) | 401 | 77 | tool_error | no |
| [`credential_revoked`](#credential_revoked) | 401 | 77 | tool_error | no |
| [`credential_unknown`](#credential_unknown) | 401 | 77 | tool_error | no |
| [`doctor_app_role_bypassrls`](#doctor_app_role_bypassrls) | 500 | 78 | internal_error | no |
| [`doctor_app_role_createrole`](#doctor_app_role_createrole) | 500 | 78 | internal_error | no |
| [`doctor_app_role_has_ddl`](#doctor_app_role_has_ddl) | 500 | 78 | internal_error | no |
| [`doctor_app_role_privileged_membership`](#doctor_app_role_privileged_membership) | 500 | 78 | internal_error | no |
| [`doctor_app_role_superuser`](#doctor_app_role_superuser) | 500 | 78 | internal_error | no |
| [`doctor_argon2id_unavailable`](#doctor_argon2id_unavailable) | 500 | 78 | internal_error | no |
| [`doctor_check_crashed`](#doctor_check_crashed) | 500 | 78 | internal_error | no |
| [`doctor_chromium_missing`](#doctor_chromium_missing) | 503 | 79 | internal_error | no |
| [`doctor_config_invalid`](#doctor_config_invalid) | 500 | 78 | internal_error | no |
| [`doctor_credential_store_missing`](#doctor_credential_store_missing) | 500 | 78 | internal_error | no |
| [`doctor_credential_store_readable`](#doctor_credential_store_readable) | 500 | 78 | internal_error | no |
| [`doctor_event_log_unreadable`](#doctor_event_log_unreadable) | 503 | 79 | internal_error | no |
| [`doctor_events_lag`](#doctor_events_lag) | 503 | 79 | internal_error | no |
| [`doctor_events_parked`](#doctor_events_parked) | 503 | 79 | internal_error | no |
| [`doctor_extension_missing`](#doctor_extension_missing) | 500 | 78 | internal_error | no |
| [`doctor_horizon_held`](#doctor_horizon_held) | 503 | 79 | internal_error | no |
| [`doctor_identity_connection_refused`](#doctor_identity_connection_refused) | 500 | 78 | internal_error | no |
| [`doctor_identity_connection_shared_role`](#doctor_identity_connection_shared_role) | 500 | 78 | internal_error | no |
| [`doctor_identity_connection_unavailable`](#doctor_identity_connection_unavailable) | 503 | 75 | internal_error | yes |
| [`doctor_identity_role_privileged`](#doctor_identity_role_privileged) | 500 | 78 | internal_error | no |
| [`doctor_idle_in_transaction_timeout_missing`](#doctor_idle_in_transaction_timeout_missing) | 500 | 78 | internal_error | no |
| [`doctor_laravel_version`](#doctor_laravel_version) | 500 | 78 | internal_error | no |
| [`doctor_lc_messages_not_english`](#doctor_lc_messages_not_english) | 500 | 78 | internal_error | no |
| [`doctor_login_policy_invalid`](#doctor_login_policy_invalid) | 500 | 78 | internal_error | no |
| [`doctor_node_missing`](#doctor_node_missing) | 503 | 79 | internal_error | no |
| [`doctor_node_version`](#doctor_node_version) | 503 | 79 | internal_error | no |
| [`doctor_operator_invalid`](#doctor_operator_invalid) | 503 | 79 | internal_error | no |
| [`doctor_operator_missing`](#doctor_operator_missing) | 503 | 79 | internal_error | no |
| [`doctor_operator_unreadable`](#doctor_operator_unreadable) | 503 | 79 | internal_error | no |
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
| [`doctor_session_cookie_insecure`](#doctor_session_cookie_insecure) | 500 | 78 | internal_error | no |
| [`doctor_session_cookie_invalid`](#doctor_session_cookie_invalid) | 500 | 78 | internal_error | no |
| [`doctor_snapshot_held`](#doctor_snapshot_held) | 503 | 79 | internal_error | no |
| [`doctor_transaction_timeout_missing`](#doctor_transaction_timeout_missing) | 500 | 78 | internal_error | no |
| [`doctor_valkey_refused`](#doctor_valkey_refused) | 500 | 78 | internal_error | no |
| [`doctor_valkey_unavailable`](#doctor_valkey_unavailable) | 503 | 75 | internal_error | yes |
| [`doctor_vendor_manifest_missing`](#doctor_vendor_manifest_missing) | 500 | 78 | internal_error | no |
| [`dry_run`](#dry_run) | 200 | 0 | result | no |
| [`egress_blocked`](#egress_blocked) | 422 | 65 | tool_error | no |
| [`egress_guard_disabled`](#egress_guard_disabled) | 500 | 78 | internal_error | no |
| [`egress_mail_failed`](#egress_mail_failed) | 503 | 75 | internal_error | yes |
| [`egress_redirect_refused`](#egress_redirect_refused) | 503 | 69 | internal_error | no |
| [`egress_unavailable`](#egress_unavailable) | 503 | 75 | internal_error | yes |
| [`fake_check_failed`](#fake_check_failed) | 500 | 78 | internal_error | no |
| [`field_encryption_unavailable`](#field_encryption_unavailable) | 422 | 65 | tool_error | no |
| [`generate_column_name_too_long`](#generate_column_name_too_long) | 500 | 65 | internal_error | no |
| [`generate_duplicate_field_handle`](#generate_duplicate_field_handle) | 500 | 65 | internal_error | no |
| [`generate_duplicate_select_value`](#generate_duplicate_select_value) | 500 | 65 | internal_error | no |
| [`generate_duplicate_type_handle`](#generate_duplicate_type_handle) | 500 | 65 | internal_error | no |
| [`generate_duplicate_type_id`](#generate_duplicate_type_id) | 500 | 65 | internal_error | no |
| [`generate_extension_of_own_type`](#generate_extension_of_own_type) | 500 | 65 | internal_error | no |
| [`generate_extension_version_mismatch`](#generate_extension_version_mismatch) | 500 | 65 | internal_error | no |
| [`generate_field_changed`](#generate_field_changed) | 500 | 65 | internal_error | no |
| [`generate_field_not_queryable`](#generate_field_not_queryable) | 500 | 65 | internal_error | no |
| [`generate_field_removed`](#generate_field_removed) | 500 | 65 | internal_error | no |
| [`generate_invalid_case_name`](#generate_invalid_case_name) | 500 | 65 | internal_error | no |
| [`generate_invalid_config`](#generate_invalid_config) | 500 | 78 | internal_error | no |
| [`generate_invalid_output`](#generate_invalid_output) | 500 | 70 | internal_error | no |
| [`generate_lock_invalid`](#generate_lock_invalid) | 500 | 65 | internal_error | no |
| [`generate_min_above_max`](#generate_min_above_max) | 500 | 65 | internal_error | no |
| [`generate_min_items_above_max_items`](#generate_min_items_above_max_items) | 500 | 65 | internal_error | no |
| [`generate_min_length_above_max_length`](#generate_min_length_above_max_length) | 500 | 65 | internal_error | no |
| [`generate_name_collision`](#generate_name_collision) | 500 | 65 | internal_error | no |
| [`generate_output_unwritable`](#generate_output_unwritable) | 500 | 73 | internal_error | no |
| [`generate_required_field_added`](#generate_required_field_added) | 500 | 65 | internal_error | no |
| [`generate_scale_above_precision`](#generate_scale_above_precision) | 500 | 65 | internal_error | no |
| [`generate_schema_invalid`](#generate_schema_invalid) | 500 | 65 | internal_error | no |
| [`generate_schema_missing`](#generate_schema_missing) | 500 | 66 | internal_error | no |
| [`generate_schema_unsupported_version`](#generate_schema_unsupported_version) | 500 | 65 | internal_error | no |
| [`generate_schema_unwritable`](#generate_schema_unwritable) | 500 | 73 | internal_error | no |
| [`generate_table_changed`](#generate_table_changed) | 500 | 65 | internal_error | no |
| [`generate_table_name_too_long`](#generate_table_name_too_long) | 500 | 65 | internal_error | no |
| [`generate_too_many_fields`](#generate_too_many_fields) | 500 | 65 | internal_error | no |
| [`generate_type_removed`](#generate_type_removed) | 500 | 65 | internal_error | no |
| [`generate_unknown_extends_target`](#generate_unknown_extends_target) | 500 | 65 | internal_error | no |
| [`generate_unknown_field_type`](#generate_unknown_field_type) | 500 | 65 | internal_error | no |
| [`grant_escalation_refused`](#grant_escalation_refused) | 403 | 77 | tool_error | no |
| [`hook_budget_exceeded`](#hook_budget_exceeded) | 503 | 75 | internal_error | yes |
| [`hook_change_refused`](#hook_change_refused) | 500 | 70 | internal_error | no |
| [`host_not_configured`](#host_not_configured) | 421 | 68 | tool_error | no |
| [`idempotency_conflict`](#idempotency_conflict) | 409 | 65 | tool_error | no |
| [`idempotency_in_flight`](#idempotency_in_flight) | 409 | 75 | tool_error | yes |
| [`idempotency_key_required`](#idempotency_key_required) | 400 | 64 | tool_error | no |
| [`install_owner_connection_required`](#install_owner_connection_required) | 500 | 78 | internal_error | no |
| [`installation_operator_missing`](#installation_operator_missing) | 500 | 78 | internal_error | no |
| [`json_invalid`](#json_invalid) | 422 | 65 | tool_error | no |
| [`json_malformed`](#json_malformed) | 400 | 65 | tool_error | no |
| [`local_account_exists`](#local_account_exists) | 409 | 65 | tool_error | no |
| [`local_account_missing`](#local_account_missing) | 404 | 67 | tool_error | no |
| [`login_authoritative_link`](#login_authoritative_link) | 403 | 77 | tool_error | no |
| [`login_class_not_allowed`](#login_class_not_allowed) | 403 | 77 | tool_error | no |
| [`login_connection_not_allowed`](#login_connection_not_allowed) | 403 | 77 | tool_error | no |
| [`login_factors_unavailable`](#login_factors_unavailable) | 403 | 77 | tool_error | no |
| [`login_issuer_mismatch`](#login_issuer_mismatch) | 401 | 77 | tool_error | no |
| [`login_local_disabled`](#login_local_disabled) | 403 | 77 | tool_error | no |
| [`login_method_not_allowed`](#login_method_not_allowed) | 403 | 77 | tool_error | no |
| [`login_policy_invalid`](#login_policy_invalid) | 500 | 78 | internal_error | no |
| [`login_rate_limited`](#login_rate_limited) | 429 | 75 | tool_error | yes |
| [`login_rejected`](#login_rejected) | 401 | 77 | tool_error | no |
| [`login_state_mismatch`](#login_state_mismatch) | 401 | 77 | tool_error | no |
| [`login_tenant_claim_missing`](#login_tenant_claim_missing) | 401 | 77 | tool_error | no |
| [`login_tenant_mismatch`](#login_tenant_mismatch) | 401 | 77 | tool_error | no |
| [`maintenance_process_required`](#maintenance_process_required) | 500 | 78 | internal_error | no |
| [`owner_credentials_exposed`](#owner_credentials_exposed) | 500 | 78 | internal_error | no |
| [`partition_lock_timeout`](#partition_lock_timeout) | 503 | 75 | internal_error | yes |
| [`partition_missing`](#partition_missing) | 503 | 75 | internal_error | yes |
| [`partition_owner_required`](#partition_owner_required) | 500 | 78 | internal_error | no |
| [`partition_table_unmanageable`](#partition_table_unmanageable) | 500 | 78 | internal_error | no |
| [`password_breached`](#password_breached) | 422 | 65 | tool_error | no |
| [`password_reset_token_invalid`](#password_reset_token_invalid) | 400 | 65 | tool_error | no |
| [`password_too_long`](#password_too_long) | 422 | 65 | tool_error | no |
| [`password_too_short`](#password_too_short) | 422 | 65 | tool_error | no |
| [`path_gone`](#path_gone) | 410 | 66 | tool_error | no |
| [`path_not_found`](#path_not_found) | 404 | 66 | tool_error | no |
| [`placement_slug_taken`](#placement_slug_taken) | 409 | 65 | tool_error | no |
| [`query_over_budget`](#query_over_budget) | 422 | 65 | tool_error | no |
| [`rebuild_identity_invalid`](#rebuild_identity_invalid) | 500 | 78 | internal_error | no |
| [`rebuild_schema_version_unsupported`](#rebuild_schema_version_unsupported) | 422 | 65 | tool_error | no |
| [`rebuild_type_unknown`](#rebuild_type_unknown) | 422 | 65 | tool_error | no |
| [`registry_cache_malformed`](#registry_cache_malformed) | 500 | 78 | internal_error | no |
| [`registry_cache_missing`](#registry_cache_missing) | 500 | 78 | internal_error | no |
| [`registry_cache_unwritable`](#registry_cache_unwritable) | 500 | 73 | internal_error | no |
| [`registry_class_in_two_roots`](#registry_class_in_two_roots) | 500 | 65 | internal_error | no |
| [`registry_class_not_loadable`](#registry_class_not_loadable) | 500 | 65 | internal_error | no |
| [`registry_duplicate_action`](#registry_duplicate_action) | 500 | 65 | internal_error | no |
| [`registry_duplicate_command`](#registry_duplicate_command) | 500 | 65 | internal_error | no |
| [`registry_duplicate_namespace`](#registry_duplicate_namespace) | 500 | 65 | internal_error | no |
| [`registry_duplicate_panel_point`](#registry_duplicate_panel_point) | 500 | 65 | internal_error | no |
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
| [`registry_panel_point_without_stability`](#registry_panel_point_without_stability) | 500 | 65 | internal_error | no |
| [`registry_reserved_namespace`](#registry_reserved_namespace) | 500 | 65 | internal_error | no |
| [`registry_surface_without_codec`](#registry_surface_without_codec) | 500 | 65 | internal_error | no |
| [`registry_undeclared_hook`](#registry_undeclared_hook) | 500 | 65 | internal_error | no |
| [`registry_undeclared_subscriber`](#registry_undeclared_subscriber) | 500 | 65 | internal_error | no |
| [`registry_unknown_action_command`](#registry_unknown_action_command) | 500 | 65 | internal_error | no |
| [`registry_unknown_event`](#registry_unknown_event) | 500 | 65 | internal_error | no |
| [`registry_unknown_hook_command`](#registry_unknown_hook_command) | 500 | 65 | internal_error | no |
| [`registry_unknown_lane`](#registry_unknown_lane) | 500 | 65 | internal_error | no |
| [`registry_unknown_surface`](#registry_unknown_surface) | 500 | 65 | internal_error | no |
| [`request_header_invalid`](#request_header_invalid) | 400 | 64 | tool_error | no |
| [`scim_invalid_value`](#scim_invalid_value) | 400 | 65 | tool_error | no |
| [`scim_mutability`](#scim_mutability) | 400 | 65 | tool_error | no |
| [`scim_reactivation_refused`](#scim_reactivation_refused) | 409 | 65 | tool_error | no |
| [`scim_resource_not_found`](#scim_resource_not_found) | 404 | 65 | tool_error | no |
| [`scim_uniqueness`](#scim_uniqueness) | 409 | 65 | tool_error | no |
| [`scim_version_mismatch`](#scim_version_mismatch) | 412 | 65 | tool_error | no |
| [`session_cookie_insecure`](#session_cookie_insecure) | 500 | 78 | internal_error | no |
| [`session_cookie_invalid`](#session_cookie_invalid) | 500 | 78 | internal_error | no |
| [`signal_audience_mismatch`](#signal_audience_mismatch) | 400 | 65 | tool_error | no |
| [`signal_event_unsupported`](#signal_event_unsupported) | 400 | 65 | tool_error | no |
| [`signal_expired`](#signal_expired) | 400 | 65 | tool_error | no |
| [`signal_issued_in_future`](#signal_issued_in_future) | 400 | 65 | tool_error | no |
| [`signal_issuer_mismatch`](#signal_issuer_mismatch) | 400 | 65 | tool_error | no |
| [`signal_logout_event_missing`](#signal_logout_event_missing) | 400 | 65 | tool_error | no |
| [`signal_nonce_present`](#signal_nonce_present) | 400 | 65 | tool_error | no |
| [`signal_replayed`](#signal_replayed) | 400 | 65 | tool_error | no |
| [`signal_subject_missing`](#signal_subject_missing) | 400 | 65 | tool_error | no |
| [`signal_subject_unsupported`](#signal_subject_unsupported) | 400 | 65 | tool_error | no |
| [`site_locales_drift`](#site_locales_drift) | 409 | 65 | tool_error | no |
| [`step_up_required`](#step_up_required) | 403 | 77 | tool_error | no |
| [`subscription_identity_invalid`](#subscription_identity_invalid) | 500 | 78 | internal_error | no |
| [`subscription_not_parked`](#subscription_not_parked) | 422 | 65 | tool_error | no |
| [`subscription_unknown`](#subscription_unknown) | 422 | 65 | tool_error | no |
| [`type_not_releasable`](#type_not_releasable) | 422 | 65 | tool_error | no |
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

### access_bootstrap_done

The one-time access bootstrap was refused, because a staff member holds a grant already (PRD 5.10, 5.16), so it has done its work once. Nothing was committed. Give further access in the panel as a staff member whose roles allow it; the escalation guard holds every grant to what its issuer has.

- HTTP status: 409 Conflict
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### access_bootstrap_production

The one-time access bootstrap does not run in the production environment (PRD 5.10): full access in production comes only from a role that only actors on the emergency access list can assign, and that list is not built yet. Nothing was committed. Bootstrap access in an environment that is not production.

- HTTP status: 403 Forbidden
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### access_bootstrap_role_conflict

The one-time access bootstrap was refused, because a role has the handle in cbox-cms.access.bootstrap_role already and it is not the full role the bootstrap grants: its ceiling is below sensitive, or it lacks a command or query of the registry (PRD 5.10). Nothing was committed. Name another handle in cbox-cms.access.bootstrap_role.

- HTTP status: 409 Conflict
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

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

### agent_visibility_forbidden

An agent or a token may not make content public (invariant 18): the command would release a revision, open a placement's window or otherwise change what the public sees, so nothing was committed. An agent can prepare the change, such as a hidden placement or a draft; a person makes it public.

- HTTP status: 403 Forbidden
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### breached_passwords_unavailable

Whether the password is known from data breaches could not be checked, because the service the check asks did not answer or answered something it could not read (PRD 5.16). The check fails closed, so the password was neither accepted nor refused as breached, and nothing was committed. Try again in a moment; if it keeps failing, check the counters cms.egress.failures and cms.identity.breached_passwords.checks.

- HTTP status: 503 Service Unavailable
- CLI exit code: 75 (EX_TEMPFAIL)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: yes, the same call may succeed later

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

### credential_not_allowed

The session was refused because the login policy of this environment no longer allows how it was obtained: its actor class, its login connection or its login method is no longer allowed, or local login was switched off (PRD 5.16). Nothing was read or committed. Log in again through a way the policy allows.

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

### doctor_argon2id_unavailable

This PHP has no Argon2id (PASSWORD_ARGON2ID), which the local accounts hash their passwords with (PRD 5.16). Install PHP with libargon2 or libsodium support, such as the php-baseimages images, then run cms:doctor again.

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

### doctor_credential_store_missing

The schema cms_identity of the credential store does not exist in the database, so the local accounts have nowhere to keep their credentials (PRD 5.16). Create it as the owner role with the identity role's grants, as docs/security/credential-store.md says, run the migrations, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_credential_store_readable

The app role has a privilege on the credential store, the schema cms_identity or one of its tables, so the web and queue processes could read or change credentials (PRD 5.16). As the owner role or a superuser, revoke what the cause names from the app role and from PUBLIC, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_event_log_unreadable

The doctor could not read the event log's cursors, events or parked aggregates, or the subscriptions of the registry cache, so it cannot say how far the subscribers are behind (PRD 7.12). Check that the core's migrations have run and that cms:build has written the registry cache, then run cms:doctor again.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_events_lag

A subscription has an event it has not handled that is older than the lag target of its lane, such as 500 ms for the critical lane (PRD 7.6, 7.12). Either no runner is running the lane (cms:events:run), or a transaction that is still open holds back the transaction horizon below which the runners read (PRD 7.4). Start the lane's runner, or end the transaction that postgres.oldest_xact names.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_events_parked

A subscription has parked aggregates: events it failed to handle as often as it may, whose later events wait with them (PRD 7.8). List them with cms:events:parked, fix the cause the subscriber failed on, and release them with cms:events:release.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_extension_missing

A Postgres extension the core's tables need, such as ltree, is not installed in the database, so the migrations have not run against it (PRD 4.2). Run the migrations as the owner role in the maintenance process, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_horizon_held

A transaction has held a transaction id for longer than the command budget, so the transaction horizon, below which the event runners read, cannot pass it and no subscriber sees the events committed after it began (PRD 4.2, 7.4). End the transaction the cause names, with pg_terminate_backend(<pid>) as its role or a superuser if it hangs, and find what keeps it open.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_identity_connection_refused

Postgres answered, but refused the login of the identity connection, which cbox-cms.identity.connection names, or the connection is not configured as Postgres. Correct the connection's settings and the identity role's password, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_identity_connection_shared_role

The identity connection logs in as the app role or the owner role, so the credential store is not isolated from the processes that use that role (PRD 5.16). Give the identity connection a role of its own, as docs/security/credential-store.md says, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_identity_connection_unavailable

The doctor could not reach Postgres in time on the identity connection, which cbox-cms.identity.connection names. Check that Postgres is running and that the host and port of the connection are right, then run cms:doctor again.

- HTTP status: 503 Service Unavailable
- CLI exit code: 75 (EX_TEMPFAIL)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: yes, the same call may succeed later

### doctor_identity_role_privileged

The identity role is a superuser, has BYPASSRLS or CREATEROLE, or is a member of a role with more power, so it reaches more than the credential store (PRD 5.16, 4.2). As a superuser, take the attribute or the membership the cause names away, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_idle_in_transaction_timeout_missing

The app role has no idle_in_transaction_session_timeout of its own, so a session that begins a transaction and then waits holds its locks, the event horizon and vacuum until something else ends it (PRD 7.4). As a superuser, run ALTER ROLE <app role> SET idle_in_transaction_session_timeout = '5s', then run cms:doctor again.

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

### doctor_login_policy_invalid

The login policy of this environment, cbox-cms.identity.policy, cannot be used: a key is missing or has a value of another form, or the policy lets members of staff log in locally with a password alone in an environment other than local and testing, where PRD 5.16 requires a passkey or two factors. The web processes refuse to boot with it. Correct the key the cause names, as docs/security/login-policy.md describes it, then run cms:doctor again.

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

### doctor_operator_invalid

The installation operator, the service actor the maintenance commands run as, is not an active service actor (PRD 5.16), so no maintenance command can run. Find out who changed it from the audit; an operator is created once, by cms:install.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_operator_missing

The installation has no operator yet, the service actor the maintenance commands run as (PRD 5.16). Run cms:install in the maintenance process, after the migrations and cms:partitions:maintain.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_operator_unreadable

The doctor could not read the installation operator, usually because the core's migrations have not run. Run them as the owner role in the maintenance process, then run cms:doctor again.

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

### doctor_session_cookie_insecure

The session cookie of this environment is not safe for an environment other than local and testing (PRD 5.16): it is not Secure, its name lacks the __Host- prefix, or it allows SameSite=None. The web processes refuse to boot with it. Set cbox-cms.identity.session.cookie for the environment to the name __Host-cms_session with secure true and same_site lax or strict, or remove the entry so the default applies, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_session_cookie_invalid

The setting cbox-cms.identity.session.cookie is invalid for this environment, so no session cookie can be set: a name that is not a cookie token, a __Host- name without secure, a same_site other than lax, strict or none, or a value of the wrong type (PRD 5.16). Correct the key the cause names, then run cms:doctor again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### doctor_snapshot_held

A session of this database has held a snapshot for longer than the command budget, so vacuum cannot remove the rows that changed after it was taken, and the tables and their indexes grow (PRD 4.2). End the transaction the cause names, and run long reads on a replica, never on the primary.

- HTTP status: 503 Service Unavailable
- CLI exit code: 79 (NOT_READY)
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

### egress_blocked

The egress gateway refused to send the outbound request (GUARDRAILS 3, PRD 7.14): the SSRF guard found that its URL points at a private, reserved or cloud metadata address or a blocked host, uses another scheme than https, or carries credentials. Nothing was sent. Use a public https URL.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### egress_guard_disabled

The egress gateway sent nothing, because the policy of its SSRF guard is switched off: ssrf.enforce or ssrf.pin_dns of cboxdk/laravel-ssrf is false (GUARDRAILS 3). Without them the gateway cannot promise that a request never reaches a private address. Turn both on in config/ssrf.php or the environment (SSRF_ENFORCE).

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### egress_mail_failed

A mail was not handed to the mail transport of the installation's mailer, which the operator configures in mail.default and mail.mailers: the transport refused it, did not answer, or the mail has no sender, mail.from (PRD 5.16). Nothing was sent. Check the mailer's settings and that its host is reachable, then try again.

- HTTP status: 503 Service Unavailable
- CLI exit code: 75 (EX_TEMPFAIL)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: yes, the same call may succeed later

### egress_redirect_refused

The destination of an outbound request answered with a redirect, which the egress gateway never follows, because a redirect can point at an address the SSRF guard would refuse (PRD 7.14). The request counts as failed. Use the URL the destination redirects to, if it is a public https URL.

- HTTP status: 503 Service Unavailable
- CLI exit code: 69 (EX_UNAVAILABLE)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### egress_unavailable

The destination of an outbound request did not answer within the egress gateway's timeouts, cbox-cms.egress.connect_timeout_ms and timeout_ms, or the connection failed (PRD 7.14). Try again later; if it keeps failing, check that the destination is up and reachable from the server.

- HTTP status: 503 Service Unavailable
- CLI exit code: 75 (EX_TEMPFAIL)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: yes, the same call may succeed later

### fake_check_failed

A FakeDoctorCheck of the testkit failed, because a test told it to. Only tests see this code.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### field_encryption_unavailable

The field is classified confidential or above, so it is stored only as ciphertext under a scope or subject key (PRD 12.2), and this installation has no key management yet (PRD 12.3). The kernel refuses the value rather than store it in plain text, and nothing was committed. Leave the field out or send null.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
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

### generate_field_changed

A field's column type, NOT NULL, CHECK constraints or index differ from its type's schema lock, the table the committed migrations build. Until schema evolution comes (B3), an existing column never changes: undo the change, or add a new optional field instead.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_field_not_queryable

A field is declared filterable or sortable, but its field type has no order the typed query builder can compare: rich text, a group, or a select that allows several options. Remove filterable and sortable from the field, or model the value as its own field of a type that compares (PRD 8.8).

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_field_removed

A field whose column is in its type's schema lock is no longer in the schema. Until schema evolution comes (B3), a type table only grows: put the field back, and stop using it instead.

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

### generate_lock_invalid

A schema lock (<table>.lock) in the migrations directory is not one cms:generate wrote: it is not valid JSON, lacks a value, has an unknown format, or its file name does not match its table. Restore the committed file with git, then run cms:generate again.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
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

### generate_required_field_added

A field added to a type that already has a table is required, so its column would be NOT NULL on the rows that exist. Until schema evolution comes (B3), add the field as optional.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
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

### generate_table_changed

A type's type_id, stages or localization differ from its schema lock, which would change its table's key or system columns. Until schema evolution comes (B3), undo the change, or define a new type.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_table_name_too_long

A type's table name, <owner>__<handle>, is longer than 54 bytes, so the names of its row level security policies would pass Postgres' limit of 63 bytes. Give the type a shorter handle.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_too_many_fields

A type has more than 200 top-level fields, its own and those its extensions add together, and each is a column of the type's table (PRD 11.6). Move fields into groups, or split the type.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### generate_type_removed

A type whose table has a schema lock in the migrations directory is no longer in the schema. Until schema evolution comes (B3), a type is never removed or renamed: put its blueprint back.

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

### grant_escalation_refused

An actor can only give the roles and grants it holds itself, on the nodes where it holds them (PRD 5.10, invariant 31): the grant would give a permission of the role that the issuing actor does not hold on the node in the grant's locales, or a classification access above its own there, so nothing was committed. Ask an actor who holds the role's permissions on the node to grant it, or grant a role within your own rights.

- HTTP status: 403 Forbidden
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
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

### host_not_configured

No configured site is served at the host the request names, so nothing was resolved (PRD 8.10 point 7). Only the hosts in cbox-cms.sites resolve, and a request's own Host or X-Forwarded-Host is never trusted instead. Ask for a host a site is served at, or add the host to its site.

- HTTP status: 421 Misdirected Request
- CLI exit code: 68 (EX_NOHOST)
- MCP: a tool result with isError set
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

### idempotency_key_required

A command through REST needs an idempotency key (PRD 6.1), and the request has no Idempotency-Key header, or one that is not 1 to 255 visible ASCII characters. Nothing ran and nothing was committed. Send the command again with a key of your own in Idempotency-Key, and the same key when you repeat it.

- HTTP status: 400 Bad Request
- CLI exit code: 64 (EX_USAGE)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### install_owner_connection_required

cms:install creates the installation operator as the owner role, and this process has no owner connection, or the connection it names is not the owner role's (PRD 4.2). Run cms:install in the maintenance process, with cbox-cms.database.owner_connection naming the owner role's connection.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### installation_operator_missing

A maintenance command runs as the installation operator, and the installation has none yet (PRD 5.16). Run cms:install in the maintenance process first.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

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

### local_account_exists

A local account was not created: its login identifier, the email address in lower case, is the login of another local account already, or the actor has a local account (PRD 5.16, "Lokale konti"). Every login belongs to one account and every account to one actor. Nothing was written. Use another email address, or reset the password of the account that exists.

- HTTP status: 409 Conflict
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### local_account_missing

The actor has no local account, so its password cannot be changed or reset (PRD 5.16). Nothing was written. An actor that logs in only through a federated connection has no local account.

- HTTP status: 404 Not Found
- CLI exit code: 67 (EX_NOUSER)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### login_authoritative_link

The login was refused by the login policy: it came through the local connection, and the actor is linked to a connection marked authoritative, whose identity provider owns the actor's access, so the actor has no local login methods (PRD 5.16, invariant 38). No session was issued. Log in through the authoritative connection; the person is only told that the login failed.

- HTTP status: 403 Forbidden
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### login_class_not_allowed

The login was refused by the login policy: the actor is a service actor, and a service actor never logs in; it only holds service credentials (PRD 5.16). No session was issued. Use the service actor's credential, or log in as a person.

- HTTP status: 403 Forbidden
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### login_connection_not_allowed

The login was refused by the login policy: the login policy of the actor's class does not list the connection it came through (PRD 5.16, cbox-cms.identity.policy.<class>.connections). No session was issued. Log in through a connection the policy lists, or add the connection to the policy.

- HTTP status: 403 Forbidden
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### login_factors_unavailable

The login was refused by the login policy: the policy of the actor's class requires factors the login did not give, such as a passkey or two factors for a local staff login, or MFA shown in amr or acr for a federated one (PRD 5.16, cbox-cms.identity.policy.<class>.local_factors, federated_amr and federated_acr). No session was issued. Log in with the factors the policy requires. Only in local and testing may the environment's policy allow a password alone for staff.

- HTTP status: 403 Forbidden
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### login_issuer_mismatch

The login was refused: the token came from another issuer than the one its login connection is pinned to (PRD 5.16), so no session was issued and no actor was found or created. A token is trusted only from the issuer its connection names. Log in again through the connection of your organisation; if the issuer of the connection has changed, correct the connection's configuration.

- HTTP status: 401 Unauthorized
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### login_local_disabled

The login was refused by the login policy: local login is switched off for the actor's class in this environment, so only federated connections give a session (PRD 5.16, cbox-cms.identity.policy.<class>.local_login). No session was issued. Log in through the federated connection of your organisation.

- HTTP status: 403 Forbidden
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### login_method_not_allowed

The login was refused by the login policy: the policy of the actor's class does not allow the login method, or the method does not belong to the connection it came through, such as a password on a federated connection (PRD 5.16, cbox-cms.identity.policy.<class>.methods). No session was issued. Log in with a method the policy allows.

- HTTP status: 403 Forbidden
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### login_policy_invalid

The login policy in cbox-cms.identity.policy is invalid: a key is missing or has a value of another form, such as an unknown method, a lifetime below one minute or an inactivity timeout longer than the absolute lifetime, or it lets members of staff log in locally with a password alone in an environment other than local and testing, where PRD 5.16 requires a passkey or two factors. No login is decided while it is invalid, and a process that serves HTTP refuses to boot with it; cms:doctor's identity.login_policy says the same. Correct the policy as docs/security/login-policy.md describes it.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### login_rate_limited

The login was refused before any password was checked: too many logins that did not succeed came for the same email address, or from the same IP address, within the window of cbox-cms.identity.login.throttle (PRD 5.16). No session was issued. Wait until the window has passed and log in again; the person is told to wait, never whether the account exists.

- HTTP status: 429 Too Many Requests
- CLI exit code: 75 (EX_TEMPFAIL)
- MCP: a tool result with isError set
- Retry: yes, the same call may succeed later

### login_rejected

The login was refused: the identity provider or the credential check did not accept it, such as a wrong password, an unknown account or an error the provider sent back (PRD 5.16). No session was issued. Check the credentials and log in again; the person is only told that the login failed.

- HTTP status: 401 Unauthorized
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### login_state_mismatch

The login was refused: what came back does not belong to the login this browser started, by its state, its connection or its flow (PRD 5.16). It may be a planted or replayed response, or the session that held the pending login has expired. Nothing was checked further and no session was issued. Start the login again from the login page.

- HTTP status: 401 Unauthorized
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### login_tenant_claim_missing

The login was refused: the login connection is pinned to a tenant of its issuer, and the token does not carry the claim that names the tenant, such as tid for Microsoft Entra ID or hd for Google (PRD 5.16). The tenant is read only from the token, never from the request, so the login cannot be admitted. Log in with an account of the pinned tenant, such as a Workspace account rather than a personal one.

- HTTP status: 401 Unauthorized
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### login_tenant_mismatch

The login was refused: the token's tenant claim names another tenant than the one the login connection is pinned to (PRD 5.16), so no session was issued. An issuer with several tenants is trusted only for the pinned tenant. Log in with an account of the organisation the connection is for.

- HTTP status: 401 Unauthorized
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### maintenance_process_required

The command runs only in the maintenance process (PRD 4.2, 5.16), the console process that has the owner connection and runs the migrations, and this process serves HTTP, runs queued jobs or has no owner connection. Nothing ran. Run it from the console of the maintenance process, with cbox-cms.database.owner_connection naming the owner role's connection.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
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

### password_breached

The password was refused: it is known from data breaches, so someone could guess it from the lists of leaked passwords (PRD 5.16, "Lokale konti"). Nothing was written. Choose another password, such as a sentence of several unrelated words, that you have not used anywhere else.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### password_reset_token_invalid

The password reset link was refused: its token is unknown, has been used already or has expired (PRD 5.16). Nothing was changed. Ask for a new link from the page that resets a password; a link sets a password once and expires.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### password_too_long

The password was refused: it is longer than 1024 bytes in UTF-8, the most a local account takes, so hashing it cannot be used to slow the server down (PRD 5.16). Nothing was written. Choose a shorter password.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### password_too_short

The password was refused: it has fewer than 12 characters, the least a local account takes (PRD 5.16, "Lokale konti"). Nothing was written. Choose a longer password, such as a sentence of several unrelated words.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### path_gone

What the path showed was withdrawn (PRD 6.6, invariant 7): the entry is no longer active, or its variant or its placement was taken down, and no scheduled change shows it again. Only a reinstatement does.

- HTTP status: 410 Gone
- CLI exit code: 66 (EX_NOINPUT)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### path_not_found

Nothing is shown at the path in the language on the site now (PRD 5.9, 6.6): no route, placement or published content answers it, or its window has not opened or has ended. A publication, a new placement or an opening window can show something there later.

- HTTP status: 404 Not Found
- CLI exit code: 66 (EX_NOINPUT)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### placement_slug_taken

Another placement that is not withdrawn has this slug below the same node in the same language, and a URL must name one placement (PRD 5.9, invariant 15), so nothing was committed. Choose another slug, or change the other placement first.

- HTTP status: 409 Conflict
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### query_over_budget

The read costs more than the budget of its principal, cbox-cms.queries.budgets (PRD 6.2, 8.8), so it was rejected before anything was read. The cost comes from the rows the read may return, how deep it reads and the relations it expands. Ask for fewer rows or a smaller selection, or call with a credential whose budget allows the read.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### rebuild_identity_invalid

A rebuild of a type's read model runs as a service actor, never as the system (PRD 5.10, 6.5 invariant 21), and cbox-cms.rebuild.service_actor names none, an actor that does not exist or one that is not of class service. Nothing was rebuilt. Create a service actor, grant it a role on the nodes whose entries it rebuilds, and name its id there.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### rebuild_schema_version_unsupported

A rebuild of a type's read model found a revision or head snapshot written under another schema version than the type's current one (PRD 4.1, 11.6, invariant 22). Upcasting a payload to the current version comes with the upcasters, so the rebuild reads payloads at the current version only. The chunk that found it rolled back, and the chunks before it stay rebuilt. Run the rebuild again once the payload can be read at the current version; it resumes at that chunk.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### rebuild_type_unknown

No type of this installation has the name given to the rebuild, so nothing was rebuilt. Name the type as <owner>:<handle>, the name cms:generate gives it.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
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

### registry_duplicate_panel_point

Two classes declare the same panel point name and version with #[PanelPoint]. A point's name and version belong to one props class: give the new props the next version, or rename one of the points.

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

The arguments of an #[Action], #[Command], #[Query], #[Hook], #[Subscription] or #[PanelPoint] attribute are invalid, so it cannot be built. Correct the attribute as the cause says.

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

An #[Action], #[Command], #[Query], #[Hook], #[Subscription] or #[PanelPoint] attribute sits on an interface, a trait, an enum or an abstract class. Put it on a concrete class.

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

A #[Command], #[Query], #[Action], #[Subscription] or #[PanelPoint] sits on a class that is not a final readonly class (GUARDRAILS 2.1). Make the class final readonly.

- HTTP status: 500 Internal Server Error
- CLI exit code: 65 (EX_DATAERR)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### registry_panel_point_without_stability

A #[PanelPoint] class carries none, or more than one, of #[Stable], #[Experimental] and #[Internal]. The attribute on the props class is the point's stability (GUARDRAILS 2.3), so give it exactly one.

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

### registry_surface_without_codec

An action is exposed on REST, but no codec reads its command or query, so cms:build cannot describe or serve it (GUARDRAILS 2.1, 2.2). Register the command's CommandCodec under the container tag cbox-cms.command-codecs, or the query's QueryCodec under cbox-cms.query-codecs, each with its JSON Schema, and run cms:build again.

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

### request_header_invalid

A header of the envelope of a REST command, Cbox-Wait-Level, Cbox-Dry-Run or Cbox-Correlation-Id, does not hold what the envelope allows (PRD 6.1): a wait level other than commit, origin, edge, verified or propagated, a dry run other than true or false, or a correlation id that is not 1 to 128 visible ASCII characters. Nothing ran. Correct the header the error names, or leave it out for its default.

- HTTP status: 400 Bad Request
- CLI exit code: 64 (EX_USAGE)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### scim_invalid_value

The SCIM call was refused: a member of the group is not a user of the connection whose token made the call (PRD 5.16, RFC 7644 scimType invalidValue). A connection's token reaches only its own users and groups, so a user of another connection, a local account or an unknown id cannot be a member. Nothing was committed. Provision the user through the same connection first.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### scim_mutability

The SCIM call was refused: it would change the externalId of a user or group (PRD 5.16, RFC 7644 scimType mutability). A user's externalId carries the same immutable value as the connection's declared claim of the ID token, sub by default, so it is the key of the IdP identity and never changes. Nothing was committed. Send the externalId the resource has, or delete the resource and create it again.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### scim_reactivation_refused

The SCIM call was refused: it sets active=true for an actor that another source than this connection deactivated, such as a security event, a local command or the inactivity rule (PRD 5.16). Only the source that deactivated an actor may reactivate it, so the actor stays deactivated and nothing was committed. A person reactivates it with actor.reactivate, with four eyes and step-up.

- HTTP status: 409 Conflict
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### scim_resource_not_found

The SCIM call was refused: the connection whose token made it has no user or group with the id (PRD 5.16, RFC 7644 3.12). The resource never existed, was deleted, which a repeated DELETE meets, or belongs to another connection, whose resources a token never reaches. Nothing was committed. Look the resource up through the same connection.

- HTTP status: 404 Not Found
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### scim_uniqueness

The SCIM call was refused: another resource of the connection already has the externalId or the userName of the user, or the displayName of the group, compared without regard to case where RFC 7643 says so (PRD 5.16, RFC 7644 scimType uniqueness). A create that repeats the state of the existing resource is not refused; it changes nothing. Nothing was committed. Use the existing resource, or give the new one another value.

- HTTP status: 409 Conflict
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### scim_version_mismatch

The SCIM call was refused: its If-Match names another version than the resource's current one in the CMS (PRD 5.16, RFC 7644 3.14), because something changed the resource since the identity provider read it. Nothing was committed. Read the resource again and send the change with its current version.

- HTTP status: 412 Precondition Failed
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### session_cookie_insecure

The identity module refused to boot a process that serves HTTP, because the session cookie of this environment is not safe outside local and testing (PRD 5.16): it is not Secure, its name lacks the __Host- prefix, or it allows SameSite=None. Run cms:doctor, whose identity.session_cookie says which, and set cbox-cms.identity.session.cookie for the environment to __Host-cms_session with secure true.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### session_cookie_invalid

The setting cbox-cms.identity.session.cookie is invalid for this environment, so the identity module cannot set a session cookie (PRD 5.16). Correct the key the message names; cms:doctor's identity.session_cookie says the same.

- HTTP status: 500 Internal Server Error
- CLI exit code: 78 (EX_CONFIG)
- MCP: the JSON-RPC error -32603, Internal error
- Retry: no, the same call gives the same answer until something changes

### signal_audience_mismatch

The signal was refused: the token's aud claim does not list the audience the connection is pinned to, the CMS's client id at the identity provider for a logout token or the stream's audience for a security event (PRD 5.16). Nothing was ended or changed. Check the client id or the stream configured at the identity provider.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### signal_event_unsupported

The security event was refused: its event type is not one the core acts on, CAEP session-revoked or credential-change, or RISC account-disabled, account-enabled, account-purged or credential-compromise (PRD 5.16). Nothing was ended or changed. Configure the stream at the transmitter to send only those events.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### signal_expired

The back-channel logout was refused: the logout token's exp has passed, beyond the 60 seconds the clocks may differ (PRD 5.16). Nothing was ended. The identity provider sends a new logout token; if it keeps happening, check the clocks of the identity provider and the CMS.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### signal_issued_in_future

The signal was refused: the token's iat is more than 60 seconds after the receiver's time (PRD 5.16). Nothing was ended or changed. Check the clocks of the identity provider and the CMS.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### signal_issuer_mismatch

The signal was refused: the token's iss is not the issuer the connection is pinned to (PRD 5.16), compared exactly, so a back-channel logout or a security event from another issuer ends and changes nothing. Configure the identity provider to send the connection's signals, or correct the connection's issuer.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### signal_logout_event_missing

The back-channel logout was refused: the logout token's events claim does not hold the event http://schemas.openid.net/event/backchannel-logout, so it is not a logout token (OpenID Connect Back-Channel Logout 1.0, PRD 5.16). Nothing was ended.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### signal_nonce_present

The back-channel logout was refused: the logout token carries a nonce claim, which a logout token never does, so it may be an ID token sent in its place (OpenID Connect Back-Channel Logout 1.0, PRD 5.16). Nothing was ended.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### signal_replayed

The back-channel logout was refused: its logout token's jti was received before from the same issuer (PRD 5.16), so the logout has had its effect and a replay ends nothing more. The next login goes to the identity provider in any case.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### signal_subject_missing

The back-channel logout was refused: the logout token names neither a subject (sub) nor an IdP session (sid), so it says nothing about whose sessions end (OpenID Connect Back-Channel Logout 1.0, PRD 5.16). Nothing was ended.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### signal_subject_unsupported

The security event was refused: it does not name its subject as an iss_sub of the connection's issuer (RFC 9493, PRD 5.16). The core finds an actor only by its IdP identity, the connection, the issuer and the subject, never by an email address or a phone number alone. Nothing was ended or changed. Configure the transmitter to send iss_sub subjects.

- HTTP status: 400 Bad Request
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### site_locales_drift

The site is registered already, and the locales cbox-cms.sites configures for it are not the locales it publishes in (PRD 11.14). cms:sites:sync registers sites the database lacks and never rewrites one that exists, so nothing of this site was changed; the other configured sites were still synced. Revert the site's locales in the configuration to the ones the error lists, or wait for the locale commands of a later block, which add and remove a site's locales through the pipeline.

- HTTP status: 409 Conflict
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### step_up_required

The command needs step-up, a fresh authentication of a person in an interactive session (PRD 5.16), such as a grant of an administrative role, one whose permissions include grant.*, role.* or actor.deactivate. Nothing was committed. Step-up is not built yet, so such a grant is refused on every surface; the first administrator gets the role from the one-time access bootstrap in the maintenance process.

- HTTP status: 403 Forbidden
- CLI exit code: 77 (EX_NOPERM)
- MCP: a tool result with isError set
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

### type_not_releasable

The type's entries have no revision to release (PRD 4.1, 5.6, 6.4): a type with stages none is public as soon as it is saved, and a type whose history is audit-only or none keeps no revisions for the head to point at. Nothing was committed. Save the entry instead, or give the type stages draft-release and history full.

- HTTP status: 422 Unprocessable Content
- CLI exit code: 65 (EX_DATAERR)
- MCP: a tool result with isError set
- Retry: no, the same call gives the same answer until something changes

### unauthorized

The actor may not run this command on this target, or this read, so the call was rejected: nothing was committed and nothing was read. Ask for the right the command or read needs, or call as an actor that has it.

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
