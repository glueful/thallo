--
-- PostgreSQL database dump
--

\restrict 4Y5kGvQiBxs1hghe0v9Czap89ghCICAAXgFC5GI1eLQo7NB8dqqtOPhPDBD6on9

-- Dumped from database version 17.11 (Postgres.app)
-- Dumped by pg_dump version 18.6 (Postgres.app)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

ALTER TABLE IF EXISTS ONLY public.user_roles DROP CONSTRAINT IF EXISTS fk_user_roles_role_uuid_roles;
ALTER TABLE IF EXISTS ONLY public.user_permissions DROP CONSTRAINT IF EXISTS fk_user_permissions_permission_uuid_permissions;
ALTER TABLE IF EXISTS ONLY public.tenant_memberships DROP CONSTRAINT IF EXISTS fk_tenant_memberships_tenant_uuid_tenants;
ALTER TABLE IF EXISTS ONLY public.tenant_domains DROP CONSTRAINT IF EXISTS fk_tenant_domains_tenant_uuid_tenants;
ALTER TABLE IF EXISTS ONLY public.roles DROP CONSTRAINT IF EXISTS fk_roles_parent_uuid_roles;
ALTER TABLE IF EXISTS ONLY public.role_permissions DROP CONSTRAINT IF EXISTS fk_role_permissions_role_uuid_roles;
ALTER TABLE IF EXISTS ONLY public.role_permissions DROP CONSTRAINT IF EXISTS fk_role_permissions_permission_uuid_permissions;
ALTER TABLE IF EXISTS ONLY public.profiles DROP CONSTRAINT IF EXISTS fk_profiles_user_uuid_users;
ALTER TABLE IF EXISTS ONLY public.media_assets DROP CONSTRAINT IF EXISTS fk_media_assets_blob_uuid_blobs;
ALTER TABLE IF EXISTS ONLY public.job_executions DROP CONSTRAINT IF EXISTS fk_job_executions_job_uuid_scheduled_jobs;
ALTER TABLE IF EXISTS ONLY public.import_export_reports DROP CONSTRAINT IF EXISTS fk_import_export_reports_job_uuid_import_export_jobs;
ALTER TABLE IF EXISTS ONLY public.import_export_files DROP CONSTRAINT IF EXISTS fk_import_export_files_job_uuid_import_export_jobs;
ALTER TABLE IF EXISTS ONLY public.import_export_errors DROP CONSTRAINT IF EXISTS fk_import_export_errors_job_uuid_import_export_jobs;
ALTER TABLE IF EXISTS ONLY public.import_export_errors DROP CONSTRAINT IF EXISTS fk_import_export_errors_batch_uuid_import_export_batches;
ALTER TABLE IF EXISTS ONLY public.import_export_batches DROP CONSTRAINT IF EXISTS fk_import_export_batches_job_uuid_import_export_jobs;
ALTER TABLE IF EXISTS ONLY public.auth_refresh_tokens DROP CONSTRAINT IF EXISTS fk_auth_refresh_tokens_session_uuid_auth_sessions;
DROP INDEX IF EXISTS public.workflow_review_states_state_index;
DROP INDEX IF EXISTS public.webhook_subscriptions_is_active_index;
DROP INDEX IF EXISTS public.webhook_deliveries_subscription_id_index;
DROP INDEX IF EXISTS public.webhook_deliveries_status_index;
DROP INDEX IF EXISTS public.webhook_deliveries_next_retry_at_index;
DROP INDEX IF EXISTS public.user_roles_user_uuid_index;
DROP INDEX IF EXISTS public.user_roles_role_uuid_index;
DROP INDEX IF EXISTS public.user_roles_granted_by_index;
DROP INDEX IF EXISTS public.user_roles_expires_at_index;
DROP INDEX IF EXISTS public.user_permissions_user_uuid_index;
DROP INDEX IF EXISTS public.user_permissions_permission_uuid_index;
DROP INDEX IF EXISTS public.user_permissions_granted_by_index;
DROP INDEX IF EXISTS public.user_permissions_expires_at_index;
DROP INDEX IF EXISTS public.uniq_subscriptions_subject;
DROP INDEX IF EXISTS public.uniq_plans_scope_key;
DROP INDEX IF EXISTS public.uniq_pending_schedule;
DROP INDEX IF EXISTS public.uniq_override_subject_entitlement;
DROP INDEX IF EXISTS public.uniq_entry_schema_migrations_active;
DROP INDEX IF EXISTS public.uniq_active_tenant_purge_run;
DROP INDEX IF EXISTS public.thallo_tenant_purge_runs_tenant_uuid_index;
DROP INDEX IF EXISTS public.thallo_tenant_purge_runs_status_index;
DROP INDEX IF EXISTS public.thallo_tenant_purge_runs_lease_expires_at_index;
DROP INDEX IF EXISTS public.tenants_status_index;
DROP INDEX IF EXISTS public.tenant_memberships_user_uuid_index;
DROP INDEX IF EXISTS public.tenant_memberships_tenant_uuid_index;
DROP INDEX IF EXISTS public.tenant_domains_verification_status_last_checked_at_index;
DROP INDEX IF EXISTS public.tenant_domains_tenant_uuid_index;
DROP INDEX IF EXISTS public.tenant_domains_status_index;
DROP INDEX IF EXISTS public.starter_provenance_tenant_uuid_index;
DROP INDEX IF EXISTS public.scheduled_jobs_next_run_index;
DROP INDEX IF EXISTS public.scheduled_jobs_name_index;
DROP INDEX IF EXISTS public.scheduled_jobs_is_enabled_index;
DROP INDEX IF EXISTS public.roles_status_index;
DROP INDEX IF EXISTS public.roles_parent_uuid_index;
DROP INDEX IF EXISTS public.roles_level_index;
DROP INDEX IF EXISTS public.role_permissions_role_uuid_index;
DROP INDEX IF EXISTS public.role_permissions_permission_uuid_index;
DROP INDEX IF EXISTS public.role_permissions_granted_by_index;
DROP INDEX IF EXISTS public.role_permissions_expires_at_index;
DROP INDEX IF EXISTS public.released_hosts_retained_until_index;
DROP INDEX IF EXISTS public.released_hosts_released_by_tenant_index;
DROP INDEX IF EXISTS public.queue_jobs_reserved_at_index;
DROP INDEX IF EXISTS public.queue_jobs_queue_index;
DROP INDEX IF EXISTS public.queue_jobs_priority_index;
DROP INDEX IF EXISTS public.queue_jobs_batch_uuid_index;
DROP INDEX IF EXISTS public.queue_jobs_available_at_index;
DROP INDEX IF EXISTS public.queue_failed_jobs_queue_index;
DROP INDEX IF EXISTS public.queue_failed_jobs_failed_at_index;
DROP INDEX IF EXISTS public.queue_failed_jobs_connection_index;
DROP INDEX IF EXISTS public.queue_failed_jobs_batch_uuid_index;
DROP INDEX IF EXISTS public.queue_batches_name_index;
DROP INDEX IF EXISTS public.queue_batches_finished_at_index;
DROP INDEX IF EXISTS public.queue_batches_created_at_index;
DROP INDEX IF EXISTS public.queue_batches_cancelled_at_index;
DROP INDEX IF EXISTS public.published_entry_references_target_entry_uuid_index;
DROP INDEX IF EXISTS public.profiles_photo_uuid_index;
DROP INDEX IF EXISTS public.permissions_resource_type_index;
DROP INDEX IF EXISTS public.permissions_category_index;
DROP INDEX IF EXISTS public.permission_audit_target_uuid_index;
DROP INDEX IF EXISTS public.permission_audit_subject_uuid_index;
DROP INDEX IF EXISTS public.permission_audit_subject_type_index;
DROP INDEX IF EXISTS public.permission_audit_permission_uuid_index;
DROP INDEX IF EXISTS public.permission_audit_performed_by_index;
DROP INDEX IF EXISTS public.permission_audit_created_at_index;
DROP INDEX IF EXISTS public.notifications_type_index;
DROP INDEX IF EXISTS public.notifications_scheduled_at_index;
DROP INDEX IF EXISTS public.notifications_read_at_index;
DROP INDEX IF EXISTS public.notifications_notifiable_type_index;
DROP INDEX IF EXISTS public.notifications_notifiable_id_index;
DROP INDEX IF EXISTS public.notification_templates_notification_type_index;
DROP INDEX IF EXISTS public.notification_templates_channel_index;
DROP INDEX IF EXISTS public.notification_retry_queue_retry_at_index;
DROP INDEX IF EXISTS public.notification_retry_queue_notification_id_index;
DROP INDEX IF EXISTS public.notification_retry_queue_notifiable_type_notifiable_id_index;
DROP INDEX IF EXISTS public.notification_preferences_notification_type_index;
DROP INDEX IF EXISTS public.notification_preferences_notifiable_type_index;
DROP INDEX IF EXISTS public.notification_preferences_notifiable_id_index;
DROP INDEX IF EXISTS public.notification_deliveries_status_index;
DROP INDEX IF EXISTS public.notification_deliveries_sent_at_index;
DROP INDEX IF EXISTS public.notification_deliveries_notification_uuid_index;
DROP INDEX IF EXISTS public.notification_deliveries_channel_index;
DROP INDEX IF EXISTS public.media_usage_tenant_uuid_index;
DROP INDEX IF EXISTS public.media_usage_entry_uuid_index;
DROP INDEX IF EXISTS public.media_usage_blob_uuid_index;
DROP INDEX IF EXISTS public.media_meta_tenant_uuid_index;
DROP INDEX IF EXISTS public.media_assets_tenant_uuid_index;
DROP INDEX IF EXISTS public.locks_token_index;
DROP INDEX IF EXISTS public.locks_expiration_index;
DROP INDEX IF EXISTS public.job_executions_status_index;
DROP INDEX IF EXISTS public.job_executions_started_at_index;
DROP INDEX IF EXISTS public.job_executions_job_uuid_index;
DROP INDEX IF EXISTS public.import_export_jobs_type_index;
DROP INDEX IF EXISTS public.import_export_jobs_status_index;
DROP INDEX IF EXISTS public.import_export_jobs_created_by_index;
DROP INDEX IF EXISTS public.import_export_jobs_created_at_index;
DROP INDEX IF EXISTS public.import_export_jobs_adapter_index;
DROP INDEX IF EXISTS public.import_export_files_role_index;
DROP INDEX IF EXISTS public.import_export_files_job_uuid_index;
DROP INDEX IF EXISTS public.import_export_errors_severity_index;
DROP INDEX IF EXISTS public.import_export_errors_job_uuid_index;
DROP INDEX IF EXISTS public.import_export_errors_batch_uuid_index;
DROP INDEX IF EXISTS public.import_export_batches_locked_at_index;
DROP INDEX IF EXISTS public.idx_workflow_transitions_entry_locale;
DROP INDEX IF EXISTS public.idx_tenant_roles_tenant;
DROP INDEX IF EXISTS public.idx_tenant_role_overrides_tenant;
DROP INDEX IF EXISTS public.idx_tenant_api_key_bindings_tenant;
DROP INDEX IF EXISTS public.idx_subscriptions_checkout_origination;
DROP INDEX IF EXISTS public.idx_subscription_plans_updated_at;
DROP INDEX IF EXISTS public.idx_subscription_plans_status;
DROP INDEX IF EXISTS public.idx_signup_intents_status;
DROP INDEX IF EXISTS public.idx_signup_intents_expires;
DROP INDEX IF EXISTS public.idx_signup_intents_email;
DROP INDEX IF EXISTS public.idx_schema_migrations_type_from;
DROP INDEX IF EXISTS public.idx_schedules_status_run_at;
DROP INDEX IF EXISTS public.idx_render_template_versions_template;
DROP INDEX IF EXISTS public.idx_redirect_target_entry;
DROP INDEX IF EXISTS public.idx_queue_reserved;
DROP INDEX IF EXISTS public.idx_queue_available;
DROP INDEX IF EXISTS public.idx_pubref_type_field_locale_target;
DROP INDEX IF EXISTS public.idx_priority_available;
DROP INDEX IF EXISTS public.idx_overrides_tenant;
DROP INDEX IF EXISTS public.idx_notifications_idempotency_lookup;
DROP INDEX IF EXISTS public.idx_navigation_items_tree;
DROP INDEX IF EXISTS public.idx_import_export_batch_job_status;
DROP INDEX IF EXISTS public.idx_i18n_translation_bundle;
DROP INDEX IF EXISTS public.idx_i18n_missing_bundle;
DROP INDEX IF EXISTS public.idx_form_submissions_submitted_at;
DROP INDEX IF EXISTS public.idx_form_submissions_status;
DROP INDEX IF EXISTS public.idx_form_submissions_form_key;
DROP INDEX IF EXISTS public.idx_failed_connection_queue;
DROP INDEX IF EXISTS public.idx_events_tenant_created;
DROP INDEX IF EXISTS public.idx_commerce_product_slug_tenant_product;
DROP INDEX IF EXISTS public.idx_commerce_product_link_tenant_product;
DROP INDEX IF EXISTS public.idx_commerce_link_delivery_tenant_order;
DROP INDEX IF EXISTS public.idx_commerce_checkout_attempt_created_at;
DROP INDEX IF EXISTS public.idx_collection_changes_tenant_collection;
DROP INDEX IF EXISTS public.idx_block_type_migrations_type;
DROP INDEX IF EXISTS public.idx_block_type_migrations_status;
DROP INDEX IF EXISTS public.idx_batch_pending;
DROP INDEX IF EXISTS public.idx_audit_target;
DROP INDEX IF EXISTS public.idx_audit_category;
DROP INDEX IF EXISTS public.idx_audit_actor;
DROP INDEX IF EXISTS public.idx_api_metrics_timestamp;
DROP INDEX IF EXISTS public.idx_api_metrics_endpoint_method;
DROP INDEX IF EXISTS public.idx_api_metrics_daily_date;
DROP INDEX IF EXISTS public.i18n_translations_status_index;
DROP INDEX IF EXISTS public.i18n_locales_is_default_index;
DROP INDEX IF EXISTS public.i18n_locales_enabled_index;
DROP INDEX IF EXISTS public.extension_operations_status_index;
DROP INDEX IF EXISTS public.extension_operations_package_index;
DROP INDEX IF EXISTS public.entry_versions_entry_uuid_locale_index;
DROP INDEX IF EXISTS public.entry_routes_entry_uuid_index;
DROP INDEX IF EXISTS public.entry_references_target_entry_uuid_index;
DROP INDEX IF EXISTS public.entry_publications_version_uuid_index;
DROP INDEX IF EXISTS public.entries_status_index;
DROP INDEX IF EXISTS public.entries_content_type_uuid_index;
DROP INDEX IF EXISTS public.collection_schema_changes_collection_uuid_index;
DROP INDEX IF EXISTS public.collection_definitions_tenant_uuid_index;
DROP INDEX IF EXISTS public.blobs_visibility_index;
DROP INDEX IF EXISTS public.blobs_created_by_index;
DROP INDEX IF EXISTS public.auth_sessions_user_uuid_index;
DROP INDEX IF EXISTS public.auth_sessions_status_index;
DROP INDEX IF EXISTS public.auth_refresh_tokens_user_uuid_index;
DROP INDEX IF EXISTS public.auth_refresh_tokens_status_index;
DROP INDEX IF EXISTS public.auth_refresh_tokens_session_uuid_index;
DROP INDEX IF EXISTS public.auth_refresh_tokens_parent_uuid_index;
DROP INDEX IF EXISTS public.auth_refresh_tokens_expires_at_index;
DROP INDEX IF EXISTS public.audit_logs_occurred_at_index;
DROP INDEX IF EXISTS public.api_keys_user_uuid_index;
DROP INDEX IF EXISTS public.api_keys_key_prefix_index;
DROP INDEX IF EXISTS public.analytics_facts_occurred_at_index;
DROP INDEX IF EXISTS public.analytics_facts_event_occurred_at_index;
DROP INDEX IF EXISTS public.analytics_facts_category_occurred_at_index;
ALTER TABLE IF EXISTS ONLY public.workflow_transitions DROP CONSTRAINT IF EXISTS workflow_transitions_pkey;
ALTER TABLE IF EXISTS ONLY public.workflow_review_states DROP CONSTRAINT IF EXISTS workflow_review_states_pkey;
ALTER TABLE IF EXISTS ONLY public.webhook_subscriptions DROP CONSTRAINT IF EXISTS webhook_subscriptions_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.webhook_subscriptions DROP CONSTRAINT IF EXISTS webhook_subscriptions_pkey;
ALTER TABLE IF EXISTS ONLY public.webhook_deliveries DROP CONSTRAINT IF EXISTS webhook_deliveries_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.webhook_deliveries DROP CONSTRAINT IF EXISTS webhook_deliveries_pkey;
ALTER TABLE IF EXISTS ONLY public.users DROP CONSTRAINT IF EXISTS users_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.users DROP CONSTRAINT IF EXISTS users_username_unique;
ALTER TABLE IF EXISTS ONLY public.users DROP CONSTRAINT IF EXISTS users_pkey;
ALTER TABLE IF EXISTS ONLY public.users DROP CONSTRAINT IF EXISTS users_email_unique;
ALTER TABLE IF EXISTS ONLY public.user_roles DROP CONSTRAINT IF EXISTS user_roles_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.user_roles DROP CONSTRAINT IF EXISTS user_roles_pkey;
ALTER TABLE IF EXISTS ONLY public.user_permissions DROP CONSTRAINT IF EXISTS user_permissions_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.user_permissions DROP CONSTRAINT IF EXISTS user_permissions_pkey;
ALTER TABLE IF EXISTS ONLY public.notification_templates DROP CONSTRAINT IF EXISTS unique_notification_template;
ALTER TABLE IF EXISTS ONLY public.notification_preferences DROP CONSTRAINT IF EXISTS unique_notification_pref;
ALTER TABLE IF EXISTS ONLY public.notification_deliveries DROP CONSTRAINT IF EXISTS unique_notification_delivery_channel;
ALTER TABLE IF EXISTS ONLY public.workflow_review_states DROP CONSTRAINT IF EXISTS uniq_workflow_state_entry_locale;
ALTER TABLE IF EXISTS ONLY public.entry_versions DROP CONSTRAINT IF EXISTS uniq_version_entry_locale_version;
ALTER TABLE IF EXISTS ONLY public.tenant_roles DROP CONSTRAINT IF EXISTS uniq_tenant_role_slug;
ALTER TABLE IF EXISTS ONLY public.tenant_role_policy DROP CONSTRAINT IF EXISTS uniq_tenant_role_policy;
ALTER TABLE IF EXISTS ONLY public.tenant_role_overrides DROP CONSTRAINT IF EXISTS uniq_tenant_role_override;
ALTER TABLE IF EXISTS ONLY public.tenant_role_availability DROP CONSTRAINT IF EXISTS uniq_tenant_role_availability;
ALTER TABLE IF EXISTS ONLY public.thallo_tenant_api_key_bindings DROP CONSTRAINT IF EXISTS uniq_tenant_api_key_binding;
ALTER TABLE IF EXISTS ONLY public.subscriptions DROP CONSTRAINT IF EXISTS uniq_subscriptions_provider_sub;
ALTER TABLE IF EXISTS ONLY public.starter_provenance DROP CONSTRAINT IF EXISTS uniq_starter_provenance_source;
ALTER TABLE IF EXISTS ONLY public.starter_provenance DROP CONSTRAINT IF EXISTS uniq_starter_provenance_key;
ALTER TABLE IF EXISTS ONLY public.signup_verifiers DROP CONSTRAINT IF EXISTS uniq_signup_verifier_intent;
ALTER TABLE IF EXISTS ONLY public.signup_rate_counters DROP CONSTRAINT IF EXISTS uniq_signup_rate_bucket;
ALTER TABLE IF EXISTS ONLY public.signup_intents DROP CONSTRAINT IF EXISTS uniq_signup_intent_uuid;
ALTER TABLE IF EXISTS ONLY public.signup_daily_counters DROP CONSTRAINT IF EXISTS uniq_signup_daily_cap;
ALTER TABLE IF EXISTS ONLY public.signup_continuations DROP CONSTRAINT IF EXISTS uniq_signup_continuation_intent;
ALTER TABLE IF EXISTS ONLY public.entry_routes DROP CONSTRAINT IF EXISTS uniq_route_type_locale_slug;
ALTER TABLE IF EXISTS ONLY public.render_templates DROP CONSTRAINT IF EXISTS uniq_render_template_theme_path;
ALTER TABLE IF EXISTS ONLY public.entry_references DROP CONSTRAINT IF EXISTS uniq_reference_source_field_target_locale;
ALTER TABLE IF EXISTS ONLY public.entry_redirects DROP CONSTRAINT IF EXISTS uniq_redirect_type_locale_source;
ALTER TABLE IF EXISTS ONLY public.subscription_provider_event_receipts DROP CONSTRAINT IF EXISTS uniq_receipts_gateway_logical_key;
ALTER TABLE IF EXISTS ONLY public.published_entry_references DROP CONSTRAINT IF EXISTS uniq_pubref_source_locale_field_target;
ALTER TABLE IF EXISTS ONLY public.entry_publications DROP CONSTRAINT IF EXISTS uniq_publication_entry_locale;
ALTER TABLE IF EXISTS ONLY public.navigation_menus DROP CONSTRAINT IF EXISTS uniq_navigation_menu_slug;
ALTER TABLE IF EXISTS ONLY public.media_usage DROP CONSTRAINT IF EXISTS uniq_media_usage;
ALTER TABLE IF EXISTS ONLY public.import_export_batches DROP CONSTRAINT IF EXISTS uniq_import_export_batch_sequence;
ALTER TABLE IF EXISTS ONLY public.i18n_translations DROP CONSTRAINT IF EXISTS uniq_i18n_translation_key;
ALTER TABLE IF EXISTS ONLY public.i18n_missing_translations DROP CONSTRAINT IF EXISTS uniq_i18n_missing_key;
ALTER TABLE IF EXISTS ONLY public.filter_indexes DROP CONSTRAINT IF EXISTS uniq_filter_index_type_field;
ALTER TABLE IF EXISTS ONLY public.subscription_events DROP CONSTRAINT IF EXISTS uniq_event_gateway_logical_key;
ALTER TABLE IF EXISTS ONLY public.entry_drafts DROP CONSTRAINT IF EXISTS uniq_draft_entry_locale;
ALTER TABLE IF EXISTS ONLY public.thallo_commerce_product_slugs DROP CONSTRAINT IF EXISTS uniq_commerce_product_slug_tenant_slug;
ALTER TABLE IF EXISTS ONLY public.thallo_commerce_product_links DROP CONSTRAINT IF EXISTS uniq_commerce_product_link_tenant_product;
ALTER TABLE IF EXISTS ONLY public.thallo_commerce_product_links DROP CONSTRAINT IF EXISTS uniq_commerce_product_link_tenant_entry;
ALTER TABLE IF EXISTS ONLY public.thallo_commerce_payment_link_deliveries DROP CONSTRAINT IF EXISTS uniq_commerce_link_delivery_tenant_key;
ALTER TABLE IF EXISTS ONLY public.thallo_commerce_checkout_attempts DROP CONSTRAINT IF EXISTS uniq_commerce_checkout_attempt_tenant_key;
ALTER TABLE IF EXISTS ONLY public.collection_definitions DROP CONSTRAINT IF EXISTS uniq_collection_def_tenant_name;
ALTER TABLE IF EXISTS ONLY public.collection_definitions DROP CONSTRAINT IF EXISTS uniq_collection_def_table_name;
ALTER TABLE IF EXISTS ONLY public.block_types DROP CONSTRAINT IF EXISTS uniq_block_type_slug;
ALTER TABLE IF EXISTS ONLY public.thallo_tenant_purge_runs DROP CONSTRAINT IF EXISTS thallo_tenant_purge_runs_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.thallo_tenant_purge_runs DROP CONSTRAINT IF EXISTS thallo_tenant_purge_runs_pkey;
ALTER TABLE IF EXISTS ONLY public.thallo_tenant_api_key_bindings DROP CONSTRAINT IF EXISTS thallo_tenant_api_key_bindings_pkey;
ALTER TABLE IF EXISTS ONLY public.thallo_system_flags DROP CONSTRAINT IF EXISTS thallo_system_flags_pkey;
ALTER TABLE IF EXISTS ONLY public.thallo_commerce_product_slugs DROP CONSTRAINT IF EXISTS thallo_commerce_product_slugs_pkey;
ALTER TABLE IF EXISTS ONLY public.thallo_commerce_product_links DROP CONSTRAINT IF EXISTS thallo_commerce_product_links_pkey;
ALTER TABLE IF EXISTS ONLY public.thallo_commerce_payment_link_deliveries DROP CONSTRAINT IF EXISTS thallo_commerce_payment_link_deliveries_pkey;
ALTER TABLE IF EXISTS ONLY public.thallo_commerce_checkout_attempts DROP CONSTRAINT IF EXISTS thallo_commerce_checkout_attempts_pkey;
ALTER TABLE IF EXISTS ONLY public.tenants DROP CONSTRAINT IF EXISTS tenants_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.tenants DROP CONSTRAINT IF EXISTS tenants_slug_unique;
ALTER TABLE IF EXISTS ONLY public.tenants DROP CONSTRAINT IF EXISTS tenants_pkey;
ALTER TABLE IF EXISTS ONLY public.tenant_roles DROP CONSTRAINT IF EXISTS tenant_roles_pkey;
ALTER TABLE IF EXISTS ONLY public.tenant_role_policy DROP CONSTRAINT IF EXISTS tenant_role_policy_pkey;
ALTER TABLE IF EXISTS ONLY public.tenant_role_overrides DROP CONSTRAINT IF EXISTS tenant_role_overrides_pkey;
ALTER TABLE IF EXISTS ONLY public.tenant_role_availability DROP CONSTRAINT IF EXISTS tenant_role_availability_pkey;
ALTER TABLE IF EXISTS ONLY public.tenant_memberships DROP CONSTRAINT IF EXISTS tenant_memberships_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.tenant_memberships DROP CONSTRAINT IF EXISTS tenant_memberships_tenant_uuid_user_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.tenant_memberships DROP CONSTRAINT IF EXISTS tenant_memberships_pkey;
ALTER TABLE IF EXISTS ONLY public.tenant_domains DROP CONSTRAINT IF EXISTS tenant_domains_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.tenant_domains DROP CONSTRAINT IF EXISTS tenant_domains_pkey;
ALTER TABLE IF EXISTS ONLY public.tenant_domains DROP CONSTRAINT IF EXISTS tenant_domains_host_unique;
ALTER TABLE IF EXISTS ONLY public.subscriptions DROP CONSTRAINT IF EXISTS subscriptions_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.subscriptions DROP CONSTRAINT IF EXISTS subscriptions_pkey;
ALTER TABLE IF EXISTS ONLY public.subscription_v2_preparation DROP CONSTRAINT IF EXISTS subscription_v2_preparation_pkey;
ALTER TABLE IF EXISTS ONLY public.subscription_v2_preparation DROP CONSTRAINT IF EXISTS subscription_v2_preparation_marker_key_unique;
ALTER TABLE IF EXISTS ONLY public.subscription_provider_event_receipts DROP CONSTRAINT IF EXISTS subscription_provider_event_receipts_pkey;
ALTER TABLE IF EXISTS ONLY public.subscription_plans DROP CONSTRAINT IF EXISTS subscription_plans_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.subscription_plans DROP CONSTRAINT IF EXISTS subscription_plans_pkey;
ALTER TABLE IF EXISTS ONLY public.subscription_overrides DROP CONSTRAINT IF EXISTS subscription_overrides_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.subscription_overrides DROP CONSTRAINT IF EXISTS subscription_overrides_pkey;
ALTER TABLE IF EXISTS ONLY public.subscription_events DROP CONSTRAINT IF EXISTS subscription_events_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.subscription_events DROP CONSTRAINT IF EXISTS subscription_events_pkey;
ALTER TABLE IF EXISTS ONLY public.starter_provenance DROP CONSTRAINT IF EXISTS starter_provenance_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.starter_provenance DROP CONSTRAINT IF EXISTS starter_provenance_pkey;
ALTER TABLE IF EXISTS ONLY public.signup_verifiers DROP CONSTRAINT IF EXISTS signup_verifiers_pkey;
ALTER TABLE IF EXISTS ONLY public.signup_rate_counters DROP CONSTRAINT IF EXISTS signup_rate_counters_pkey;
ALTER TABLE IF EXISTS ONLY public.signup_intents DROP CONSTRAINT IF EXISTS signup_intents_pkey;
ALTER TABLE IF EXISTS ONLY public.signup_daily_counters DROP CONSTRAINT IF EXISTS signup_daily_counters_pkey;
ALTER TABLE IF EXISTS ONLY public.signup_continuations DROP CONSTRAINT IF EXISTS signup_continuations_pkey;
ALTER TABLE IF EXISTS ONLY public.settings DROP CONSTRAINT IF EXISTS settings_pkey;
ALTER TABLE IF EXISTS ONLY public.seo_meta DROP CONSTRAINT IF EXISTS seo_meta_pkey;
ALTER TABLE IF EXISTS ONLY public.seo_meta DROP CONSTRAINT IF EXISTS seo_meta_entry_uuid_locale_unique;
ALTER TABLE IF EXISTS ONLY public.scheduled_jobs DROP CONSTRAINT IF EXISTS scheduled_jobs_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.scheduled_jobs DROP CONSTRAINT IF EXISTS scheduled_jobs_pkey;
ALTER TABLE IF EXISTS ONLY public.roles DROP CONSTRAINT IF EXISTS roles_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.roles DROP CONSTRAINT IF EXISTS roles_slug_unique;
ALTER TABLE IF EXISTS ONLY public.roles DROP CONSTRAINT IF EXISTS roles_pkey;
ALTER TABLE IF EXISTS ONLY public.roles DROP CONSTRAINT IF EXISTS roles_name_unique;
ALTER TABLE IF EXISTS ONLY public.role_permissions DROP CONSTRAINT IF EXISTS role_permissions_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.role_permissions DROP CONSTRAINT IF EXISTS role_permissions_pkey;
ALTER TABLE IF EXISTS ONLY public.render_templates DROP CONSTRAINT IF EXISTS render_templates_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.render_templates DROP CONSTRAINT IF EXISTS render_templates_pkey;
ALTER TABLE IF EXISTS ONLY public.render_template_versions DROP CONSTRAINT IF EXISTS render_template_versions_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.render_template_versions DROP CONSTRAINT IF EXISTS render_template_versions_pkey;
ALTER TABLE IF EXISTS ONLY public.released_hosts DROP CONSTRAINT IF EXISTS released_hosts_pkey;
ALTER TABLE IF EXISTS ONLY public.released_hosts DROP CONSTRAINT IF EXISTS released_hosts_host_unique;
ALTER TABLE IF EXISTS ONLY public.regions DROP CONSTRAINT IF EXISTS regions_pkey;
ALTER TABLE IF EXISTS ONLY public.queue_jobs DROP CONSTRAINT IF EXISTS queue_jobs_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.queue_jobs DROP CONSTRAINT IF EXISTS queue_jobs_pkey;
ALTER TABLE IF EXISTS ONLY public.queue_failed_jobs DROP CONSTRAINT IF EXISTS queue_failed_jobs_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.queue_failed_jobs DROP CONSTRAINT IF EXISTS queue_failed_jobs_pkey;
ALTER TABLE IF EXISTS ONLY public.queue_batches DROP CONSTRAINT IF EXISTS queue_batches_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.queue_batches DROP CONSTRAINT IF EXISTS queue_batches_pkey;
ALTER TABLE IF EXISTS ONLY public.published_entry_references DROP CONSTRAINT IF EXISTS published_entry_references_pkey;
ALTER TABLE IF EXISTS ONLY public.profiles DROP CONSTRAINT IF EXISTS profiles_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.profiles DROP CONSTRAINT IF EXISTS profiles_user_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.profiles DROP CONSTRAINT IF EXISTS profiles_pkey;
ALTER TABLE IF EXISTS ONLY public.permissions DROP CONSTRAINT IF EXISTS permissions_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.permissions DROP CONSTRAINT IF EXISTS permissions_slug_unique;
ALTER TABLE IF EXISTS ONLY public.permissions DROP CONSTRAINT IF EXISTS permissions_pkey;
ALTER TABLE IF EXISTS ONLY public.permissions DROP CONSTRAINT IF EXISTS permissions_name_unique;
ALTER TABLE IF EXISTS ONLY public.permission_audit DROP CONSTRAINT IF EXISTS permission_audit_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.permission_audit DROP CONSTRAINT IF EXISTS permission_audit_pkey;
ALTER TABLE IF EXISTS ONLY public.notifications DROP CONSTRAINT IF EXISTS notifications_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.notifications DROP CONSTRAINT IF EXISTS notifications_pkey;
ALTER TABLE IF EXISTS ONLY public.notification_templates DROP CONSTRAINT IF EXISTS notification_templates_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.notification_templates DROP CONSTRAINT IF EXISTS notification_templates_pkey;
ALTER TABLE IF EXISTS ONLY public.notification_retry_queue DROP CONSTRAINT IF EXISTS notification_retry_queue_pkey;
ALTER TABLE IF EXISTS ONLY public.notification_retry_queue DROP CONSTRAINT IF EXISTS notification_retry_queue_notification_id_channel_unique;
ALTER TABLE IF EXISTS ONLY public.notification_preferences DROP CONSTRAINT IF EXISTS notification_preferences_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.notification_preferences DROP CONSTRAINT IF EXISTS notification_preferences_pkey;
ALTER TABLE IF EXISTS ONLY public.notification_deliveries DROP CONSTRAINT IF EXISTS notification_deliveries_pkey;
ALTER TABLE IF EXISTS ONLY public.navigation_menus DROP CONSTRAINT IF EXISTS navigation_menus_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.navigation_menus DROP CONSTRAINT IF EXISTS navigation_menus_pkey;
ALTER TABLE IF EXISTS ONLY public.navigation_items DROP CONSTRAINT IF EXISTS navigation_items_pkey;
ALTER TABLE IF EXISTS ONLY public.migrations DROP CONSTRAINT IF EXISTS migrations_source_migration_unique;
ALTER TABLE IF EXISTS ONLY public.migrations DROP CONSTRAINT IF EXISTS migrations_pkey;
ALTER TABLE IF EXISTS ONLY public.media_usage DROP CONSTRAINT IF EXISTS media_usage_pkey;
ALTER TABLE IF EXISTS ONLY public.media_meta DROP CONSTRAINT IF EXISTS media_meta_pkey;
ALTER TABLE IF EXISTS ONLY public.media_meta DROP CONSTRAINT IF EXISTS media_meta_blob_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.media_assets DROP CONSTRAINT IF EXISTS media_assets_pkey;
ALTER TABLE IF EXISTS ONLY public.media_assets DROP CONSTRAINT IF EXISTS media_assets_blob_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.locks DROP CONSTRAINT IF EXISTS locks_pkey;
ALTER TABLE IF EXISTS ONLY public.job_executions DROP CONSTRAINT IF EXISTS job_executions_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.job_executions DROP CONSTRAINT IF EXISTS job_executions_pkey;
ALTER TABLE IF EXISTS ONLY public.import_export_reports DROP CONSTRAINT IF EXISTS import_export_reports_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.import_export_reports DROP CONSTRAINT IF EXISTS import_export_reports_pkey;
ALTER TABLE IF EXISTS ONLY public.import_export_reports DROP CONSTRAINT IF EXISTS import_export_reports_job_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.import_export_jobs DROP CONSTRAINT IF EXISTS import_export_jobs_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.import_export_jobs DROP CONSTRAINT IF EXISTS import_export_jobs_pkey;
ALTER TABLE IF EXISTS ONLY public.import_export_files DROP CONSTRAINT IF EXISTS import_export_files_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.import_export_files DROP CONSTRAINT IF EXISTS import_export_files_pkey;
ALTER TABLE IF EXISTS ONLY public.import_export_errors DROP CONSTRAINT IF EXISTS import_export_errors_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.import_export_errors DROP CONSTRAINT IF EXISTS import_export_errors_pkey;
ALTER TABLE IF EXISTS ONLY public.import_export_batches DROP CONSTRAINT IF EXISTS import_export_batches_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.import_export_batches DROP CONSTRAINT IF EXISTS import_export_batches_pkey;
ALTER TABLE IF EXISTS ONLY public.api_rate_limits DROP CONSTRAINT IF EXISTS idx_api_rate_limits_ip_endpoint;
ALTER TABLE IF EXISTS ONLY public.api_metrics_daily DROP CONSTRAINT IF EXISTS idx_api_metrics_daily_date_endpoint_key;
ALTER TABLE IF EXISTS ONLY public.i18n_translations DROP CONSTRAINT IF EXISTS i18n_translations_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.i18n_translations DROP CONSTRAINT IF EXISTS i18n_translations_pkey;
ALTER TABLE IF EXISTS ONLY public.i18n_missing_translations DROP CONSTRAINT IF EXISTS i18n_missing_translations_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.i18n_missing_translations DROP CONSTRAINT IF EXISTS i18n_missing_translations_pkey;
ALTER TABLE IF EXISTS ONLY public.i18n_locales DROP CONSTRAINT IF EXISTS i18n_locales_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.i18n_locales DROP CONSTRAINT IF EXISTS i18n_locales_pkey;
ALTER TABLE IF EXISTS ONLY public.i18n_locales DROP CONSTRAINT IF EXISTS i18n_locales_code_unique;
ALTER TABLE IF EXISTS ONLY public.form_submissions DROP CONSTRAINT IF EXISTS form_submissions_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.form_submissions DROP CONSTRAINT IF EXISTS form_submissions_pkey;
ALTER TABLE IF EXISTS ONLY public.filter_indexes DROP CONSTRAINT IF EXISTS filter_indexes_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.filter_indexes DROP CONSTRAINT IF EXISTS filter_indexes_pkey;
ALTER TABLE IF EXISTS ONLY public.extension_operations DROP CONSTRAINT IF EXISTS extension_operations_pkey;
ALTER TABLE IF EXISTS ONLY public.entry_versions DROP CONSTRAINT IF EXISTS entry_versions_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.entry_versions DROP CONSTRAINT IF EXISTS entry_versions_pkey;
ALTER TABLE IF EXISTS ONLY public.entry_schema_migrations DROP CONSTRAINT IF EXISTS entry_schema_migrations_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.entry_schema_migrations DROP CONSTRAINT IF EXISTS entry_schema_migrations_pkey;
ALTER TABLE IF EXISTS ONLY public.entry_schedules DROP CONSTRAINT IF EXISTS entry_schedules_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.entry_schedules DROP CONSTRAINT IF EXISTS entry_schedules_pkey;
ALTER TABLE IF EXISTS ONLY public.entry_routes DROP CONSTRAINT IF EXISTS entry_routes_pkey;
ALTER TABLE IF EXISTS ONLY public.entry_references DROP CONSTRAINT IF EXISTS entry_references_pkey;
ALTER TABLE IF EXISTS ONLY public.entry_redirects DROP CONSTRAINT IF EXISTS entry_redirects_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.entry_redirects DROP CONSTRAINT IF EXISTS entry_redirects_pkey;
ALTER TABLE IF EXISTS ONLY public.entry_publications DROP CONSTRAINT IF EXISTS entry_publications_pkey;
ALTER TABLE IF EXISTS ONLY public.entry_drafts DROP CONSTRAINT IF EXISTS entry_drafts_pkey;
ALTER TABLE IF EXISTS ONLY public.entries DROP CONSTRAINT IF EXISTS entries_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.entries DROP CONSTRAINT IF EXISTS entries_pkey;
ALTER TABLE IF EXISTS ONLY public.email_templates DROP CONSTRAINT IF EXISTS email_templates_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.email_templates DROP CONSTRAINT IF EXISTS email_templates_template_key_unique;
ALTER TABLE IF EXISTS ONLY public.email_templates DROP CONSTRAINT IF EXISTS email_templates_pkey;
ALTER TABLE IF EXISTS ONLY public.email_settings DROP CONSTRAINT IF EXISTS email_settings_setting_key_unique;
ALTER TABLE IF EXISTS ONLY public.email_settings DROP CONSTRAINT IF EXISTS email_settings_pkey;
ALTER TABLE IF EXISTS ONLY public.content_types DROP CONSTRAINT IF EXISTS content_types_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.content_types DROP CONSTRAINT IF EXISTS content_types_slug_unique;
ALTER TABLE IF EXISTS ONLY public.content_types DROP CONSTRAINT IF EXISTS content_types_pkey;
ALTER TABLE IF EXISTS ONLY public.collection_schema_changes DROP CONSTRAINT IF EXISTS collection_schema_changes_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.collection_schema_changes DROP CONSTRAINT IF EXISTS collection_schema_changes_pkey;
ALTER TABLE IF EXISTS ONLY public.collection_definitions DROP CONSTRAINT IF EXISTS collection_definitions_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.collection_definitions DROP CONSTRAINT IF EXISTS collection_definitions_pkey;
ALTER TABLE IF EXISTS ONLY public.block_types DROP CONSTRAINT IF EXISTS block_types_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.block_types DROP CONSTRAINT IF EXISTS block_types_pkey;
ALTER TABLE IF EXISTS ONLY public.block_type_migrations DROP CONSTRAINT IF EXISTS block_type_migrations_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.block_type_migrations DROP CONSTRAINT IF EXISTS block_type_migrations_pkey;
ALTER TABLE IF EXISTS ONLY public.blobs DROP CONSTRAINT IF EXISTS blobs_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.blobs DROP CONSTRAINT IF EXISTS blobs_pkey;
ALTER TABLE IF EXISTS ONLY public.auth_sessions DROP CONSTRAINT IF EXISTS auth_sessions_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.auth_sessions DROP CONSTRAINT IF EXISTS auth_sessions_pkey;
ALTER TABLE IF EXISTS ONLY public.auth_refresh_tokens DROP CONSTRAINT IF EXISTS auth_refresh_tokens_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.auth_refresh_tokens DROP CONSTRAINT IF EXISTS auth_refresh_tokens_token_hash_unique;
ALTER TABLE IF EXISTS ONLY public.auth_refresh_tokens DROP CONSTRAINT IF EXISTS auth_refresh_tokens_pkey;
ALTER TABLE IF EXISTS ONLY public.audit_logs DROP CONSTRAINT IF EXISTS audit_logs_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.audit_logs DROP CONSTRAINT IF EXISTS audit_logs_pkey;
ALTER TABLE IF EXISTS ONLY public.api_rate_limits DROP CONSTRAINT IF EXISTS api_rate_limits_pkey;
ALTER TABLE IF EXISTS ONLY public.api_metrics DROP CONSTRAINT IF EXISTS api_metrics_pkey;
ALTER TABLE IF EXISTS ONLY public.api_metrics_daily DROP CONSTRAINT IF EXISTS api_metrics_daily_pkey;
ALTER TABLE IF EXISTS ONLY public.api_keys DROP CONSTRAINT IF EXISTS api_keys_uuid_unique;
ALTER TABLE IF EXISTS ONLY public.api_keys DROP CONSTRAINT IF EXISTS api_keys_pkey;
ALTER TABLE IF EXISTS ONLY public.api_keys DROP CONSTRAINT IF EXISTS api_keys_key_hash_unique;
ALTER TABLE IF EXISTS ONLY public.analytics_facts DROP CONSTRAINT IF EXISTS analytics_facts_pkey;
ALTER TABLE IF EXISTS ONLY public.analytics_daily DROP CONSTRAINT IF EXISTS analytics_daily_pkey;
ALTER TABLE IF EXISTS ONLY public.analytics_daily DROP CONSTRAINT IF EXISTS analytics_daily_day_event_subject_unique;
ALTER TABLE IF EXISTS ONLY public.analytics_active_actors DROP CONSTRAINT IF EXISTS analytics_active_actors_day_metric_actor_type_actor_id_hash_uni;
ALTER TABLE IF EXISTS public.workflow_transitions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.workflow_review_states ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.webhook_subscriptions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.webhook_deliveries ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.users ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.user_roles ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.user_permissions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.thallo_tenant_purge_runs ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.thallo_tenant_api_key_bindings ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.thallo_commerce_product_slugs ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.thallo_commerce_product_links ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.thallo_commerce_payment_link_deliveries ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.thallo_commerce_checkout_attempts ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.tenants ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.tenant_roles ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.tenant_role_policy ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.tenant_role_overrides ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.tenant_role_availability ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.tenant_memberships ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.tenant_domains ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.subscriptions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.subscription_v2_preparation ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.subscription_provider_event_receipts ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.subscription_plans ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.subscription_overrides ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.subscription_events ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.starter_provenance ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.signup_verifiers ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.signup_rate_counters ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.signup_intents ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.signup_daily_counters ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.signup_continuations ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.seo_meta ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.scheduled_jobs ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.roles ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.role_permissions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.render_templates ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.render_template_versions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.released_hosts ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.queue_jobs ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.queue_failed_jobs ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.queue_batches ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.published_entry_references ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.profiles ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.permissions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.permission_audit ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.notifications ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.notification_templates ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.notification_retry_queue ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.notification_preferences ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.notification_deliveries ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.navigation_menus ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.navigation_items ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.migrations ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.media_usage ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.media_meta ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.media_assets ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.job_executions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.import_export_reports ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.import_export_jobs ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.import_export_files ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.import_export_errors ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.import_export_batches ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.i18n_translations ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.i18n_missing_translations ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.i18n_locales ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.form_submissions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.filter_indexes ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.extension_operations ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.entry_versions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.entry_schema_migrations ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.entry_schedules ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.entry_routes ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.entry_references ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.entry_redirects ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.entry_publications ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.entry_drafts ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.entries ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.email_templates ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.email_settings ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.content_types ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.collection_schema_changes ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.collection_definitions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.block_types ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.block_type_migrations ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.blobs ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.auth_sessions ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.auth_refresh_tokens ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.audit_logs ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.api_rate_limits ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.api_metrics_daily ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.api_metrics ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.api_keys ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.analytics_facts ALTER COLUMN id DROP DEFAULT;
ALTER TABLE IF EXISTS public.analytics_daily ALTER COLUMN id DROP DEFAULT;
DROP SEQUENCE IF EXISTS public.workflow_transitions_id_seq;
DROP TABLE IF EXISTS public.workflow_transitions;
DROP SEQUENCE IF EXISTS public.workflow_review_states_id_seq;
DROP TABLE IF EXISTS public.workflow_review_states;
DROP SEQUENCE IF EXISTS public.webhook_subscriptions_id_seq;
DROP TABLE IF EXISTS public.webhook_subscriptions;
DROP SEQUENCE IF EXISTS public.webhook_deliveries_id_seq;
DROP TABLE IF EXISTS public.webhook_deliveries;
DROP SEQUENCE IF EXISTS public.users_id_seq;
DROP TABLE IF EXISTS public.users;
DROP SEQUENCE IF EXISTS public.user_roles_id_seq;
DROP TABLE IF EXISTS public.user_roles;
DROP SEQUENCE IF EXISTS public.user_permissions_id_seq;
DROP TABLE IF EXISTS public.user_permissions;
DROP SEQUENCE IF EXISTS public.thallo_tenant_purge_runs_id_seq;
DROP TABLE IF EXISTS public.thallo_tenant_purge_runs;
DROP SEQUENCE IF EXISTS public.thallo_tenant_api_key_bindings_id_seq;
DROP TABLE IF EXISTS public.thallo_tenant_api_key_bindings;
DROP TABLE IF EXISTS public.thallo_system_flags;
DROP SEQUENCE IF EXISTS public.thallo_commerce_product_slugs_id_seq;
DROP TABLE IF EXISTS public.thallo_commerce_product_slugs;
DROP SEQUENCE IF EXISTS public.thallo_commerce_product_links_id_seq;
DROP TABLE IF EXISTS public.thallo_commerce_product_links;
DROP SEQUENCE IF EXISTS public.thallo_commerce_payment_link_deliveries_id_seq;
DROP TABLE IF EXISTS public.thallo_commerce_payment_link_deliveries;
DROP SEQUENCE IF EXISTS public.thallo_commerce_checkout_attempts_id_seq;
DROP TABLE IF EXISTS public.thallo_commerce_checkout_attempts;
DROP SEQUENCE IF EXISTS public.tenants_id_seq;
DROP TABLE IF EXISTS public.tenants;
DROP SEQUENCE IF EXISTS public.tenant_roles_id_seq;
DROP TABLE IF EXISTS public.tenant_roles;
DROP SEQUENCE IF EXISTS public.tenant_role_policy_id_seq;
DROP TABLE IF EXISTS public.tenant_role_policy;
DROP SEQUENCE IF EXISTS public.tenant_role_overrides_id_seq;
DROP TABLE IF EXISTS public.tenant_role_overrides;
DROP SEQUENCE IF EXISTS public.tenant_role_availability_id_seq;
DROP TABLE IF EXISTS public.tenant_role_availability;
DROP SEQUENCE IF EXISTS public.tenant_memberships_id_seq;
DROP TABLE IF EXISTS public.tenant_memberships;
DROP SEQUENCE IF EXISTS public.tenant_domains_id_seq;
DROP TABLE IF EXISTS public.tenant_domains;
DROP SEQUENCE IF EXISTS public.subscriptions_id_seq;
DROP TABLE IF EXISTS public.subscriptions;
DROP SEQUENCE IF EXISTS public.subscription_v2_preparation_id_seq;
DROP TABLE IF EXISTS public.subscription_v2_preparation;
DROP SEQUENCE IF EXISTS public.subscription_provider_event_receipts_id_seq;
DROP TABLE IF EXISTS public.subscription_provider_event_receipts;
DROP SEQUENCE IF EXISTS public.subscription_plans_id_seq;
DROP TABLE IF EXISTS public.subscription_plans;
DROP SEQUENCE IF EXISTS public.subscription_overrides_id_seq;
DROP TABLE IF EXISTS public.subscription_overrides;
DROP SEQUENCE IF EXISTS public.subscription_events_id_seq;
DROP TABLE IF EXISTS public.subscription_events;
DROP SEQUENCE IF EXISTS public.starter_provenance_id_seq;
DROP TABLE IF EXISTS public.starter_provenance;
DROP SEQUENCE IF EXISTS public.signup_verifiers_id_seq;
DROP TABLE IF EXISTS public.signup_verifiers;
DROP SEQUENCE IF EXISTS public.signup_rate_counters_id_seq;
DROP TABLE IF EXISTS public.signup_rate_counters;
DROP SEQUENCE IF EXISTS public.signup_intents_id_seq;
DROP TABLE IF EXISTS public.signup_intents;
DROP SEQUENCE IF EXISTS public.signup_daily_counters_id_seq;
DROP TABLE IF EXISTS public.signup_daily_counters;
DROP SEQUENCE IF EXISTS public.signup_continuations_id_seq;
DROP TABLE IF EXISTS public.signup_continuations;
DROP TABLE IF EXISTS public.settings;
DROP SEQUENCE IF EXISTS public.seo_meta_id_seq;
DROP TABLE IF EXISTS public.seo_meta;
DROP SEQUENCE IF EXISTS public.scheduled_jobs_id_seq;
DROP TABLE IF EXISTS public.scheduled_jobs;
DROP SEQUENCE IF EXISTS public.roles_id_seq;
DROP TABLE IF EXISTS public.roles;
DROP SEQUENCE IF EXISTS public.role_permissions_id_seq;
DROP TABLE IF EXISTS public.role_permissions;
DROP SEQUENCE IF EXISTS public.render_templates_id_seq;
DROP TABLE IF EXISTS public.render_templates;
DROP SEQUENCE IF EXISTS public.render_template_versions_id_seq;
DROP TABLE IF EXISTS public.render_template_versions;
DROP SEQUENCE IF EXISTS public.released_hosts_id_seq;
DROP TABLE IF EXISTS public.released_hosts;
DROP TABLE IF EXISTS public.regions;
DROP SEQUENCE IF EXISTS public.queue_jobs_id_seq;
DROP TABLE IF EXISTS public.queue_jobs;
DROP SEQUENCE IF EXISTS public.queue_failed_jobs_id_seq;
DROP TABLE IF EXISTS public.queue_failed_jobs;
DROP SEQUENCE IF EXISTS public.queue_batches_id_seq;
DROP TABLE IF EXISTS public.queue_batches;
DROP SEQUENCE IF EXISTS public.published_entry_references_id_seq;
DROP TABLE IF EXISTS public.published_entry_references;
DROP SEQUENCE IF EXISTS public.profiles_id_seq;
DROP TABLE IF EXISTS public.profiles;
DROP SEQUENCE IF EXISTS public.permissions_id_seq;
DROP TABLE IF EXISTS public.permissions;
DROP SEQUENCE IF EXISTS public.permission_audit_id_seq;
DROP TABLE IF EXISTS public.permission_audit;
DROP SEQUENCE IF EXISTS public.notifications_id_seq;
DROP TABLE IF EXISTS public.notifications;
DROP SEQUENCE IF EXISTS public.notification_templates_id_seq;
DROP TABLE IF EXISTS public.notification_templates;
DROP SEQUENCE IF EXISTS public.notification_retry_queue_id_seq;
DROP TABLE IF EXISTS public.notification_retry_queue;
DROP SEQUENCE IF EXISTS public.notification_preferences_id_seq;
DROP TABLE IF EXISTS public.notification_preferences;
DROP SEQUENCE IF EXISTS public.notification_deliveries_id_seq;
DROP TABLE IF EXISTS public.notification_deliveries;
DROP SEQUENCE IF EXISTS public.navigation_menus_id_seq;
DROP TABLE IF EXISTS public.navigation_menus;
DROP SEQUENCE IF EXISTS public.navigation_items_id_seq;
DROP TABLE IF EXISTS public.navigation_items;
DROP SEQUENCE IF EXISTS public.migrations_id_seq;
DROP TABLE IF EXISTS public.migrations;
DROP SEQUENCE IF EXISTS public.media_usage_id_seq;
DROP TABLE IF EXISTS public.media_usage;
DROP SEQUENCE IF EXISTS public.media_meta_id_seq;
DROP TABLE IF EXISTS public.media_meta;
DROP SEQUENCE IF EXISTS public.media_assets_id_seq;
DROP TABLE IF EXISTS public.media_assets;
DROP TABLE IF EXISTS public.locks;
DROP SEQUENCE IF EXISTS public.job_executions_id_seq;
DROP TABLE IF EXISTS public.job_executions;
DROP SEQUENCE IF EXISTS public.import_export_reports_id_seq;
DROP TABLE IF EXISTS public.import_export_reports;
DROP SEQUENCE IF EXISTS public.import_export_jobs_id_seq;
DROP TABLE IF EXISTS public.import_export_jobs;
DROP SEQUENCE IF EXISTS public.import_export_files_id_seq;
DROP TABLE IF EXISTS public.import_export_files;
DROP SEQUENCE IF EXISTS public.import_export_errors_id_seq;
DROP TABLE IF EXISTS public.import_export_errors;
DROP SEQUENCE IF EXISTS public.import_export_batches_id_seq;
DROP TABLE IF EXISTS public.import_export_batches;
DROP SEQUENCE IF EXISTS public.i18n_translations_id_seq;
DROP TABLE IF EXISTS public.i18n_translations;
DROP SEQUENCE IF EXISTS public.i18n_missing_translations_id_seq;
DROP TABLE IF EXISTS public.i18n_missing_translations;
DROP SEQUENCE IF EXISTS public.i18n_locales_id_seq;
DROP TABLE IF EXISTS public.i18n_locales;
DROP SEQUENCE IF EXISTS public.form_submissions_id_seq;
DROP TABLE IF EXISTS public.form_submissions;
DROP SEQUENCE IF EXISTS public.filter_indexes_id_seq;
DROP TABLE IF EXISTS public.filter_indexes;
DROP SEQUENCE IF EXISTS public.extension_operations_id_seq;
DROP TABLE IF EXISTS public.extension_operations;
DROP SEQUENCE IF EXISTS public.entry_versions_id_seq;
DROP TABLE IF EXISTS public.entry_versions;
DROP SEQUENCE IF EXISTS public.entry_schema_migrations_id_seq;
DROP TABLE IF EXISTS public.entry_schema_migrations;
DROP SEQUENCE IF EXISTS public.entry_schedules_id_seq;
DROP TABLE IF EXISTS public.entry_schedules;
DROP SEQUENCE IF EXISTS public.entry_routes_id_seq;
DROP TABLE IF EXISTS public.entry_routes;
DROP SEQUENCE IF EXISTS public.entry_references_id_seq;
DROP TABLE IF EXISTS public.entry_references;
DROP SEQUENCE IF EXISTS public.entry_redirects_id_seq;
DROP TABLE IF EXISTS public.entry_redirects;
DROP SEQUENCE IF EXISTS public.entry_publications_id_seq;
DROP TABLE IF EXISTS public.entry_publications;
DROP SEQUENCE IF EXISTS public.entry_drafts_id_seq;
DROP TABLE IF EXISTS public.entry_drafts;
DROP SEQUENCE IF EXISTS public.entries_id_seq;
DROP TABLE IF EXISTS public.entries;
DROP SEQUENCE IF EXISTS public.email_templates_id_seq;
DROP TABLE IF EXISTS public.email_templates;
DROP SEQUENCE IF EXISTS public.email_settings_id_seq;
DROP TABLE IF EXISTS public.email_settings;
DROP SEQUENCE IF EXISTS public.content_types_id_seq;
DROP TABLE IF EXISTS public.content_types;
DROP SEQUENCE IF EXISTS public.collection_schema_changes_id_seq;
DROP TABLE IF EXISTS public.collection_schema_changes;
DROP SEQUENCE IF EXISTS public.collection_definitions_id_seq;
DROP TABLE IF EXISTS public.collection_definitions;
DROP SEQUENCE IF EXISTS public.block_types_id_seq;
DROP TABLE IF EXISTS public.block_types;
DROP SEQUENCE IF EXISTS public.block_type_migrations_id_seq;
DROP TABLE IF EXISTS public.block_type_migrations;
DROP SEQUENCE IF EXISTS public.blobs_id_seq;
DROP TABLE IF EXISTS public.blobs;
DROP SEQUENCE IF EXISTS public.auth_sessions_id_seq;
DROP TABLE IF EXISTS public.auth_sessions;
DROP SEQUENCE IF EXISTS public.auth_refresh_tokens_id_seq;
DROP TABLE IF EXISTS public.auth_refresh_tokens;
DROP SEQUENCE IF EXISTS public.audit_logs_id_seq;
DROP TABLE IF EXISTS public.audit_logs;
DROP SEQUENCE IF EXISTS public.api_rate_limits_id_seq;
DROP TABLE IF EXISTS public.api_rate_limits;
DROP SEQUENCE IF EXISTS public.api_metrics_id_seq;
DROP SEQUENCE IF EXISTS public.api_metrics_daily_id_seq;
DROP TABLE IF EXISTS public.api_metrics_daily;
DROP TABLE IF EXISTS public.api_metrics;
DROP SEQUENCE IF EXISTS public.api_keys_id_seq;
DROP TABLE IF EXISTS public.api_keys;
DROP SEQUENCE IF EXISTS public.analytics_facts_id_seq;
DROP TABLE IF EXISTS public.analytics_facts;
DROP SEQUENCE IF EXISTS public.analytics_daily_id_seq;
DROP TABLE IF EXISTS public.analytics_daily;
DROP TABLE IF EXISTS public.analytics_active_actors;
SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: analytics_active_actors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.analytics_active_actors (
    day date,
    metric character varying(32) DEFAULT 'active_users'::character varying,
    actor_type character varying(16),
    actor_id_hash character varying(64)
);


--
-- Name: analytics_daily; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.analytics_daily (
    id bigint NOT NULL,
    day date,
    event character varying(64),
    subject character varying(191) DEFAULT '__total__'::character varying,
    count bigint DEFAULT 0
);


--
-- Name: analytics_daily_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.analytics_daily_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: analytics_daily_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.analytics_daily_id_seq OWNED BY public.analytics_daily.id;


--
-- Name: analytics_facts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.analytics_facts (
    id bigint NOT NULL,
    occurred_at timestamp without time zone,
    event character varying(64),
    category character varying(32),
    subject_type character varying(32),
    subject_id character varying(191),
    actor_type character varying(16),
    actor_id character varying(64),
    metadata text
);


--
-- Name: analytics_facts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.analytics_facts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: analytics_facts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.analytics_facts_id_seq OWNED BY public.analytics_facts.id;


--
-- Name: api_keys; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_keys (
    id bigint NOT NULL,
    uuid character varying(12),
    user_uuid character varying(12),
    name character varying(255),
    key_prefix character varying(24),
    key_hash character varying(64),
    scopes text,
    allowed_ips text,
    expires_at timestamp without time zone,
    rotated_from_id bigint,
    revoked_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: api_keys_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.api_keys_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: api_keys_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.api_keys_id_seq OWNED BY public.api_keys.id;


--
-- Name: api_metrics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_metrics (
    id bigint NOT NULL,
    uuid character varying(12),
    endpoint character varying(255),
    method character varying(10),
    response_time numeric(10,2),
    status_code integer,
    is_error boolean DEFAULT false,
    "timestamp" timestamp without time zone,
    ip character varying(45)
);


--
-- Name: api_metrics_daily; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_metrics_daily (
    id bigint NOT NULL,
    uuid character varying(12),
    date date,
    endpoint character varying(255),
    method character varying(10),
    endpoint_key character varying(266),
    calls integer DEFAULT 0,
    total_response_time numeric(15,2) DEFAULT 0,
    error_count integer DEFAULT 0,
    last_called timestamp without time zone
);


--
-- Name: api_metrics_daily_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.api_metrics_daily_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: api_metrics_daily_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.api_metrics_daily_id_seq OWNED BY public.api_metrics_daily.id;


--
-- Name: api_metrics_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.api_metrics_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: api_metrics_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.api_metrics_id_seq OWNED BY public.api_metrics.id;


--
-- Name: api_rate_limits; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_rate_limits (
    id bigint NOT NULL,
    uuid character varying(12),
    ip character varying(45),
    endpoint character varying(255),
    remaining integer,
    "limit" integer,
    reset_time timestamp without time zone,
    usage_percentage numeric(5,2)
);


--
-- Name: api_rate_limits_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.api_rate_limits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: api_rate_limits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.api_rate_limits_id_seq OWNED BY public.api_rate_limits.id;


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_logs (
    id bigint NOT NULL,
    uuid character varying(12),
    occurred_at timestamp without time zone,
    actor_uuid character varying(12),
    actor_label character varying(255),
    action character varying(32),
    category character varying(24),
    target_type character varying(64),
    target_uuid character varying(64),
    target_label character varying(255),
    changes jsonb,
    context jsonb,
    created_at timestamp without time zone
);


--
-- Name: audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.audit_logs_id_seq OWNED BY public.audit_logs.id;


--
-- Name: auth_refresh_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auth_refresh_tokens (
    id bigint NOT NULL,
    uuid character varying(12),
    session_uuid character varying(12),
    user_uuid character varying(12),
    token_hash character varying(64),
    status character varying(20) DEFAULT 'active'::character varying,
    parent_uuid character varying(12),
    replaced_by_uuid character varying(12),
    issued_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    expires_at timestamp without time zone,
    consumed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: auth_refresh_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.auth_refresh_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auth_refresh_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.auth_refresh_tokens_id_seq OWNED BY public.auth_refresh_tokens.id;


--
-- Name: auth_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auth_sessions (
    id bigint NOT NULL,
    uuid character varying(12),
    user_uuid character varying(12),
    ip_address character varying(45),
    user_agent text,
    last_seen_at timestamp without time zone,
    expires_at timestamp without time zone,
    revoked_at timestamp without time zone,
    session_version integer DEFAULT 1,
    status character varying(20) DEFAULT 'active'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    provider text DEFAULT 'jwt'::text,
    remember_me boolean DEFAULT false
);


--
-- Name: auth_sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.auth_sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auth_sessions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.auth_sessions_id_seq OWNED BY public.auth_sessions.id;


--
-- Name: blobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.blobs (
    id bigint NOT NULL,
    uuid character varying(12),
    name character varying(255),
    description text,
    mime_type character varying(127),
    size bigint,
    url character varying(2048),
    storage_type character varying(20) DEFAULT 'local'::character varying,
    visibility character varying(10) DEFAULT 'private'::character varying,
    status character varying(20) DEFAULT 'active'::character varying,
    created_by character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone
);


--
-- Name: blobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.blobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: blobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.blobs_id_seq OWNED BY public.blobs.id;


--
-- Name: block_type_migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.block_type_migrations (
    id bigint NOT NULL,
    uuid character varying(12),
    block_type_uuid character varying(12),
    ops jsonb,
    status character varying(16) DEFAULT 'running'::character varying,
    work_items_total integer DEFAULT 0,
    work_items_done integer DEFAULT 0,
    work_items_failed integer DEFAULT 0,
    failure_report jsonb,
    created_by character varying(12),
    created_at timestamp without time zone,
    started_at timestamp without time zone,
    completed_at timestamp without time zone
);


--
-- Name: block_type_migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.block_type_migrations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: block_type_migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.block_type_migrations_id_seq OWNED BY public.block_type_migrations.id;


--
-- Name: block_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.block_types (
    id bigint NOT NULL,
    uuid character varying(12),
    slug character varying(64),
    label character varying(120),
    icon character varying(64),
    category character varying(64),
    description character varying(500),
    schema jsonb,
    active boolean DEFAULT true,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: block_types_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.block_types_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: block_types_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.block_types_id_seq OWNED BY public.block_types.id;


--
-- Name: collection_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.collection_definitions (
    id bigint NOT NULL,
    uuid character varying(24),
    tenant_uuid character varying(12) NOT NULL,
    name character varying(64),
    label character varying(160),
    table_name character varying(63),
    storage_mode character varying(16) DEFAULT 'table'::character varying,
    fields text,
    schema_version integer DEFAULT 1,
    status character varying(16) DEFAULT 'active'::character varying,
    access_policy text,
    field_order text,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: collection_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.collection_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: collection_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.collection_definitions_id_seq OWNED BY public.collection_definitions.id;


--
-- Name: collection_schema_changes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.collection_schema_changes (
    id bigint NOT NULL,
    uuid character varying(24),
    tenant_uuid character varying(12) NOT NULL,
    collection_uuid character varying(24),
    change_type character varying(32),
    payload text,
    actor_type character varying(16),
    actor_id character varying(64),
    destructive boolean DEFAULT false,
    status character varying(16) DEFAULT 'pending'::character varying,
    created_at timestamp without time zone,
    applied_at timestamp without time zone
);


--
-- Name: collection_schema_changes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.collection_schema_changes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: collection_schema_changes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.collection_schema_changes_id_seq OWNED BY public.collection_schema_changes.id;


--
-- Name: content_types; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.content_types (
    id bigint NOT NULL,
    uuid character varying(12),
    slug character varying(160),
    name character varying(200),
    description text,
    cache_ttl integer,
    public_delivery boolean DEFAULT false,
    mount_at_root boolean DEFAULT false,
    status character varying(255) DEFAULT 'active'::character varying,
    schema jsonb,
    schema_version integer DEFAULT 1,
    created_by character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    CONSTRAINT content_types_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'archived'::character varying, 'deleted'::character varying])::text[])))
);


--
-- Name: content_types_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.content_types_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: content_types_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.content_types_id_seq OWNED BY public.content_types.id;


--
-- Name: email_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.email_settings (
    id bigint NOT NULL,
    setting_key character varying(64),
    value text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone
);


--
-- Name: email_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.email_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: email_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.email_settings_id_seq OWNED BY public.email_settings.id;


--
-- Name: email_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.email_templates (
    id bigint NOT NULL,
    uuid character varying(12),
    template_key character varying(191),
    subject text,
    body text,
    updated_by character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone
);


--
-- Name: email_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.email_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: email_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.email_templates_id_seq OWNED BY public.email_templates.id;


--
-- Name: entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entries (
    id bigint NOT NULL,
    uuid character varying(12),
    content_type_uuid character varying(12),
    status character varying(255) DEFAULT 'active'::character varying,
    created_by character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    CONSTRAINT entries_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'archived'::character varying, 'deleted'::character varying])::text[])))
);


--
-- Name: entries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.entries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: entries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.entries_id_seq OWNED BY public.entries.id;


--
-- Name: entry_drafts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entry_drafts (
    id bigint NOT NULL,
    entry_uuid character varying(12),
    locale character varying(16),
    fields jsonb,
    schema_version integer,
    lock_version integer DEFAULT 0,
    updated_by character varying(12),
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: entry_drafts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.entry_drafts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: entry_drafts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.entry_drafts_id_seq OWNED BY public.entry_drafts.id;


--
-- Name: entry_publications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entry_publications (
    id bigint NOT NULL,
    entry_uuid character varying(12),
    locale character varying(16),
    version_uuid character varying(12),
    published_by character varying(12),
    published_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: entry_publications_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.entry_publications_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: entry_publications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.entry_publications_id_seq OWNED BY public.entry_publications.id;


--
-- Name: entry_redirects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entry_redirects (
    id bigint NOT NULL,
    uuid character varying(12),
    content_type_uuid character varying(12),
    locale character varying(16),
    source_slug character varying(200),
    target_content_type_uuid character varying(12),
    target_locale character varying(16),
    target_entry_uuid character varying(12),
    target_url character varying(2048),
    status integer DEFAULT 301,
    origin character varying(16) DEFAULT 'manual'::character varying,
    created_by character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    CONSTRAINT chk_entry_redirect_exactly_one_target CHECK ((((target_entry_uuid IS NOT NULL) AND (target_content_type_uuid IS NOT NULL) AND (target_locale IS NOT NULL) AND (target_url IS NULL)) OR ((target_entry_uuid IS NULL) AND (target_content_type_uuid IS NULL) AND (target_locale IS NULL) AND (target_url IS NOT NULL)))),
    CONSTRAINT chk_entry_redirect_origin CHECK (((origin)::text = ANY ((ARRAY['auto'::character varying, 'manual'::character varying])::text[]))),
    CONSTRAINT chk_entry_redirect_status CHECK ((status = ANY (ARRAY[301, 302, 308])))
);


--
-- Name: entry_redirects_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.entry_redirects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: entry_redirects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.entry_redirects_id_seq OWNED BY public.entry_redirects.id;


--
-- Name: entry_references; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entry_references (
    id bigint NOT NULL,
    source_entry_uuid character varying(12),
    source_field character varying(160),
    target_entry_uuid character varying(12),
    locale character varying(16)
);


--
-- Name: entry_references_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.entry_references_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: entry_references_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.entry_references_id_seq OWNED BY public.entry_references.id;


--
-- Name: entry_routes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entry_routes (
    id bigint NOT NULL,
    entry_uuid character varying(12),
    content_type_uuid character varying(12),
    locale character varying(16),
    slug character varying(200)
);


--
-- Name: entry_routes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.entry_routes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: entry_routes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.entry_routes_id_seq OWNED BY public.entry_routes.id;


--
-- Name: entry_schedules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entry_schedules (
    id bigint NOT NULL,
    uuid character varying(12),
    entry_uuid character varying(12),
    locale character varying(16),
    action character varying(16),
    run_at timestamp without time zone,
    status character varying(16) DEFAULT 'pending'::character varying,
    attempts integer DEFAULT 0,
    failure_reason text,
    created_by character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    canceled_at timestamp without time zone,
    canceled_by character varying(12),
    locked_by character varying(64),
    CONSTRAINT chk_schedule_action CHECK (((action)::text = ANY ((ARRAY['publish'::character varying, 'unpublish'::character varying])::text[]))),
    CONSTRAINT chk_schedule_status CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'processing'::character varying, 'done'::character varying, 'failed'::character varying, 'canceled'::character varying])::text[])))
);


--
-- Name: entry_schedules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.entry_schedules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: entry_schedules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.entry_schedules_id_seq OWNED BY public.entry_schedules.id;


--
-- Name: entry_schema_migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entry_schema_migrations (
    id bigint NOT NULL,
    uuid character varying(12),
    content_type_uuid character varying(12),
    from_version integer,
    to_version integer,
    ops jsonb,
    status character varying(16) DEFAULT 'pending'::character varying,
    work_items_total integer DEFAULT 0,
    work_items_done integer DEFAULT 0,
    work_items_failed integer DEFAULT 0,
    failure_report jsonb,
    created_by character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    started_at timestamp without time zone,
    completed_at timestamp without time zone,
    CONSTRAINT chk_entry_schema_migration_status CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying, 'completed'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: entry_schema_migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.entry_schema_migrations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: entry_schema_migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.entry_schema_migrations_id_seq OWNED BY public.entry_schema_migrations.id;


--
-- Name: entry_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entry_versions (
    id bigint NOT NULL,
    uuid character varying(12),
    entry_uuid character varying(12),
    locale character varying(16),
    version integer,
    fields jsonb,
    schema_version integer,
    created_by character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: entry_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.entry_versions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: entry_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.entry_versions_id_seq OWNED BY public.entry_versions.id;


--
-- Name: extension_operations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.extension_operations (
    id bigint NOT NULL,
    package character varying(191),
    operation character varying(32),
    step character varying(64),
    status character varying(32),
    actor character varying(191),
    failed_migration character varying(255),
    error text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: extension_operations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.extension_operations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: extension_operations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.extension_operations_id_seq OWNED BY public.extension_operations.id;


--
-- Name: filter_indexes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.filter_indexes (
    id bigint NOT NULL,
    uuid character varying(12),
    content_type_uuid character varying(12),
    field character varying(160),
    filter_type character varying(16),
    index_name character varying(80),
    status character varying(16) DEFAULT 'pending'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: filter_indexes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.filter_indexes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: filter_indexes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.filter_indexes_id_seq OWNED BY public.filter_indexes.id;


--
-- Name: form_submissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.form_submissions (
    id bigint NOT NULL,
    uuid character varying(12),
    form_key character varying(64),
    form_name character varying(255),
    source_url character varying(1024),
    fields_snapshot jsonb,
    submitted_values jsonb,
    descriptor_version integer DEFAULT 1,
    status character varying(16) DEFAULT 'unread'::character varying,
    ip character varying(64),
    user_agent character varying(512),
    submitted_at timestamp without time zone
);


--
-- Name: form_submissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.form_submissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: form_submissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.form_submissions_id_seq OWNED BY public.form_submissions.id;


--
-- Name: i18n_locales; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.i18n_locales (
    id bigint NOT NULL,
    uuid character varying(12),
    code character varying(16),
    name character varying(255),
    native_name character varying(255),
    enabled boolean DEFAULT true,
    is_default boolean DEFAULT false,
    fallback_locale character varying(16),
    direction character varying(3) DEFAULT 'ltr'::character varying,
    region character varying(16),
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: i18n_locales_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.i18n_locales_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: i18n_locales_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.i18n_locales_id_seq OWNED BY public.i18n_locales.id;


--
-- Name: i18n_missing_translations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.i18n_missing_translations (
    id bigint NOT NULL,
    uuid character varying(12),
    domain character varying(120),
    locale character varying(16),
    key character varying(255),
    first_seen_at timestamp without time zone,
    last_seen_at timestamp without time zone,
    hits integer DEFAULT 1
);


--
-- Name: i18n_missing_translations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.i18n_missing_translations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: i18n_missing_translations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.i18n_missing_translations_id_seq OWNED BY public.i18n_missing_translations.id;


--
-- Name: i18n_translations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.i18n_translations (
    id bigint NOT NULL,
    uuid character varying(12),
    domain character varying(120),
    locale character varying(16),
    key character varying(255),
    value text,
    status character varying(20) DEFAULT 'active'::character varying,
    source character varying(40),
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: i18n_translations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.i18n_translations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: i18n_translations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.i18n_translations_id_seq OWNED BY public.i18n_translations.id;


--
-- Name: import_export_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.import_export_batches (
    id bigint NOT NULL,
    uuid character varying(12),
    job_uuid character varying(12),
    sequence integer,
    status character varying(20),
    "offset" integer DEFAULT 0,
    "limit" integer DEFAULT 0,
    processed_records integer DEFAULT 0,
    failed_records integer DEFAULT 0,
    attempts integer DEFAULT 0,
    locked_at timestamp without time zone,
    started_at timestamp without time zone,
    finished_at timestamp without time zone,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: import_export_batches_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.import_export_batches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: import_export_batches_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.import_export_batches_id_seq OWNED BY public.import_export_batches.id;


--
-- Name: import_export_errors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.import_export_errors (
    id bigint NOT NULL,
    uuid character varying(12),
    job_uuid character varying(12),
    batch_uuid character varying(12),
    record_number integer,
    severity character varying(20),
    code character varying(120),
    message text,
    context jsonb,
    created_at timestamp without time zone
);


--
-- Name: import_export_errors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.import_export_errors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: import_export_errors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.import_export_errors_id_seq OWNED BY public.import_export_errors.id;


--
-- Name: import_export_files; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.import_export_files (
    id bigint NOT NULL,
    uuid character varying(12),
    job_uuid character varying(12),
    role character varying(40),
    disk character varying(120),
    path character varying(2048),
    mime_type character varying(120),
    size_bytes bigint DEFAULT 0,
    checksum character varying(128),
    created_at timestamp without time zone
);


--
-- Name: import_export_files_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.import_export_files_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: import_export_files_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.import_export_files_id_seq OWNED BY public.import_export_files.id;


--
-- Name: import_export_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.import_export_jobs (
    id bigint NOT NULL,
    uuid character varying(12),
    type character varying(10),
    adapter character varying(120),
    status character varying(20),
    mode character varying(20),
    format character varying(40),
    source_disk character varying(120),
    source_path character varying(2048),
    result_disk character varying(120),
    result_path character varying(2048),
    filters jsonb,
    options jsonb,
    total_records integer DEFAULT 0,
    processed_records integer DEFAULT 0,
    failed_records integer DEFAULT 0,
    error_overflow_count integer DEFAULT 0,
    created_by character varying(12),
    started_at timestamp without time zone,
    finished_at timestamp without time zone,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: import_export_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.import_export_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: import_export_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.import_export_jobs_id_seq OWNED BY public.import_export_jobs.id;


--
-- Name: import_export_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.import_export_reports (
    id bigint NOT NULL,
    uuid character varying(12),
    job_uuid character varying(12),
    summary jsonb,
    report_disk character varying(120),
    report_path character varying(2048),
    failed_records_disk character varying(120),
    failed_records_path character varying(2048),
    created_at timestamp without time zone
);


--
-- Name: import_export_reports_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.import_export_reports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: import_export_reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.import_export_reports_id_seq OWNED BY public.import_export_reports.id;


--
-- Name: job_executions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_executions (
    id bigint NOT NULL,
    uuid character varying(12),
    job_uuid character varying(12),
    status character varying(255),
    started_at timestamp without time zone,
    completed_at timestamp without time zone,
    result text,
    error_message text,
    execution_time numeric(8,2),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT job_executions_status_check CHECK (((status)::text = ANY ((ARRAY['success'::character varying, 'failure'::character varying, 'running'::character varying])::text[])))
);


--
-- Name: job_executions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.job_executions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: job_executions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.job_executions_id_seq OWNED BY public.job_executions.id;


--
-- Name: locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.locks (
    key_id character varying(255) NOT NULL,
    token character varying(255),
    expiration integer
);


--
-- Name: media_assets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media_assets (
    id bigint NOT NULL,
    blob_uuid character varying(12),
    tenant_uuid character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: media_assets_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.media_assets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: media_assets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.media_assets_id_seq OWNED BY public.media_assets.id;


--
-- Name: media_meta; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media_meta (
    id bigint NOT NULL,
    blob_uuid character varying(12),
    tenant_uuid character varying(12),
    alt_text text,
    caption text,
    tags jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone
);


--
-- Name: media_meta_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.media_meta_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: media_meta_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.media_meta_id_seq OWNED BY public.media_meta.id;


--
-- Name: media_usage; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media_usage (
    id bigint NOT NULL,
    blob_uuid character varying(12),
    entry_uuid character varying(64),
    tenant_uuid character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: media_usage_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.media_usage_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: media_usage_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.media_usage_id_seq OWNED BY public.media_usage.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id bigint NOT NULL,
    migration character varying(255),
    batch integer,
    applied_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    checksum character varying(64),
    description text,
    extension character varying(100),
    source character varying(191) DEFAULT 'app'::character varying
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: navigation_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.navigation_items (
    id bigint NOT NULL,
    uuid character varying(12),
    menu_uuid character varying(12),
    parent_uuid character varying(12),
    "position" integer DEFAULT 0,
    kind character varying(8),
    entry_uuid character varying(12),
    url character varying(1024),
    icon character varying(64),
    labels jsonb,
    descriptions jsonb,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: navigation_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.navigation_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: navigation_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.navigation_items_id_seq OWNED BY public.navigation_items.id;


--
-- Name: navigation_menus; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.navigation_menus (
    id bigint NOT NULL,
    uuid character varying(12),
    slug character varying(64),
    name character varying(120),
    lock_version integer DEFAULT 0,
    "position" integer DEFAULT 0,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: navigation_menus_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.navigation_menus_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: navigation_menus_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.navigation_menus_id_seq OWNED BY public.navigation_menus.id;


--
-- Name: notification_deliveries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_deliveries (
    id bigint NOT NULL,
    notification_uuid character varying(12),
    channel character varying(100),
    status character varying(20) DEFAULT 'pending'::character varying,
    attempt_count integer DEFAULT 0,
    last_error text,
    last_attempt_at timestamp without time zone,
    sent_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone
);


--
-- Name: notification_deliveries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notification_deliveries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notification_deliveries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notification_deliveries_id_seq OWNED BY public.notification_deliveries.id;


--
-- Name: notification_preferences; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_preferences (
    id bigint NOT NULL,
    uuid character varying(12),
    notifiable_type character varying(100),
    notifiable_id character varying(255),
    notification_type character varying(100),
    channels jsonb,
    enabled boolean DEFAULT true,
    settings jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone
);


--
-- Name: notification_preferences_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notification_preferences_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notification_preferences_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notification_preferences_id_seq OWNED BY public.notification_preferences.id;


--
-- Name: notification_retry_queue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_retry_queue (
    id integer NOT NULL,
    notification_id character varying(255),
    notifiable_type character varying(100),
    notifiable_id character varying(255),
    channel character varying(50),
    retry_count integer DEFAULT 1,
    retry_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone
);


--
-- Name: notification_retry_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notification_retry_queue_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notification_retry_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notification_retry_queue_id_seq OWNED BY public.notification_retry_queue.id;


--
-- Name: notification_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_templates (
    id bigint NOT NULL,
    uuid character varying(12),
    name character varying(255),
    notification_type character varying(100),
    channel character varying(100),
    content text,
    parameters jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone
);


--
-- Name: notification_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notification_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notification_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notification_templates_id_seq OWNED BY public.notification_templates.id;


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    id bigint NOT NULL,
    uuid character varying(12),
    type character varying(100),
    subject character varying(255),
    idempotency_key character varying(191),
    data jsonb,
    priority character varying(20) DEFAULT 'normal'::character varying,
    notifiable_type character varying(100),
    notifiable_id character varying(255),
    read_at timestamp without time zone,
    scheduled_at timestamp without time zone,
    sent_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone
);


--
-- Name: notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notifications_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notifications_id_seq OWNED BY public.notifications.id;


--
-- Name: permission_audit; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permission_audit (
    id bigint NOT NULL,
    uuid character varying(12),
    action character varying(255),
    subject_type character varying(255),
    subject_uuid character varying(12),
    permission_uuid character varying(12),
    target_uuid character varying(12),
    old_data jsonb,
    new_data jsonb,
    reason text,
    performed_by character varying(12),
    ip_address character varying(45),
    user_agent text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT permission_audit_action_check CHECK (((action)::text = ANY ((ARRAY['GRANTED'::character varying, 'REVOKED'::character varying, 'MODIFIED'::character varying, 'EXPIRED'::character varying])::text[]))),
    CONSTRAINT permission_audit_subject_type_check CHECK (((subject_type)::text = ANY ((ARRAY['user'::character varying, 'role'::character varying])::text[])))
);


--
-- Name: permission_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.permission_audit_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permission_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.permission_audit_id_seq OWNED BY public.permission_audit.id;


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permissions (
    id bigint NOT NULL,
    uuid character varying(12),
    name character varying(100),
    slug character varying(100),
    description text,
    category character varying(50),
    resource_type character varying(100),
    is_system boolean DEFAULT false,
    managed_by character varying(100),
    metadata jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp without time zone
);


--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.profiles (
    id bigint NOT NULL,
    uuid character varying(12),
    user_uuid character varying(12),
    first_name character varying(100),
    last_name character varying(100),
    photo_uuid character varying(12),
    photo_url character varying(255),
    status character varying(20) DEFAULT 'active'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp without time zone
);


--
-- Name: profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.profiles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.profiles_id_seq OWNED BY public.profiles.id;


--
-- Name: published_entry_references; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.published_entry_references (
    id bigint NOT NULL,
    source_entry_uuid character varying(12),
    source_content_type_uuid character varying(12),
    field character varying(160),
    target_entry_uuid character varying(12),
    locale character varying(16)
);


--
-- Name: published_entry_references_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.published_entry_references_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: published_entry_references_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.published_entry_references_id_seq OWNED BY public.published_entry_references.id;


--
-- Name: queue_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.queue_batches (
    id bigint NOT NULL,
    uuid character varying(12),
    name character varying(255),
    total_jobs integer DEFAULT 0,
    pending_jobs integer DEFAULT 0,
    processed_jobs integer DEFAULT 0,
    failed_jobs integer DEFAULT 0,
    cancelled_at timestamp without time zone,
    finished_at timestamp without time zone,
    options jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone
);


--
-- Name: queue_batches_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.queue_batches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: queue_batches_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.queue_batches_id_seq OWNED BY public.queue_batches.id;


--
-- Name: queue_failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.queue_failed_jobs (
    id bigint NOT NULL,
    uuid character varying(12),
    connection character varying(255),
    queue character varying(255),
    payload text,
    exception text,
    batch_uuid character varying(12),
    failed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: queue_failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.queue_failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: queue_failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.queue_failed_jobs_id_seq OWNED BY public.queue_failed_jobs.id;


--
-- Name: queue_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.queue_jobs (
    id bigint NOT NULL,
    uuid character varying(12),
    queue character varying(255) DEFAULT 'default'::character varying,
    payload text,
    attempts integer DEFAULT 0,
    reserved_at timestamp without time zone,
    available_at timestamp without time zone,
    priority integer DEFAULT 0,
    batch_uuid character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: queue_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.queue_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: queue_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.queue_jobs_id_seq OWNED BY public.queue_jobs.id;


--
-- Name: regions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.regions (
    slug character varying(64) NOT NULL,
    blocks jsonb,
    settings jsonb,
    updated_at timestamp without time zone,
    updated_by character varying(12)
);


--
-- Name: released_hosts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.released_hosts (
    id bigint NOT NULL,
    host character varying(255),
    released_by_tenant character varying(12),
    retained_until timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: released_hosts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.released_hosts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: released_hosts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.released_hosts_id_seq OWNED BY public.released_hosts.id;


--
-- Name: render_template_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.render_template_versions (
    id bigint NOT NULL,
    uuid character varying(12),
    template_uuid character varying(12),
    source text,
    created_by character varying(12),
    created_at timestamp without time zone
);


--
-- Name: render_template_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.render_template_versions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: render_template_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.render_template_versions_id_seq OWNED BY public.render_template_versions.id;


--
-- Name: render_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.render_templates (
    id bigint NOT NULL,
    uuid character varying(12),
    theme character varying(64),
    path character varying(190),
    current_version_uuid character varying(12),
    active boolean DEFAULT true,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: render_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.render_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: render_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.render_templates_id_seq OWNED BY public.render_templates.id;


--
-- Name: role_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_permissions (
    id bigint NOT NULL,
    uuid character varying(12),
    role_uuid character varying(12),
    permission_uuid character varying(12),
    resource_filter jsonb,
    constraints jsonb,
    granted_by character varying(12),
    expires_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone
);


--
-- Name: role_permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.role_permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: role_permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.role_permissions_id_seq OWNED BY public.role_permissions.id;


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    uuid character varying(12),
    name character varying(100),
    slug character varying(100),
    description text,
    parent_uuid character varying(12),
    level integer DEFAULT 0,
    is_system boolean DEFAULT false,
    managed_by character varying(100),
    metadata jsonb,
    status character varying(255) DEFAULT 'active'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone,
    CONSTRAINT roles_status_check CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'inactive'::character varying])::text[])))
);


--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: scheduled_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.scheduled_jobs (
    id bigint NOT NULL,
    uuid character varying(12),
    name character varying(255),
    schedule character varying(100),
    handler_class character varying(255),
    parameters jsonb,
    is_enabled boolean DEFAULT true,
    last_run timestamp without time zone,
    next_run timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone
);


--
-- Name: scheduled_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.scheduled_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: scheduled_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.scheduled_jobs_id_seq OWNED BY public.scheduled_jobs.id;


--
-- Name: seo_meta; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seo_meta (
    id bigint NOT NULL,
    entry_uuid character varying(191),
    locale character varying(12),
    title character varying(255),
    description text,
    og_title character varying(255),
    og_description text,
    og_image character varying(1024),
    twitter_card character varying(50),
    robots character varying(50) DEFAULT 'index'::character varying,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: seo_meta_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.seo_meta_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: seo_meta_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.seo_meta_id_seq OWNED BY public.seo_meta.id;


--
-- Name: settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.settings (
    key character varying(120) NOT NULL,
    value text,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: signup_continuations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.signup_continuations (
    id bigint NOT NULL,
    intent_uuid character varying(12),
    current_hash character varying(64),
    previous_hash character varying(64),
    previous_operation_id character varying(96),
    previous_valid_until timestamp without time zone,
    last_operation_id character varying(96),
    last_operation_payload_hash character varying(64),
    last_operation_status character varying(16),
    last_operation_result jsonb,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: signup_continuations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.signup_continuations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: signup_continuations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.signup_continuations_id_seq OWNED BY public.signup_continuations.id;


--
-- Name: signup_daily_counters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.signup_daily_counters (
    id bigint NOT NULL,
    capability character varying(20),
    day character varying(10),
    count integer DEFAULT 0,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: signup_daily_counters_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.signup_daily_counters_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: signup_daily_counters_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.signup_daily_counters_id_seq OWNED BY public.signup_daily_counters.id;


--
-- Name: signup_intents; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.signup_intents (
    id bigint NOT NULL,
    uuid character varying(12),
    kind character varying(16),
    origin character varying(16),
    email character varying(255),
    username character varying(255),
    first_name character varying(100),
    last_name character varying(100),
    password_hash character varying(255),
    tenant_uuid character varying(12),
    desired_slug character varying(64),
    workspace_name character varying(160),
    result_user_uuid character varying(12),
    result_tenant_uuid character varying(12),
    status character varying(20),
    completion_outcome character varying(32),
    request_ip_hash character varying(64),
    expires_at timestamp without time zone,
    consumed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: signup_intents_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.signup_intents_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: signup_intents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.signup_intents_id_seq OWNED BY public.signup_intents.id;


--
-- Name: signup_rate_counters; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.signup_rate_counters (
    id bigint NOT NULL,
    dimension character varying(32),
    bucket_hash character varying(64),
    window_start timestamp without time zone,
    count integer DEFAULT 0,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: signup_rate_counters_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.signup_rate_counters_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: signup_rate_counters_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.signup_rate_counters_id_seq OWNED BY public.signup_rate_counters.id;


--
-- Name: signup_verifiers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.signup_verifiers (
    id bigint NOT NULL,
    intent_uuid character varying(12),
    otp_hash character varying(255),
    attempts integer DEFAULT 0,
    expires_at timestamp without time zone,
    consumed_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: signup_verifiers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.signup_verifiers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: signup_verifiers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.signup_verifiers_id_seq OWNED BY public.signup_verifiers.id;


--
-- Name: starter_provenance; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.starter_provenance (
    id bigint NOT NULL,
    uuid character varying(12),
    tenant_uuid character varying(12),
    definition_kind character varying(32),
    definition_key character varying(255),
    source_id character varying(255),
    fingerprint character varying(64),
    state character varying(16) DEFAULT 'applied'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: starter_provenance_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.starter_provenance_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: starter_provenance_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.starter_provenance_id_seq OWNED BY public.starter_provenance.id;


--
-- Name: subscription_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_events (
    id bigint NOT NULL,
    uuid character varying(12),
    tenant_uuid character varying(64),
    type character varying(40),
    from_status character varying(20),
    to_status character varying(20),
    source character varying(20),
    provider_gateway character varying(50),
    provider_logical_event_key character varying(191),
    data jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    subject_type character varying(10) DEFAULT 'tenant'::character varying NOT NULL,
    subject_uuid character varying(64) DEFAULT ''::character varying NOT NULL
);


--
-- Name: subscription_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscription_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscription_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscription_events_id_seq OWNED BY public.subscription_events.id;


--
-- Name: subscription_overrides; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_overrides (
    id bigint NOT NULL,
    uuid character varying(12),
    tenant_uuid character varying(64),
    entitlement character varying(128),
    value jsonb,
    expires_at timestamp without time zone,
    reason character varying(255),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    subject_type character varying(10) DEFAULT 'tenant'::character varying NOT NULL,
    subject_uuid character varying(64) DEFAULT ''::character varying NOT NULL
);


--
-- Name: subscription_overrides_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscription_overrides_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscription_overrides_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscription_overrides_id_seq OWNED BY public.subscription_overrides.id;


--
-- Name: subscription_plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_plans (
    id bigint NOT NULL,
    uuid character varying(12),
    plan_key character varying(64),
    display_name character varying(120),
    description character varying(255),
    entitlements jsonb,
    provider_price_id character varying(191),
    status character varying(20),
    sort_order integer DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    audience character varying(10) DEFAULT 'tenant'::character varying NOT NULL,
    owner_tenant_uuid character varying(64) DEFAULT ''::character varying NOT NULL,
    provider_identifiers jsonb
);


--
-- Name: subscription_plans_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscription_plans_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscription_plans_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscription_plans_id_seq OWNED BY public.subscription_plans.id;


--
-- Name: subscription_provider_event_receipts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_provider_event_receipts (
    id bigint NOT NULL,
    uuid character varying(12) NOT NULL,
    provider_gateway character varying(50) NOT NULL,
    provider_logical_event_key character varying(191),
    event_type character varying(40) NOT NULL,
    candidate_tenant_uuid character varying(64),
    candidate_subject_type character varying(10),
    candidate_subject_uuid character varying(64),
    candidate_plan_uuid character varying(12),
    tenant_uuid character varying(64),
    subject_type character varying(10),
    subject_uuid character varying(64),
    plan_uuid character varying(12),
    outcome character varying(20) NOT NULL,
    rejection_code character varying(60),
    data jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: subscription_provider_event_receipts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscription_provider_event_receipts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscription_provider_event_receipts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscription_provider_event_receipts_id_seq OWNED BY public.subscription_provider_event_receipts.id;


--
-- Name: subscription_v2_preparation; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_v2_preparation (
    id bigint NOT NULL,
    marker_key character varying(40),
    catalog_signature character varying(64),
    report jsonb,
    prepared_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: subscription_v2_preparation_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscription_v2_preparation_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscription_v2_preparation_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscription_v2_preparation_id_seq OWNED BY public.subscription_v2_preparation.id;


--
-- Name: subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscriptions (
    id bigint NOT NULL,
    uuid character varying(12),
    tenant_uuid character varying(64),
    plan_key character varying(64),
    status character varying(20) DEFAULT 'active'::character varying,
    trial_ends_at timestamp without time zone,
    current_period_end timestamp without time zone,
    grace_ends_at timestamp without time zone,
    canceled_at timestamp without time zone,
    provider_gateway character varying(50),
    provider_customer_id character varying(191),
    provider_subscription_id character varying(191),
    provider_price_id character varying(191),
    metadata jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    subject_type character varying(10) DEFAULT 'tenant'::character varying NOT NULL,
    subject_uuid character varying(64) DEFAULT ''::character varying NOT NULL,
    plan_uuid character varying(12) NOT NULL,
    checkout_origination_uuid character varying(12)
);


--
-- Name: subscriptions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscriptions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscriptions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscriptions_id_seq OWNED BY public.subscriptions.id;


--
-- Name: tenant_domains; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_domains (
    id bigint NOT NULL,
    uuid character varying(12),
    tenant_uuid character varying(12),
    host character varying(255),
    verification_status character varying(16) DEFAULT 'pending'::character varying,
    status character varying(16) DEFAULT 'active'::character varying,
    verification_token character varying(64),
    verified_at timestamp without time zone,
    last_checked_at timestamp without time zone,
    last_check_status character varying(16),
    consecutive_failures integer DEFAULT 0,
    first_failure_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: tenant_domains_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tenant_domains_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tenant_domains_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tenant_domains_id_seq OWNED BY public.tenant_domains.id;


--
-- Name: tenant_memberships; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_memberships (
    id bigint NOT NULL,
    uuid character varying(12),
    tenant_uuid character varying(12),
    user_uuid character varying(12),
    role character varying(64) DEFAULT 'member'::character varying,
    status character varying(32) DEFAULT 'active'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: tenant_memberships_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tenant_memberships_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tenant_memberships_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tenant_memberships_id_seq OWNED BY public.tenant_memberships.id;


--
-- Name: tenant_role_availability; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_role_availability (
    id bigint NOT NULL,
    tenant_uuid character varying(12),
    role character varying(64),
    status character varying(16) DEFAULT 'disabled'::character varying,
    updated_by character varying(12),
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: tenant_role_availability_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tenant_role_availability_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tenant_role_availability_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tenant_role_availability_id_seq OWNED BY public.tenant_role_availability.id;


--
-- Name: tenant_role_overrides; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_role_overrides (
    id bigint NOT NULL,
    tenant_uuid character varying(12),
    role_slug character varying(64),
    capability character varying(96),
    effect character varying(8),
    created_by character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: tenant_role_overrides_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tenant_role_overrides_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tenant_role_overrides_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tenant_role_overrides_id_seq OWNED BY public.tenant_role_overrides.id;


--
-- Name: tenant_role_policy; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_role_policy (
    id bigint NOT NULL,
    tenant_uuid character varying(12),
    version integer DEFAULT 0,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: tenant_role_policy_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tenant_role_policy_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tenant_role_policy_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tenant_role_policy_id_seq OWNED BY public.tenant_role_policy.id;


--
-- Name: tenant_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenant_roles (
    id bigint NOT NULL,
    tenant_uuid character varying(12),
    slug character varying(64),
    name character varying(160),
    status character varying(16) DEFAULT 'active'::character varying,
    created_by character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: tenant_roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tenant_roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tenant_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tenant_roles_id_seq OWNED BY public.tenant_roles.id;


--
-- Name: tenants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tenants (
    id bigint NOT NULL,
    uuid character varying(12),
    slug character varying(255),
    name character varying(255),
    status character varying(32) DEFAULT 'active'::character varying,
    settings text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp without time zone,
    deleted_from_status character varying(32),
    purge_after timestamp without time zone
);


--
-- Name: tenants_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tenants_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tenants_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tenants_id_seq OWNED BY public.tenants.id;


--
-- Name: thallo_commerce_checkout_attempts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.thallo_commerce_checkout_attempts (
    id bigint NOT NULL,
    tenant_uuid character varying(12) DEFAULT ''::character varying,
    idempotency_key character varying(191),
    request_fingerprint character varying(64),
    status character varying(16) DEFAULT 'pending'::character varying,
    order_uuid character varying(12),
    order_ref character varying(191),
    guest_credential_ciphertext text,
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: thallo_commerce_checkout_attempts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.thallo_commerce_checkout_attempts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: thallo_commerce_checkout_attempts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.thallo_commerce_checkout_attempts_id_seq OWNED BY public.thallo_commerce_checkout_attempts.id;


--
-- Name: thallo_commerce_payment_link_deliveries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.thallo_commerce_payment_link_deliveries (
    id bigint NOT NULL,
    uuid character varying(12),
    tenant_uuid character varying(12) DEFAULT ''::character varying,
    idempotency_key character varying(191),
    fingerprint character varying(64),
    order_uuid character varying(12),
    link_uuid character varying(12),
    recipient_hash character varying(64),
    mode character varying(16),
    status character varying(16) DEFAULT 'processing'::character varying,
    error_code character varying(64),
    provider_message_id character varying(191),
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: thallo_commerce_payment_link_deliveries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.thallo_commerce_payment_link_deliveries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: thallo_commerce_payment_link_deliveries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.thallo_commerce_payment_link_deliveries_id_seq OWNED BY public.thallo_commerce_payment_link_deliveries.id;


--
-- Name: thallo_commerce_product_links; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.thallo_commerce_product_links (
    id bigint NOT NULL,
    uuid character varying(12),
    tenant_uuid character varying(12) DEFAULT ''::character varying,
    product_uuid character varying(12),
    entry_uuid character varying(12),
    created_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: thallo_commerce_product_links_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.thallo_commerce_product_links_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: thallo_commerce_product_links_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.thallo_commerce_product_links_id_seq OWNED BY public.thallo_commerce_product_links.id;


--
-- Name: thallo_commerce_product_slugs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.thallo_commerce_product_slugs (
    id bigint NOT NULL,
    tenant_uuid character varying(12) DEFAULT ''::character varying,
    slug character varying(191),
    product_uuid character varying(12),
    created_at timestamp without time zone
);


--
-- Name: thallo_commerce_product_slugs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.thallo_commerce_product_slugs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: thallo_commerce_product_slugs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.thallo_commerce_product_slugs_id_seq OWNED BY public.thallo_commerce_product_slugs.id;


--
-- Name: thallo_system_flags; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.thallo_system_flags (
    key character varying(120) NOT NULL,
    value text,
    updated_at character varying(32)
);


--
-- Name: thallo_tenant_api_key_bindings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.thallo_tenant_api_key_bindings (
    id bigint NOT NULL,
    api_key_uuid character varying(12),
    tenant_uuid character varying(12),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: thallo_tenant_api_key_bindings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.thallo_tenant_api_key_bindings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: thallo_tenant_api_key_bindings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.thallo_tenant_api_key_bindings_id_seq OWNED BY public.thallo_tenant_api_key_bindings.id;


--
-- Name: thallo_tenant_purge_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.thallo_tenant_purge_runs (
    id bigint NOT NULL,
    uuid character varying(12),
    tenant_uuid character varying(12),
    requested_by_uuid character varying(12),
    status character varying(32),
    lease_expires_at timestamp without time zone,
    lease_owner character varying(64),
    attempts integer DEFAULT 0,
    plan jsonb,
    artifacts jsonb,
    failed_handler character varying(120),
    failed_phase character varying(32),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: thallo_tenant_purge_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.thallo_tenant_purge_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: thallo_tenant_purge_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.thallo_tenant_purge_runs_id_seq OWNED BY public.thallo_tenant_purge_runs.id;


--
-- Name: user_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_permissions (
    id bigint NOT NULL,
    uuid character varying(12),
    user_uuid character varying(12),
    permission_uuid character varying(12),
    resource_filter jsonb,
    constraints jsonb,
    granted_by character varying(12),
    expires_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: user_permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_permissions_id_seq OWNED BY public.user_permissions.id;


--
-- Name: user_roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_roles (
    id bigint NOT NULL,
    uuid character varying(12),
    user_uuid character varying(12),
    role_uuid character varying(12),
    scope jsonb,
    granted_by character varying(12),
    expires_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: user_roles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_roles_id_seq OWNED BY public.user_roles.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    uuid character varying(12),
    username character varying(255),
    email character varying(255),
    password character varying(100),
    status character varying(20) DEFAULT 'active'::character varying,
    two_factor_enabled boolean DEFAULT false NOT NULL,
    email_verified_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp without time zone
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: webhook_deliveries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.webhook_deliveries (
    id bigint NOT NULL,
    uuid character varying(32),
    subscription_id bigint,
    event character varying(255),
    payload jsonb,
    status character varying(20) DEFAULT 'pending'::character varying,
    attempts integer DEFAULT 0,
    response_code integer,
    response_body text,
    delivered_at timestamp without time zone,
    next_retry_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: webhook_deliveries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.webhook_deliveries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: webhook_deliveries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.webhook_deliveries_id_seq OWNED BY public.webhook_deliveries.id;


--
-- Name: webhook_subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.webhook_subscriptions (
    id bigint NOT NULL,
    uuid character varying(32),
    url character varying(2048),
    events jsonb,
    secret character varying(255),
    is_active boolean DEFAULT true,
    metadata jsonb,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: webhook_subscriptions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.webhook_subscriptions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: webhook_subscriptions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.webhook_subscriptions_id_seq OWNED BY public.webhook_subscriptions.id;


--
-- Name: workflow_review_states; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workflow_review_states (
    id bigint NOT NULL,
    entry_uuid character varying(12),
    locale character varying(16),
    state character varying(24) DEFAULT 'draft'::character varying,
    submitted_by character varying(12),
    submitted_at timestamp without time zone,
    reviewed_by character varying(12),
    reviewed_at timestamp without time zone,
    updated_at timestamp without time zone
);


--
-- Name: workflow_review_states_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.workflow_review_states_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: workflow_review_states_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.workflow_review_states_id_seq OWNED BY public.workflow_review_states.id;


--
-- Name: workflow_transitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.workflow_transitions (
    id bigint NOT NULL,
    entry_uuid character varying(12),
    locale character varying(16),
    from_state character varying(24),
    to_state character varying(24),
    action character varying(32),
    actor_uuid character varying(12),
    note text,
    metadata jsonb,
    created_at timestamp without time zone
);


--
-- Name: workflow_transitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.workflow_transitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: workflow_transitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.workflow_transitions_id_seq OWNED BY public.workflow_transitions.id;


--
-- Name: analytics_daily id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_daily ALTER COLUMN id SET DEFAULT nextval('public.analytics_daily_id_seq'::regclass);


--
-- Name: analytics_facts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_facts ALTER COLUMN id SET DEFAULT nextval('public.analytics_facts_id_seq'::regclass);


--
-- Name: api_keys id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_keys ALTER COLUMN id SET DEFAULT nextval('public.api_keys_id_seq'::regclass);


--
-- Name: api_metrics id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_metrics ALTER COLUMN id SET DEFAULT nextval('public.api_metrics_id_seq'::regclass);


--
-- Name: api_metrics_daily id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_metrics_daily ALTER COLUMN id SET DEFAULT nextval('public.api_metrics_daily_id_seq'::regclass);


--
-- Name: api_rate_limits id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_rate_limits ALTER COLUMN id SET DEFAULT nextval('public.api_rate_limits_id_seq'::regclass);


--
-- Name: audit_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs ALTER COLUMN id SET DEFAULT nextval('public.audit_logs_id_seq'::regclass);


--
-- Name: auth_refresh_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_refresh_tokens ALTER COLUMN id SET DEFAULT nextval('public.auth_refresh_tokens_id_seq'::regclass);


--
-- Name: auth_sessions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_sessions ALTER COLUMN id SET DEFAULT nextval('public.auth_sessions_id_seq'::regclass);


--
-- Name: blobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blobs ALTER COLUMN id SET DEFAULT nextval('public.blobs_id_seq'::regclass);


--
-- Name: block_type_migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.block_type_migrations ALTER COLUMN id SET DEFAULT nextval('public.block_type_migrations_id_seq'::regclass);


--
-- Name: block_types id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.block_types ALTER COLUMN id SET DEFAULT nextval('public.block_types_id_seq'::regclass);


--
-- Name: collection_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collection_definitions ALTER COLUMN id SET DEFAULT nextval('public.collection_definitions_id_seq'::regclass);


--
-- Name: collection_schema_changes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collection_schema_changes ALTER COLUMN id SET DEFAULT nextval('public.collection_schema_changes_id_seq'::regclass);


--
-- Name: content_types id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.content_types ALTER COLUMN id SET DEFAULT nextval('public.content_types_id_seq'::regclass);


--
-- Name: email_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_settings ALTER COLUMN id SET DEFAULT nextval('public.email_settings_id_seq'::regclass);


--
-- Name: email_templates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_templates ALTER COLUMN id SET DEFAULT nextval('public.email_templates_id_seq'::regclass);


--
-- Name: entries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entries ALTER COLUMN id SET DEFAULT nextval('public.entries_id_seq'::regclass);


--
-- Name: entry_drafts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_drafts ALTER COLUMN id SET DEFAULT nextval('public.entry_drafts_id_seq'::regclass);


--
-- Name: entry_publications id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_publications ALTER COLUMN id SET DEFAULT nextval('public.entry_publications_id_seq'::regclass);


--
-- Name: entry_redirects id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_redirects ALTER COLUMN id SET DEFAULT nextval('public.entry_redirects_id_seq'::regclass);


--
-- Name: entry_references id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_references ALTER COLUMN id SET DEFAULT nextval('public.entry_references_id_seq'::regclass);


--
-- Name: entry_routes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_routes ALTER COLUMN id SET DEFAULT nextval('public.entry_routes_id_seq'::regclass);


--
-- Name: entry_schedules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_schedules ALTER COLUMN id SET DEFAULT nextval('public.entry_schedules_id_seq'::regclass);


--
-- Name: entry_schema_migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_schema_migrations ALTER COLUMN id SET DEFAULT nextval('public.entry_schema_migrations_id_seq'::regclass);


--
-- Name: entry_versions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_versions ALTER COLUMN id SET DEFAULT nextval('public.entry_versions_id_seq'::regclass);


--
-- Name: extension_operations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.extension_operations ALTER COLUMN id SET DEFAULT nextval('public.extension_operations_id_seq'::regclass);


--
-- Name: filter_indexes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.filter_indexes ALTER COLUMN id SET DEFAULT nextval('public.filter_indexes_id_seq'::regclass);


--
-- Name: form_submissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.form_submissions ALTER COLUMN id SET DEFAULT nextval('public.form_submissions_id_seq'::regclass);


--
-- Name: i18n_locales id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_locales ALTER COLUMN id SET DEFAULT nextval('public.i18n_locales_id_seq'::regclass);


--
-- Name: i18n_missing_translations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_missing_translations ALTER COLUMN id SET DEFAULT nextval('public.i18n_missing_translations_id_seq'::regclass);


--
-- Name: i18n_translations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_translations ALTER COLUMN id SET DEFAULT nextval('public.i18n_translations_id_seq'::regclass);


--
-- Name: import_export_batches id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_batches ALTER COLUMN id SET DEFAULT nextval('public.import_export_batches_id_seq'::regclass);


--
-- Name: import_export_errors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_errors ALTER COLUMN id SET DEFAULT nextval('public.import_export_errors_id_seq'::regclass);


--
-- Name: import_export_files id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_files ALTER COLUMN id SET DEFAULT nextval('public.import_export_files_id_seq'::regclass);


--
-- Name: import_export_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_jobs ALTER COLUMN id SET DEFAULT nextval('public.import_export_jobs_id_seq'::regclass);


--
-- Name: import_export_reports id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_reports ALTER COLUMN id SET DEFAULT nextval('public.import_export_reports_id_seq'::regclass);


--
-- Name: job_executions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_executions ALTER COLUMN id SET DEFAULT nextval('public.job_executions_id_seq'::regclass);


--
-- Name: media_assets id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_assets ALTER COLUMN id SET DEFAULT nextval('public.media_assets_id_seq'::regclass);


--
-- Name: media_meta id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_meta ALTER COLUMN id SET DEFAULT nextval('public.media_meta_id_seq'::regclass);


--
-- Name: media_usage id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_usage ALTER COLUMN id SET DEFAULT nextval('public.media_usage_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: navigation_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.navigation_items ALTER COLUMN id SET DEFAULT nextval('public.navigation_items_id_seq'::regclass);


--
-- Name: navigation_menus id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.navigation_menus ALTER COLUMN id SET DEFAULT nextval('public.navigation_menus_id_seq'::regclass);


--
-- Name: notification_deliveries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_deliveries ALTER COLUMN id SET DEFAULT nextval('public.notification_deliveries_id_seq'::regclass);


--
-- Name: notification_preferences id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_preferences ALTER COLUMN id SET DEFAULT nextval('public.notification_preferences_id_seq'::regclass);


--
-- Name: notification_retry_queue id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_retry_queue ALTER COLUMN id SET DEFAULT nextval('public.notification_retry_queue_id_seq'::regclass);


--
-- Name: notification_templates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates ALTER COLUMN id SET DEFAULT nextval('public.notification_templates_id_seq'::regclass);


--
-- Name: notifications id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications ALTER COLUMN id SET DEFAULT nextval('public.notifications_id_seq'::regclass);


--
-- Name: permission_audit id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permission_audit ALTER COLUMN id SET DEFAULT nextval('public.permission_audit_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: profiles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.profiles ALTER COLUMN id SET DEFAULT nextval('public.profiles_id_seq'::regclass);


--
-- Name: published_entry_references id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.published_entry_references ALTER COLUMN id SET DEFAULT nextval('public.published_entry_references_id_seq'::regclass);


--
-- Name: queue_batches id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_batches ALTER COLUMN id SET DEFAULT nextval('public.queue_batches_id_seq'::regclass);


--
-- Name: queue_failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.queue_failed_jobs_id_seq'::regclass);


--
-- Name: queue_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_jobs ALTER COLUMN id SET DEFAULT nextval('public.queue_jobs_id_seq'::regclass);


--
-- Name: released_hosts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.released_hosts ALTER COLUMN id SET DEFAULT nextval('public.released_hosts_id_seq'::regclass);


--
-- Name: render_template_versions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.render_template_versions ALTER COLUMN id SET DEFAULT nextval('public.render_template_versions_id_seq'::regclass);


--
-- Name: render_templates id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.render_templates ALTER COLUMN id SET DEFAULT nextval('public.render_templates_id_seq'::regclass);


--
-- Name: role_permissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions ALTER COLUMN id SET DEFAULT nextval('public.role_permissions_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: scheduled_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scheduled_jobs ALTER COLUMN id SET DEFAULT nextval('public.scheduled_jobs_id_seq'::regclass);


--
-- Name: seo_meta id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_meta ALTER COLUMN id SET DEFAULT nextval('public.seo_meta_id_seq'::regclass);


--
-- Name: signup_continuations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_continuations ALTER COLUMN id SET DEFAULT nextval('public.signup_continuations_id_seq'::regclass);


--
-- Name: signup_daily_counters id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_daily_counters ALTER COLUMN id SET DEFAULT nextval('public.signup_daily_counters_id_seq'::regclass);


--
-- Name: signup_intents id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_intents ALTER COLUMN id SET DEFAULT nextval('public.signup_intents_id_seq'::regclass);


--
-- Name: signup_rate_counters id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_rate_counters ALTER COLUMN id SET DEFAULT nextval('public.signup_rate_counters_id_seq'::regclass);


--
-- Name: signup_verifiers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_verifiers ALTER COLUMN id SET DEFAULT nextval('public.signup_verifiers_id_seq'::regclass);


--
-- Name: starter_provenance id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.starter_provenance ALTER COLUMN id SET DEFAULT nextval('public.starter_provenance_id_seq'::regclass);


--
-- Name: subscription_events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_events ALTER COLUMN id SET DEFAULT nextval('public.subscription_events_id_seq'::regclass);


--
-- Name: subscription_overrides id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_overrides ALTER COLUMN id SET DEFAULT nextval('public.subscription_overrides_id_seq'::regclass);


--
-- Name: subscription_plans id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_plans ALTER COLUMN id SET DEFAULT nextval('public.subscription_plans_id_seq'::regclass);


--
-- Name: subscription_provider_event_receipts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_provider_event_receipts ALTER COLUMN id SET DEFAULT nextval('public.subscription_provider_event_receipts_id_seq'::regclass);


--
-- Name: subscription_v2_preparation id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_v2_preparation ALTER COLUMN id SET DEFAULT nextval('public.subscription_v2_preparation_id_seq'::regclass);


--
-- Name: subscriptions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions ALTER COLUMN id SET DEFAULT nextval('public.subscriptions_id_seq'::regclass);


--
-- Name: tenant_domains id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_domains ALTER COLUMN id SET DEFAULT nextval('public.tenant_domains_id_seq'::regclass);


--
-- Name: tenant_memberships id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_memberships ALTER COLUMN id SET DEFAULT nextval('public.tenant_memberships_id_seq'::regclass);


--
-- Name: tenant_role_availability id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_role_availability ALTER COLUMN id SET DEFAULT nextval('public.tenant_role_availability_id_seq'::regclass);


--
-- Name: tenant_role_overrides id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_role_overrides ALTER COLUMN id SET DEFAULT nextval('public.tenant_role_overrides_id_seq'::regclass);


--
-- Name: tenant_role_policy id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_role_policy ALTER COLUMN id SET DEFAULT nextval('public.tenant_role_policy_id_seq'::regclass);


--
-- Name: tenant_roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_roles ALTER COLUMN id SET DEFAULT nextval('public.tenant_roles_id_seq'::regclass);


--
-- Name: tenants id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants ALTER COLUMN id SET DEFAULT nextval('public.tenants_id_seq'::regclass);


--
-- Name: thallo_commerce_checkout_attempts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_checkout_attempts ALTER COLUMN id SET DEFAULT nextval('public.thallo_commerce_checkout_attempts_id_seq'::regclass);


--
-- Name: thallo_commerce_payment_link_deliveries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_payment_link_deliveries ALTER COLUMN id SET DEFAULT nextval('public.thallo_commerce_payment_link_deliveries_id_seq'::regclass);


--
-- Name: thallo_commerce_product_links id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_product_links ALTER COLUMN id SET DEFAULT nextval('public.thallo_commerce_product_links_id_seq'::regclass);


--
-- Name: thallo_commerce_product_slugs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_product_slugs ALTER COLUMN id SET DEFAULT nextval('public.thallo_commerce_product_slugs_id_seq'::regclass);


--
-- Name: thallo_tenant_api_key_bindings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_tenant_api_key_bindings ALTER COLUMN id SET DEFAULT nextval('public.thallo_tenant_api_key_bindings_id_seq'::regclass);


--
-- Name: thallo_tenant_purge_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_tenant_purge_runs ALTER COLUMN id SET DEFAULT nextval('public.thallo_tenant_purge_runs_id_seq'::regclass);


--
-- Name: user_permissions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_permissions ALTER COLUMN id SET DEFAULT nextval('public.user_permissions_id_seq'::regclass);


--
-- Name: user_roles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles ALTER COLUMN id SET DEFAULT nextval('public.user_roles_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: webhook_deliveries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhook_deliveries ALTER COLUMN id SET DEFAULT nextval('public.webhook_deliveries_id_seq'::regclass);


--
-- Name: webhook_subscriptions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhook_subscriptions ALTER COLUMN id SET DEFAULT nextval('public.webhook_subscriptions_id_seq'::regclass);


--
-- Name: workflow_review_states id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_review_states ALTER COLUMN id SET DEFAULT nextval('public.workflow_review_states_id_seq'::regclass);


--
-- Name: workflow_transitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_transitions ALTER COLUMN id SET DEFAULT nextval('public.workflow_transitions_id_seq'::regclass);


--
-- Data for Name: analytics_active_actors; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.analytics_active_actors (day, metric, actor_type, actor_id_hash) FROM stdin;
\.


--
-- Data for Name: analytics_daily; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.analytics_daily (id, day, event, subject, count) FROM stdin;
\.


--
-- Data for Name: analytics_facts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.analytics_facts (id, occurred_at, event, category, subject_type, subject_id, actor_type, actor_id, metadata) FROM stdin;
\.


--
-- Data for Name: api_keys; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.api_keys (id, uuid, user_uuid, name, key_prefix, key_hash, scopes, allowed_ips, expires_at, rotated_from_id, revoked_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: api_metrics; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.api_metrics (id, uuid, endpoint, method, response_time, status_code, is_error, "timestamp", ip) FROM stdin;
\.


--
-- Data for Name: api_metrics_daily; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.api_metrics_daily (id, uuid, date, endpoint, method, endpoint_key, calls, total_response_time, error_count, last_called) FROM stdin;
\.


--
-- Data for Name: api_rate_limits; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.api_rate_limits (id, uuid, ip, endpoint, remaining, "limit", reset_time, usage_percentage) FROM stdin;
\.


--
-- Data for Name: audit_logs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.audit_logs (id, uuid, occurred_at, actor_uuid, actor_label, action, category, target_type, target_uuid, target_label, changes, context, created_at) FROM stdin;
1	t6zoiOQcQ1Rt	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b00602e7.80339075", "permission_name": "View audit log", "permission_slug": "audit.view", "permission_uuid": "VwSqhucz6wW0"}	2026-09-14 07:35:12
2	ld1y34vDX5VT	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b00a1e70.95784248", "permission_name": "Manage collection data", "permission_slug": "collections.data.manage", "permission_uuid": "8IeMW7hL9Xhh"}	2026-09-14 07:35:12
3	2tWxgR4kPe3q	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b00edfe7.40632620", "permission_name": "Manage collection schemas", "permission_slug": "collections.schema.manage", "permission_uuid": "ivirbFRuDfQ4"}	2026-09-14 07:35:12
4	lFYpRbFeW3ue	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b013b307.31503999", "permission_name": "Manage collections", "permission_slug": "collections.manage", "permission_uuid": "OlvPuhy15xdo"}	2026-09-14 07:35:12
5	JYyZlmoIRZma	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b0175aa5.42023108", "permission_name": "Manage commerce", "permission_slug": "commerce.manage", "permission_uuid": "KeedTSS6qwSM"}	2026-09-14 07:35:12
6	E3IfruVm6cnD	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b01a8619.02324434", "permission_name": "View commerce", "permission_slug": "commerce.view", "permission_uuid": "AriSY4xYCqIv"}	2026-09-14 07:35:12
7	M5zhMaeP0Z21	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b01c7bb7.57981642", "permission_name": "Manage content models", "permission_slug": "content.manage", "permission_uuid": "GiNNquK2kSHi"}	2026-09-14 07:35:12
8	R8URYREKNdpy	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b01f2819.57678305", "permission_name": "Manage routes", "permission_slug": "content.routes", "permission_uuid": "2ARBxHM5ZbVU"}	2026-09-14 07:35:12
9	1tRxycNuYnm0	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b020f0e4.11516432", "permission_name": "Publish content", "permission_slug": "content.publish", "permission_uuid": "zJrRDCSl49tj"}	2026-09-14 07:35:12
10	EZajB4Q9jelX	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b02384f2.76161535", "permission_name": "Manage email templates & settings", "permission_slug": "email.templates.manage", "permission_uuid": "RCgaM4KnVpJR"}	2026-09-14 07:35:12
11	2JoW1T65ktXT	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b02647f5.02144681", "permission_name": "Manage navigation", "permission_slug": "navigation.manage", "permission_uuid": "XrX3uSO1stge"}	2026-09-14 07:35:12
12	XcMsyFQ5YgNa	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b0291112.18569282", "permission_name": "Manage SEO", "permission_slug": "seo.manage", "permission_uuid": "qbK1kmqm8BAA"}	2026-09-14 07:35:12
13	Ef3Z4o00Dspw	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b02c9e09.39867482", "permission_name": "Manage templates", "permission_slug": "templates.manage", "permission_uuid": "lM02C0kCrjbj"}	2026-09-14 07:35:12
14	p7xw7cRiLbdz	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b02e9ac1.09671911", "permission_name": "Export translation catalogs", "permission_slug": "i18n.export", "permission_uuid": "vArMNEpaVVQ3"}	2026-09-14 07:35:12
15	MytX2qcEW4Eh	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b030d034.21251831", "permission_name": "Import translation catalogs", "permission_slug": "i18n.import", "permission_uuid": "seH0lWtn0PHG"}	2026-09-14 07:35:12
16	QCuyRWwdiQY5	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b034bef6.61449387", "permission_name": "Manage locales and translations", "permission_slug": "i18n.manage", "permission_uuid": "nxqneH6MLAd6"}	2026-09-14 07:35:12
17	EOChCRgnYZit	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b0369485.63280473", "permission_name": "View locales and translations", "permission_slug": "i18n.view", "permission_uuid": "KP9botZO5Trb"}	2026-09-14 07:35:12
18	xIVD1a507cal	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b03829c4.53029001", "permission_name": "Cancel import/export jobs", "permission_slug": "import_export.cancel", "permission_uuid": "9nFbpStH5Q3D"}	2026-09-14 07:35:12
19	zc4mbRpvboKf	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b03ac4d5.13605539", "permission_name": "Export failed import/export records", "permission_slug": "import_export.export_failed_records", "permission_uuid": "JoBLoJPShi4M"}	2026-09-14 07:35:12
20	IWYnTaVHymfk	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b03c4cc1.54650780", "permission_name": "Manage all import/export jobs", "permission_slug": "import_export.manage_all", "permission_uuid": "vpVjdx5VKoXG"}	2026-09-14 07:35:12
21	69bcyKW5XKlG	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b03e63d8.26079572", "permission_name": "Retry import/export jobs", "permission_slug": "import_export.retry", "permission_uuid": "0FPwSMQLjndL"}	2026-09-14 07:35:12
22	FgEEjmeXPiBf	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b0406b00.63563060", "permission_name": "Run exports", "permission_slug": "import_export.run_export", "permission_uuid": "DlIZgFbKA58s"}	2026-09-14 07:35:12
23	iMKzEsxONtTn	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b04497c7.56994992", "permission_name": "Run imports", "permission_slug": "import_export.run_import", "permission_uuid": "hDzXCjPVoVGy"}	2026-09-14 07:35:12
24	osvNiPIvQYuX	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b045a195.25811468", "permission_name": "View import/export jobs", "permission_slug": "import_export.view", "permission_uuid": "poImLKAQ9THQ"}	2026-09-14 07:35:12
25	kXyh6uzMykqv	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b046df88.58200160", "permission_name": "Review workflow", "permission_slug": "workflow.review", "permission_uuid": "TxO9YdE4kSLK"}	2026-09-14 07:35:12
26	djZKr3i5ueaH	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b04a7d21.08441980", "permission_name": "View analytics", "permission_slug": "analytics.read", "permission_uuid": "uo1RPd6mEUw3"}	2026-09-14 07:35:12
27	qNcyClxNNBLy	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b04c6421.69915822", "permission_name": "Publish without an approved review", "permission_slug": "workflow.bypass", "permission_uuid": "8NZWkNhSD52w"}	2026-09-14 07:35:12
28	yincBcpl1UA5	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b04dc1b6.43690030", "permission_name": "Manage billing", "permission_slug": "billing.manage", "permission_uuid": "olMAQPIKl0ey"}	2026-09-14 07:35:12
29	VkkontyTinSb	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b04ebae7.53981495", "permission_name": "Manage domains", "permission_slug": "tenant.domains.manage", "permission_uuid": "3wyvmGiYSslR"}	2026-09-14 07:35:12
30	14V4wsl1ahEP	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b04fafc9.19875770", "permission_name": "Manage members", "permission_slug": "tenant.members.manage", "permission_uuid": "imfu6FciXZM1"}	2026-09-14 07:35:12
31	4x4rKlqWUrVh	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	1qAN65zVgXIs	Superuser	\N	{"event_id": "evt_6aa7a3b0508a28.10988107", "permission_name": "Manage roles", "permission_slug": "tenant.roles.manage", "permission_uuid": "nbdjhUp9D7rW"}	2026-09-14 07:35:12
32	6yRHSQYt4AGh	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b0520ca4.62392749", "permission_name": "View audit log", "permission_slug": "audit.view", "permission_uuid": "VwSqhucz6wW0"}	2026-09-14 07:35:12
33	PjAVAjbc3bRH	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b0531d51.80328515", "permission_name": "View commerce", "permission_slug": "commerce.view", "permission_uuid": "AriSY4xYCqIv"}	2026-09-14 07:35:12
34	QAq71kIOHxzj	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b0540065.75662022", "permission_name": "Manage templates", "permission_slug": "templates.manage", "permission_uuid": "lM02C0kCrjbj"}	2026-09-14 07:35:12
35	uhGCWRUhrR7q	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b0550350.41629909", "permission_name": "Cancel import/export jobs", "permission_slug": "import_export.cancel", "permission_uuid": "9nFbpStH5Q3D"}	2026-09-14 07:35:12
36	DcPXIAEKZ7md	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b055ed94.47516327", "permission_name": "Export failed import/export records", "permission_slug": "import_export.export_failed_records", "permission_uuid": "JoBLoJPShi4M"}	2026-09-14 07:35:12
37	I5PjqzJggRSu	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b056ece7.61563509", "permission_name": "Manage all import/export jobs", "permission_slug": "import_export.manage_all", "permission_uuid": "vpVjdx5VKoXG"}	2026-09-14 07:35:12
38	KHSSIjFPuN8R	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b057d878.34841170", "permission_name": "Retry import/export jobs", "permission_slug": "import_export.retry", "permission_uuid": "0FPwSMQLjndL"}	2026-09-14 07:35:12
39	Ow61uxO6GmMn	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b058c2c3.52011120", "permission_name": "Run exports", "permission_slug": "import_export.run_export", "permission_uuid": "DlIZgFbKA58s"}	2026-09-14 07:35:12
40	FTRN3GcFhatR	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b059d847.92909768", "permission_name": "Run imports", "permission_slug": "import_export.run_import", "permission_uuid": "hDzXCjPVoVGy"}	2026-09-14 07:35:12
41	vFpGxiXTJO25	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b05ac313.52359236", "permission_name": "View import/export jobs", "permission_slug": "import_export.view", "permission_uuid": "poImLKAQ9THQ"}	2026-09-14 07:35:12
42	VeLg90dM5qbV	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b05c2806.46961704", "permission_name": "Access any tenant", "permission_slug": "tenancy.access_any", "permission_uuid": "Xs8M7BYRm3bk"}	2026-09-14 07:35:12
43	Dvvd3OUbeo1B	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b06199b9.46678092", "permission_name": "Manage tenants", "permission_slug": "tenancy.manage", "permission_uuid": "MVrj9UkpVPgE"}	2026-09-14 07:35:12
44	qrriBVjSMrjq	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b062fc78.86256836", "permission_name": "Manage billing", "permission_slug": "billing.manage", "permission_uuid": "olMAQPIKl0ey"}	2026-09-14 07:35:12
45	TwD3bNcCh5m7	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b0648f69.20617104", "permission_name": "Manage domains", "permission_slug": "tenant.domains.manage", "permission_uuid": "3wyvmGiYSslR"}	2026-09-14 07:35:12
46	NL5hwjYr7qkh	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b0665d65.72284134", "permission_name": "Manage members", "permission_slug": "tenant.members.manage", "permission_uuid": "imfu6FciXZM1"}	2026-09-14 07:35:12
47	liFOWVhmyWdF	2026-09-14 07:35:12	\N	system	role_permission_assigned	rbac	role	3YlQHTbZnj9h	Administrator	\N	{"event_id": "evt_6aa7a3b0678dd1.59614839", "permission_name": "Manage roles", "permission_slug": "tenant.roles.manage", "permission_uuid": "nbdjhUp9D7rW"}	2026-09-14 07:35:12
\.


--
-- Data for Name: auth_refresh_tokens; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.auth_refresh_tokens (id, uuid, session_uuid, user_uuid, token_hash, status, parent_uuid, replaced_by_uuid, issued_at, expires_at, consumed_at, created_at) FROM stdin;
\.


--
-- Data for Name: auth_sessions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.auth_sessions (id, uuid, user_uuid, ip_address, user_agent, last_seen_at, expires_at, revoked_at, session_version, status, created_at, updated_at, provider, remember_me) FROM stdin;
\.


--
-- Data for Name: blobs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.blobs (id, uuid, name, description, mime_type, size, url, storage_type, visibility, status, created_by, created_at, updated_at, deleted_at) FROM stdin;
1	COUOJiK8gwgW	pixel.png	\N	image/png	70	fixture/pixel.png	uploads	public	active	user00000001	2026-09-14 07:35:13	\N	\N
\.


--
-- Data for Name: block_type_migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.block_type_migrations (id, uuid, block_type_uuid, ops, status, work_items_total, work_items_done, work_items_failed, failure_report, created_by, created_at, started_at, completed_at) FROM stdin;
\.


--
-- Data for Name: block_types; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.block_types (id, uuid, slug, label, icon, category, description, schema, active, created_at, updated_at) FROM stdin;
1	pvW0226Ah2Db	navigation	Navigation	i-lucide-menu	Layout	Links from a navigation menu (structured source — pick a menu, not links).	[{"name": "menu", "type": "string", "pattern": "[a-z0-9]+(-[a-z0-9]+)*", "required": true}, {"enum": ["horizontal", "vertical"], "name": "orientation", "type": "enum"}, {"enum": ["start", "center", "end"], "name": "align", "type": "enum"}, {"enum": ["sm", "md", "lg"], "name": "size", "type": "enum"}, {"enum": ["pill", "link"], "name": "variant", "type": "enum"}, {"enum": ["primary", "neutral"], "name": "color", "type": "enum"}, {"enum": ["none", "underline", "bar"], "name": "highlight", "type": "enum"}, {"enum": ["dropdown", "columns"], "name": "submenu_layout", "type": "enum"}, {"enum": ["chevron-down", "chevron-right", "plus", "none"], "name": "submenu_icon", "type": "enum"}, {"enum": ["hover", "click"], "name": "submenu_trigger", "type": "enum"}, {"name": "aria_label", "type": "string", "label": "Navigation label (assistive)"}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
2	nI6btS6VqvOu	section	Section	i-lucide-rows-3	Layout	A titled band of content with a background style.	[{"name": "headline", "type": "string"}, {"name": "title", "type": "string"}, {"name": "description", "type": "text"}, {"enum": ["none", "muted", "subtle", "inverted"], "name": "background", "type": "enum"}, {"enum": ["vertical", "horizontal"], "name": "orientation", "type": "enum"}, {"name": "reverse", "type": "boolean"}, {"name": "links", "type": "blocks", "block_types": ["button"]}, {"name": "content", "type": "blocks"}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
3	HT1Ohj8ezC1Q	container	Container	i-lucide-square-dashed	Layout	Free-form styled wrapper: background color/image, overlay, width and padding.	[{"name": "background_color", "type": "string", "group": "Background", "format": "color", "pattern": "#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?"}, {"name": "background_image", "type": "asset", "group": "Background"}, {"name": "background_video", "type": "asset", "group": "Background"}, {"name": "background_video_url", "type": "string", "group": "Background"}, {"enum": ["cover", "contain", "auto"], "name": "bg_size", "type": "enum", "group": "Background"}, {"enum": ["no-repeat", "repeat"], "name": "bg_repeat", "type": "enum", "group": "Background"}, {"enum": ["center", "top", "bottom", "left", "right"], "name": "bg_position", "type": "enum", "group": "Background"}, {"name": "overlay_color", "type": "string", "group": "Background", "format": "color", "pattern": "#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?"}, {"max": 100, "min": 0, "name": "overlay_opacity", "type": "number", "group": "Background"}, {"enum": ["full", "contained", "narrow"], "name": "width", "type": "enum", "group": "Layout"}, {"min": 0, "name": "max_width", "type": "number", "group": "Layout"}, {"enum": ["auto", "half", "screen"], "name": "min_height", "type": "enum", "group": "Layout"}, {"min": 0, "name": "min_height_px", "type": "number", "group": "Layout"}, {"enum": ["top", "center", "bottom"], "name": "content_align", "type": "enum", "group": "Layout"}, {"enum": ["block", "flex"], "name": "layout", "type": "enum", "group": "Layout"}, {"enum": ["row", "column", "row-reverse", "column-reverse"], "name": "flex_direction", "type": "enum", "group": "Layout"}, {"enum": ["start", "center", "end", "between", "around", "evenly"], "name": "justify", "type": "enum", "group": "Layout"}, {"enum": ["start", "center", "end", "stretch"], "name": "align_items", "type": "enum", "group": "Layout"}, {"min": 0, "name": "gap", "type": "number", "group": "Layout"}, {"enum": ["nowrap", "wrap"], "name": "flex_wrap", "type": "enum", "group": "Layout"}, {"enum": ["none", "small", "medium", "large"], "name": "padding_preset", "type": "enum", "group": "Spacing"}, {"name": "padding", "type": "box", "group": "Spacing"}, {"name": "margin", "type": "box", "group": "Spacing"}, {"name": "radius", "type": "box", "group": "Border"}, {"enum": ["none", "solid", "dashed", "dotted"], "name": "border_style", "type": "enum", "group": "Border"}, {"min": 0, "name": "border_width", "type": "number", "group": "Border"}, {"name": "border_color", "type": "string", "group": "Border", "format": "color", "pattern": "#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?"}, {"enum": ["none", "2xs", "xs", "sm", "md", "lg", "xl", "2xl"], "name": "shadow", "type": "enum", "group": "Effects"}, {"name": "content", "type": "blocks"}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
4	dOdH9fXSwwLg	grid	Grid	i-lucide-layout-grid	Layout	A responsive wrapping grid (or masonry flow) of blocks.	[{"enum": ["1", "2", "3", "4"], "name": "columns", "type": "enum"}, {"enum": ["grid", "masonry"], "name": "flow", "type": "enum"}, {"enum": ["small", "medium", "large"], "name": "gap", "type": "enum"}, {"name": "items", "type": "blocks"}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
5	ivFcJUfCJlHg	separator	Separator	i-lucide-separator-horizontal	Layout	A horizontal rule, optionally with a centered label and icon.	[{"name": "label", "type": "string"}, {"enum": ["solid", "dashed", "dotted"], "name": "type", "type": "enum"}, {"enum": ["xs", "sm", "md", "lg", "xl"], "name": "size", "type": "enum"}, {"name": "icon", "type": "string", "format": "icon", "pattern": "[a-z0-9]+(-[a-z0-9]+)*"}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
6	nVN4DoTO5OT4	footer	Footer	i-lucide-panels-top-left	Layout	A footer bar: copyright, links and social, over an optional top band.	[{"name": "top", "type": "blocks"}, {"name": "copyright", "type": "blocks"}, {"name": "links", "type": "blocks", "block_types": ["links", "navigation"]}, {"name": "social", "type": "blocks", "block_types": ["social_links"]}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
7	zmf1tikmpP1C	card	Card	i-lucide-rectangle-horizontal	Content	A content card: icon, title, description and nested blocks.	[{"name": "icon", "type": "string", "format": "icon", "pattern": "[a-z0-9]+(-[a-z0-9]+)*"}, {"name": "title", "type": "string"}, {"name": "description", "type": "text"}, {"enum": ["outline", "solid", "soft", "subtle", "ghost", "naked"], "name": "variant", "type": "enum"}, {"enum": ["vertical", "horizontal"], "name": "orientation", "type": "enum"}, {"name": "reverse", "type": "boolean"}, {"name": "body", "type": "blocks"}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
8	oBkZBfyjwTmW	accordion	Accordion	i-lucide-list-collapse	Content	A stack of expandable question/answer items.	[{"name": "title", "type": "string"}, {"name": "multiple", "type": "boolean"}, {"name": "items", "type": "blocks", "block_types": ["accordion_item"]}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
9	8ECSUEf4C6yn	collapsible	Collapsible	i-lucide-chevrons-up-down	Content	A single show/hide disclosure wrapping nested blocks.	[{"name": "label", "type": "string"}, {"name": "open", "type": "boolean"}, {"name": "content", "type": "blocks"}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
10	cB8Uo7Z2zdMq	links	Links	i-lucide-list	Content	A vertical list of navigation links with an optional title.	[{"name": "title", "type": "string"}, {"name": "items", "type": "json"}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
11	Ebptll8TNnxK	stepper	Stepper	i-lucide-list-ordered	Content	A numbered sequence of steps, horizontal or vertical.	[{"name": "title", "type": "string"}, {"enum": ["vertical", "horizontal"], "name": "orientation", "type": "enum"}, {"enum": ["primary", "secondary", "success", "info", "warning", "error", "neutral"], "name": "color", "type": "enum"}, {"enum": ["xs", "sm", "md", "lg", "xl"], "name": "size", "type": "enum"}, {"name": "items", "type": "blocks", "block_types": ["stepper_item"]}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
12	LxWZL6PE4Q5Y	button	Button	i-lucide-mouse-pointer-click	Content	A standalone action button.	[{"name": "label", "type": "string", "required": true}, {"name": "url", "type": "string", "required": true}, {"enum": ["solid", "outline", "soft", "subtle", "ghost", "link"], "name": "variant", "type": "enum"}, {"enum": ["primary", "neutral"], "name": "color", "type": "enum"}, {"enum": ["xs", "sm", "md", "lg", "xl"], "name": "size", "type": "enum"}, {"enum": ["pill", "rounded", "square"], "name": "shape", "type": "enum"}, {"name": "leading_icon", "type": "string", "format": "icon", "pattern": "[a-z0-9]+(-[a-z0-9]+)*"}, {"name": "trailing_icon", "type": "string", "format": "icon", "pattern": "[a-z0-9]+(-[a-z0-9]+)*"}, {"name": "block", "type": "boolean"}, {"enum": ["left", "center", "right"], "name": "align", "type": "enum"}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
13	nwMCoIiw1Krn	color_mode	Color mode	i-lucide-sun-moon	Content	A light / system / dark color-mode switch for visitors.	[]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
14	9DbE17P6hwgm	logos	Logos	i-lucide-building-2	Media	A “trusted by” strip of brand logos.	[{"name": "title", "type": "string"}, {"name": "images", "type": "asset", "multiple": true}, {"name": "grayscale", "type": "boolean"}, {"name": "scroll", "type": "boolean"}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
15	vxtN1yK5Lq7y	accordion_item	Accordion item	i-lucide-chevron-down	Items	One question with a rich-text answer.	[{"name": "question", "type": "string", "required": true}, {"name": "answer", "type": "text", "format": "rich"}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
16	CtDSbJoqhUW3	stepper_item	Stepper item	i-lucide-circle-dot	Items	One numbered step: title and description.	[{"name": "title", "type": "string", "required": true}, {"name": "description", "type": "text"}]	t	2026-09-14 07:35:10	2026-09-14 07:35:10
17	E8w7ftxGXH1N	style	Style	i-lucide-palette	Layout	Re-skin a group of blocks with a chosen accent/neutral, plus an optional custom-CSS class hook.	[{"enum": ["inherit", "red", "orange", "amber", "yellow", "lime", "green", "emerald", "teal", "cyan", "sky", "blue", "indigo", "violet", "purple", "fuchsia", "pink", "rose"], "name": "accent", "type": "enum"}, {"enum": ["inherit", "slate", "gray", "zinc", "neutral", "stone"], "name": "neutral", "type": "enum"}, {"name": "class_hook", "type": "string", "pattern": "[A-Za-z_][A-Za-z0-9_-]*( [A-Za-z_][A-Za-z0-9_-]*)*"}, {"enum": ["none", "2xs", "xs", "sm", "md", "lg", "xl", "2xl"], "name": "shadow", "type": "enum"}, {"name": "shadow_color", "type": "string", "pattern": "#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?"}, {"max": 200, "min": 0, "name": "shadow_opacity", "type": "number"}, {"enum": ["none", "small", "medium", "large"], "name": "padding", "type": "enum"}, {"enum": ["none", "small", "medium", "large"], "name": "margin", "type": "enum"}, {"name": "content", "type": "blocks"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
18	nqXCCNsH4OIb	columns	Columns	i-lucide-columns-3	Layout	Two or three columns of blocks.	[{"enum": ["2", "3"], "name": "layout", "type": "enum"}, {"enum": ["50-50", "33-67", "67-33", "25-75", "75-25", "33-33-33", "25-50-25", "50-25-25", "25-25-50"], "name": "widths", "type": "enum"}, {"enum": ["stretch", "top", "center", "bottom"], "name": "align", "type": "enum"}, {"name": "col_1", "type": "blocks"}, {"name": "col_2", "type": "blocks"}, {"name": "col_3", "type": "blocks"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
19	W9UWdd0xU8qo	spacer	Spacer	i-lucide-move-vertical	Layout	Vertical breathing room.	[{"enum": ["small", "medium", "large"], "name": "size", "type": "enum"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
20	FfgFRv2COsvi	hero	Hero	i-lucide-sparkles	Content	Big heading, supporting copy, buttons and media.	[{"name": "headline", "type": "string"}, {"name": "title", "type": "string", "required": true}, {"name": "description", "type": "text"}, {"name": "links", "type": "blocks", "block_types": ["button"]}, {"name": "image", "type": "asset"}, {"name": "aside", "type": "blocks"}, {"enum": ["vertical", "horizontal"], "name": "orientation", "type": "enum"}, {"name": "reverse", "type": "boolean"}, {"enum": ["gradient", "none", "muted", "inverted"], "name": "background", "type": "enum"}, {"enum": ["h1", "h2", "h3"], "name": "heading_level", "type": "enum"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
21	8xXTrQFyRSan	rich_text	Rich text	i-lucide-text	Content	Free-form formatted text.	[{"name": "body", "type": "text", "format": "rich"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
22	GZlOUnGRAdeI	heading	Heading	i-lucide-heading	Content	A single heading or label line.	[{"name": "text", "type": "string", "required": true}, {"enum": ["h1", "h2", "h3", "h4", "h5", "h6"], "name": "level", "type": "enum"}, {"enum": ["start", "center", "end"], "name": "align", "type": "enum"}, {"name": "color", "type": "string", "format": "color", "pattern": "#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
23	kuDYxDdMbofh	cta	Call to action	i-lucide-megaphone	Content	A call-to-action band with buttons.	[{"name": "title", "type": "string", "required": true}, {"name": "description", "type": "text"}, {"enum": ["solid", "outline", "soft", "subtle", "naked"], "name": "variant", "type": "enum"}, {"enum": ["vertical", "horizontal"], "name": "orientation", "type": "enum"}, {"name": "reverse", "type": "boolean"}, {"name": "links", "type": "blocks", "block_types": ["button"]}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
24	sEBXB5EaLEQv	form	Form	i-lucide-mail	Content	A contact form: stores submissions and emails a recipient.	[{"name": "form_name", "type": "string"}, {"name": "recipient", "type": "string", "group": "Delivery"}, {"enum": ["store_and_email", "email_only"], "name": "delivery", "type": "enum", "group": "Delivery"}, {"name": "success_message", "type": "text", "group": "Delivery"}, {"name": "redirect_url", "type": "string", "group": "Delivery"}, {"name": "submit_label", "type": "string", "group": "Form"}, {"enum": ["solid", "outline", "soft", "subtle", "ghost", "link"], "name": "submit_variant", "type": "enum", "group": "Form"}, {"enum": ["primary", "neutral"], "name": "submit_color", "type": "enum", "group": "Form"}, {"name": "heading", "type": "string", "group": "Form"}, {"name": "intro", "type": "text", "group": "Form"}, {"name": "name_label", "type": "string", "group": "Fields"}, {"name": "email_label", "type": "string", "group": "Fields"}, {"name": "message_label", "type": "string", "group": "Fields"}, {"name": "include_subject", "type": "boolean", "group": "Fields"}, {"name": "subject_label", "type": "string", "group": "Fields"}, {"name": "include_phone", "type": "boolean", "group": "Fields"}, {"name": "phone_label", "type": "string", "group": "Fields"}, {"name": "phone_required", "type": "boolean", "group": "Fields"}, {"name": "include_consent", "type": "boolean", "group": "Fields"}, {"name": "consent_text", "type": "string", "group": "Fields"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
25	PjavxKn1e5El	tabs	Tabs	i-lucide-panels-top-left	Content	Tabbed panels of blocks.	[{"name": "items", "type": "blocks", "block_types": ["tab"]}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
26	B9ZoVqRQfSxs	carousel	Carousel	i-lucide-gallery-horizontal	Content	A swipeable slider — each child block is a slide.	[{"name": "slides", "type": "blocks"}, {"enum": ["1", "2", "3"], "name": "slides_per_view", "type": "enum"}, {"name": "arrows", "type": "boolean"}, {"name": "dots", "type": "boolean"}, {"name": "autoplay", "type": "boolean"}, {"enum": ["default", "hero"], "name": "style", "type": "enum"}, {"enum": ["slide", "fade", "zoom"], "name": "transition", "type": "enum", "enum_labels": {"fade": "Fade", "zoom": "Zoom (Ken Burns)", "slide": "Slide"}}, {"max": 5, "min": 0.2, "name": "transition_duration", "type": "number"}, {"enum": ["compact", "standard", "tall", "full"], "name": "height", "type": "enum"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
27	8SR8Ls006fIy	animated_text	Animated text	i-lucide-type	Content	A heading with a reveal effect and an optional rotating word.	[{"name": "prefix", "type": "string"}, {"name": "rotate_words", "type": "text"}, {"name": "suffix", "type": "string"}, {"enum": ["fade", "slide-up", "blur"], "name": "effect", "type": "enum"}, {"name": "loop", "type": "boolean"}, {"max": 10, "min": 0.5, "name": "interval", "type": "number"}, {"enum": ["h1", "h2", "h3", "p"], "name": "tag", "type": "enum"}, {"name": "prefix_color", "type": "string", "group": "Prefix style", "format": "color"}, {"enum": ["inherit", "sm", "lg", "xl"], "name": "prefix_size", "type": "enum", "group": "Prefix style"}, {"name": "prefix_bold", "type": "boolean", "group": "Prefix style"}, {"name": "prefix_italic", "type": "boolean", "group": "Prefix style"}, {"name": "rotate_color", "type": "string", "group": "Rotating words style", "format": "color"}, {"enum": ["inherit", "sm", "lg", "xl"], "name": "rotate_size", "type": "enum", "group": "Rotating words style"}, {"name": "rotate_bold", "type": "boolean", "group": "Rotating words style"}, {"name": "rotate_italic", "type": "boolean", "group": "Rotating words style"}, {"name": "suffix_color", "type": "string", "group": "Suffix style", "format": "color"}, {"enum": ["inherit", "sm", "lg", "xl"], "name": "suffix_size", "type": "enum", "group": "Suffix style"}, {"name": "suffix_bold", "type": "boolean", "group": "Suffix style"}, {"name": "suffix_italic", "type": "boolean", "group": "Suffix style"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
28	tcIAcQ70xDtq	image	Image	i-lucide-image	Media	A single image with caption.	[{"name": "image", "type": "asset", "required": true}, {"name": "alt", "type": "string"}, {"name": "caption", "type": "string"}, {"enum": ["normal", "wide", "full"], "name": "size", "type": "enum"}, {"min": 1, "name": "width", "type": "number"}, {"min": 1, "name": "height", "type": "number"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
29	3mbC35QY3Qab	gallery	Gallery	i-lucide-images	Media	A responsive image grid with an optional lightbox.	[{"name": "items", "type": "blocks", "block_types": ["image"], "enforce_block_types": true}, {"enum": ["2", "3", "4"], "name": "columns", "type": "enum"}, {"enum": ["natural", "square", "landscape"], "name": "aspect", "type": "enum"}, {"name": "lightbox", "type": "boolean"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
30	aqQtZ3IFDNKD	logo	Logo	i-lucide-badge-check	Media	The site logo (Settings → General); falls back to the site name.	[{"enum": ["small", "medium", "large"], "name": "size", "type": "enum"}, {"name": "link_home", "type": "boolean"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
31	T9vgszAO3esz	icon	Icon	i-lucide-shapes	Media	A single decorative icon from the Lucide set, optionally linked.	[{"name": "icon", "type": "string", "format": "icon", "pattern": "[a-z0-9]+(-[a-z0-9]+)*", "required": true}, {"enum": ["small", "medium", "large"], "name": "size", "type": "enum"}, {"enum": ["start", "center", "end"], "name": "align", "type": "enum"}, {"name": "url", "type": "string"}, {"name": "label", "type": "string"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
32	i0P5JVm4dwch	social_links	Social links	i-lucide-share-2	Content	A row of brand icons linking to social profiles.	[{"name": "items", "type": "blocks", "block_types": ["social_link"]}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
33	IZFTxOsIMhDO	video	Video	i-lucide-video	Media	An uploaded video or a YouTube/Vimeo embed.	[{"enum": ["upload", "embed"], "name": "source", "type": "enum"}, {"name": "video", "type": "asset"}, {"name": "url", "type": "string"}, {"name": "poster", "type": "asset"}, {"name": "caption", "type": "string"}, {"enum": ["normal", "wide", "full"], "name": "width", "type": "enum"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
34	9dcm9rz78D74	audio	Audio	i-lucide-audio-lines	Media	An uploaded audio file with native controls.	[{"name": "audio", "type": "asset", "required": true}, {"name": "title", "type": "string"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
35	Homzct2WJ9Bh	file	File	i-lucide-file	Media	A download link to an uploaded file.	[{"name": "file", "type": "asset", "required": true}, {"name": "label", "type": "string"}, {"name": "new_tab", "type": "boolean"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
36	k4PZxbniTsTe	code	Code	i-lucide-code	Content	A code snippet with a language label and a copy button.	[{"name": "code", "type": "text", "required": true}, {"enum": ["text", "bash", "php", "json", "yaml", "html", "css", "javascript", "typescript", "twig", "sql"], "name": "language", "type": "enum"}, {"name": "label", "type": "string"}, {"name": "copy", "type": "boolean"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
37	kuCJQNsglJ6v	html	HTML	i-lucide-code	Advanced	Raw HTML, rendered verbatim. Trusted editors only — activate to opt in.	[{"name": "code", "type": "text"}]	f	2026-09-14 07:35:13	2026-09-14 07:35:13
38	vGF2gEu2IoVd	shortcode	Shortcode	i-lucide-braces	Advanced	Renders shortcodes/{name}.twig from the theme (or a DB template).	[{"name": "name", "type": "string", "pattern": "[a-z][a-z0-9_-]*", "required": true}, {"name": "params", "type": "json"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
39	HtocIaPuv8jV	feature	Feature	i-lucide-check	Items	One feature: icon, title, description, link.	[{"name": "icon", "type": "string", "format": "icon", "pattern": "[a-z0-9]+(-[a-z0-9]+)*"}, {"name": "title", "type": "string", "required": true}, {"name": "description", "type": "text"}, {"name": "url", "type": "string"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
40	1lqkQmP5zWcl	tab	Tab	i-lucide-panel-top	Items	One tab: label and panel blocks.	[{"name": "label", "type": "string", "required": true}, {"name": "content", "type": "blocks"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
41	BSDgTh1XCFdy	social_link	Social link	i-lucide-link	Items	One social profile: brand icon + URL.	[{"name": "icon", "type": "string", "format": "brand-icon", "pattern": "brand:[a-z0-9]+(-[a-z0-9]+)*", "required": true}, {"name": "url", "type": "string", "required": true}, {"name": "label", "type": "string"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
42	imfTGBBuJ7uS	pricing_plan	Pricing plan	i-lucide-badge-dollar-sign	Content	A single pricing plan card: price, features and a CTA.	[{"name": "title", "type": "string"}, {"name": "description", "type": "text"}, {"name": "price", "type": "string"}, {"name": "discount", "type": "string"}, {"name": "billing_period", "type": "string"}, {"name": "billing_cycle", "type": "string"}, {"name": "badge", "type": "string"}, {"name": "features", "type": "text"}, {"name": "feature_icon", "type": "string", "format": "icon", "pattern": "[a-z0-9]+(-[a-z0-9]+)*"}, {"name": "tagline", "type": "string"}, {"name": "terms", "type": "text"}, {"name": "button_label", "type": "string"}, {"name": "button_url", "type": "string"}, {"name": "plan_key", "type": "string", "pattern": "[a-z0-9._-]{1,100}"}, {"enum": ["solid", "outline"], "name": "button_variant", "type": "enum"}, {"enum": ["outline", "solid", "soft", "subtle"], "name": "variant", "type": "enum"}, {"name": "highlight", "type": "boolean"}, {"enum": ["vertical", "horizontal"], "name": "orientation", "type": "enum"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
43	4kzxUax36WqE	pricing_plans	Pricing plans	i-lucide-wallet-cards	Content	A row or stack of pricing plans, with an optional featured plan.	[{"name": "plans", "type": "blocks", "block_types": ["pricing_plan"]}, {"enum": ["horizontal", "vertical"], "name": "orientation", "type": "enum"}, {"name": "compact", "type": "boolean"}, {"name": "scale", "type": "boolean"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
44	QS3hGM3tYtVB	pricing_table	Pricing table	i-lucide-table	Content	A feature-comparison table across pricing tiers.	[{"name": "tiers", "type": "blocks", "block_types": ["pricing_tier"]}, {"name": "features", "type": "blocks", "block_types": ["pricing_feature"]}, {"name": "highlight", "type": "boolean"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
45	Aqtku72XIhc5	pricing_tier	Pricing tier	i-lucide-columns-3	Items	One column of a pricing table: title, price and CTA.	[{"name": "title", "type": "string"}, {"name": "description", "type": "text"}, {"name": "price", "type": "string"}, {"name": "discount", "type": "string"}, {"name": "billing_period", "type": "string"}, {"name": "billing_cycle", "type": "string"}, {"name": "badge", "type": "string"}, {"name": "button_label", "type": "string"}, {"name": "button_url", "type": "string"}, {"enum": ["solid", "outline"], "name": "button_variant", "type": "enum"}, {"name": "highlight", "type": "boolean"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
46	9PTxuszk1xhU	pricing_feature	Pricing feature	i-lucide-list-checks	Items	One comparison row (or a section heading) with a value per tier.	[{"name": "is_section", "type": "boolean"}, {"name": "title", "type": "string"}, {"name": "value_1", "type": "string"}, {"name": "value_2", "type": "string"}, {"name": "value_3", "type": "string"}, {"name": "value_4", "type": "string"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
47	7HKZoXRjINwW	blog_posts	Blog posts	i-lucide-newspaper	Content	Lists published posts as cards (dynamic).	[{"name": "type", "type": "string", "pattern": "[a-z0-9]+(-[a-z0-9]+)*"}, {"max": 12, "min": 1, "name": "limit", "type": "number"}, {"enum": ["newest", "oldest"], "name": "order", "type": "enum"}, {"name": "category", "type": "string"}, {"enum": ["1", "2", "3", "4"], "name": "columns", "type": "enum"}, {"enum": ["outline", "soft", "subtle", "ghost", "naked"], "name": "variant", "type": "enum"}, {"enum": ["vertical", "horizontal"], "name": "orientation", "type": "enum"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
48	F5HbsDN7DaEb	auth-state	Account state	i-lucide-user-round	Account	Shows one set of blocks to signed-out visitors and another to signed-in ones.	[{"name": "signed_out", "type": "blocks", "block_types": ["button", "links", "rich_text", "logo", "navigation", "login-form", "register-form", "forgot-password-form"], "enforce_block_types": true}, {"name": "signed_in", "type": "blocks", "block_types": ["button", "links", "rich_text", "logo", "navigation", "login-form", "register-form", "forgot-password-form"], "enforce_block_types": true}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
49	zi1oIhaiD4mP	login-form	Sign-in form	i-lucide-log-in	Account	The sign-in form, embeddable on any page. A failed attempt returns to this page with an inline error.	[{"name": "heading", "type": "string"}, {"name": "next", "type": "string"}, {"name": "show_links", "type": "boolean"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
50	NfcxzCsFs9O6	register-form	Registration form	i-lucide-user-plus	Account	The create-account form, embeddable on any page. Continues into the email-verification flow.	[{"name": "heading", "type": "string"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
51	5rg33pxdbO1k	forgot-password-form	Password reset request	i-lucide-key-round	Account	The request-a-reset-code form, embeddable on any page. Continues into the reset flow.	[{"name": "heading", "type": "string"}]	t	2026-09-14 07:35:13	2026-09-14 07:35:13
\.


--
-- Data for Name: collection_definitions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.collection_definitions (id, uuid, tenant_uuid, name, label, table_name, storage_mode, fields, schema_version, status, access_policy, field_order, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: collection_schema_changes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.collection_schema_changes (id, uuid, tenant_uuid, collection_uuid, change_type, payload, actor_type, actor_id, destructive, status, created_at, applied_at) FROM stdin;
\.


--
-- Data for Name: content_types; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.content_types (id, uuid, slug, name, description, cache_ttl, public_delivery, mount_at_root, status, schema, schema_version, created_by, created_at, updated_at) FROM stdin;
1	WEi3J5SJiG16	page	Page	\N	\N	t	f	active	[{"name": "title", "type": "string", "required": true}, {"name": "body", "type": "blocks"}]	1	\N	2026-09-14 07:35:13	2026-09-14 07:35:13
\.


--
-- Data for Name: email_settings; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.email_settings (id, setting_key, value, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: email_templates; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.email_templates (id, uuid, template_key, subject, body, updated_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: entries; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.entries (id, uuid, content_type_uuid, status, created_by, created_at, updated_at) FROM stdin;
1	FblJoFGImtwf	WEi3J5SJiG16	active	user00000001	2026-09-14 07:35:13	2026-09-14 07:35:13
2	37k4MCyYH9Qp	WEi3J5SJiG16	active	user00000001	2026-09-14 07:35:13	2026-09-14 07:35:13
3	Rw4X0rkKfMi2	WEi3J5SJiG16	active	user00000001	2026-09-14 07:35:13	2026-09-14 07:35:13
4	I1hUrUPOH3Aw	WEi3J5SJiG16	active	user00000001	2026-09-14 07:35:13	2026-09-14 07:35:13
\.


--
-- Data for Name: entry_drafts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.entry_drafts (id, entry_uuid, locale, fields, schema_version, lock_version, updated_by, updated_at) FROM stdin;
1	FblJoFGImtwf	en	{"body": [{"id": "SioLHcLsdEDY", "data": {"text": "Heading alpha v2", "align": "start", "color": "#ff0000", "level": "h2"}, "type": "heading"}, {"id": "aiguJWhEurXd", "data": {"url": "/go", "size": "lg", "align": "center", "label": "Go", "shape": "square", "variant": "solid"}, "type": "button"}, {"id": "EnE84CSl1Bxd", "data": {"prefix": "Build", "suffix": "sites", "prefix_color": "#112233", "rotate_color": "#445566", "rotate_words": "fast\\nwell", "suffix_color": "#778899"}, "type": "animated_text"}, {"id": "P24zjqFmOTnz", "data": {"alt": "pixel", "size": "wide", "image": "COUOJiK8gwgW", "width": 320, "height": 240}, "type": "image"}, {"id": "fbqxcdeF5acx", "data": {"slides": [{"id": "PxYbKmBmQa8U", "data": {"content": "<p>slide</p>"}, "type": "rich_text"}], "transition_duration": 1.5}, "type": "carousel"}, {"id": "YMMsTdoiLzQM", "data": {"gap": 12, "margin": {"top": 8, "bottom": 8}, "radius": {"top": 12, "left": 12, "right": 12, "bottom": 12}, "shadow": "md", "content": [{"id": "NzCRNjGNesiM", "data": {"margin": "small", "shadow": "lg", "content": [{"id": "ZvAinlxJ4BCm", "data": {"text": "Nested", "align": "center", "level": "h3"}, "type": "heading"}], "padding": "medium", "class_hook": "promo", "shadow_color": "#123456", "shadow_opacity": 40}, "type": "style"}], "padding": {"top": 17, "left": 17, "right": 17, "bottom": 17}, "max_width": 900, "min_height": "half", "border_color": "#123456", "border_style": "solid", "border_width": 2, "min_height_px": 420, "overlay_color": "#000000", "padding_preset": "large", "overlay_opacity": 40, "background_color": "#f0f0f0"}, "type": "container"}], "title": "Published alpha again"}	1	2	user00000001	2026-09-14 07:35:13
2	37k4MCyYH9Qp	en	{"body": [{"id": "kjxPPMwnvWoH", "data": {"text": "Heading beta v2", "align": "start", "color": "#ff0000", "level": "h2"}, "type": "heading"}, {"id": "tujzFKwkVfE1", "data": {"url": "/go", "size": "lg", "align": "center", "label": "Go", "shape": "square", "variant": "solid"}, "type": "button"}, {"id": "jifSCu6OI72s", "data": {"prefix": "Build", "suffix": "sites", "prefix_color": "#112233", "rotate_color": "#445566", "rotate_words": "fast\\nwell", "suffix_color": "#778899"}, "type": "animated_text"}, {"id": "eQhQrmDcfN0D", "data": {"alt": "pixel", "size": "wide", "image": "COUOJiK8gwgW", "width": 320, "height": 240}, "type": "image"}, {"id": "LWK8V4QpiRSR", "data": {"slides": [{"id": "bW1wk9e6IlBO", "data": {"content": "<p>slide</p>"}, "type": "rich_text"}], "transition_duration": 1.5}, "type": "carousel"}, {"id": "3f0oMrrGiJ3U", "data": {"gap": 12, "margin": {"top": 8, "bottom": 8}, "radius": {"top": 12, "left": 12, "right": 12, "bottom": 12}, "shadow": "md", "content": [{"id": "uJnpKxx1m7oT", "data": {"margin": "small", "shadow": "lg", "content": [{"id": "evEAq8xX7U8j", "data": {"text": "Nested", "align": "center", "level": "h3"}, "type": "heading"}], "padding": "medium", "class_hook": "promo", "shadow_color": "#123456", "shadow_opacity": 40}, "type": "style"}], "padding": {"top": 17, "left": 17, "right": 17, "bottom": 17}, "max_width": 900, "min_height": "half", "border_color": "#123456", "border_style": "solid", "border_width": 2, "min_height_px": 420, "overlay_color": "#000000", "padding_preset": "large", "overlay_opacity": 40, "background_color": "#f0f0f0"}, "type": "container"}], "title": "Published beta again"}	1	2	user00000001	2026-09-14 07:35:13
3	Rw4X0rkKfMi2	en	{"body": [{"id": "5itGbbE7tluf", "data": {"text": "Heading gamma", "align": "start", "color": "#ff0000", "level": "h2"}, "type": "heading"}, {"id": "ZkaXPrpvpuU0", "data": {"url": "/go", "size": "lg", "align": "center", "label": "Go", "shape": "square", "variant": "solid"}, "type": "button"}, {"id": "JEqNOLBNx5jG", "data": {"prefix": "Build", "suffix": "sites", "prefix_color": "#112233", "rotate_color": "#445566", "rotate_words": "fast\\nwell", "suffix_color": "#778899"}, "type": "animated_text"}, {"id": "JHGB2zxXuBqS", "data": {"alt": "pixel", "size": "wide", "image": "COUOJiK8gwgW", "width": 320, "height": 240}, "type": "image"}, {"id": "EBHXYzruGdYX", "data": {"slides": [{"id": "bLdQ6DqKTOna", "data": {"content": "<p>slide</p>"}, "type": "rich_text"}], "transition_duration": 1.5}, "type": "carousel"}, {"id": "Ek84mYukeeqQ", "data": {"gap": 12, "margin": {"top": 8, "bottom": 8}, "radius": {"top": 12, "left": 12, "right": 12, "bottom": 12}, "shadow": "md", "content": [{"id": "Au0Ie3SzMFfa", "data": {"margin": "small", "shadow": "lg", "content": [{"id": "Wp6EoN3TPG7M", "data": {"text": "Nested", "align": "center", "level": "h3"}, "type": "heading"}], "padding": "medium", "class_hook": "promo", "shadow_color": "#123456", "shadow_opacity": 40}, "type": "style"}], "padding": {"top": 17, "left": 17, "right": 17, "bottom": 17}, "max_width": 900, "min_height": "half", "border_color": "#123456", "border_style": "solid", "border_width": 2, "min_height_px": 420, "overlay_color": "#000000", "padding_preset": "large", "overlay_opacity": 40, "background_color": "#f0f0f0"}, "type": "container"}], "title": "Draft gamma"}	1	1	user00000001	2026-09-14 07:35:13
4	I1hUrUPOH3Aw	en	{"body": [{"id": "n0CpRLorpAW4", "data": {"text": "Heading delta", "align": "start", "color": "#ff0000", "level": "h2"}, "type": "heading"}, {"id": "LcBgta3fLpCW", "data": {"url": "/go", "size": "lg", "align": "center", "label": "Go", "shape": "square", "variant": "solid"}, "type": "button"}, {"id": "pcT8Bcqx05nD", "data": {"prefix": "Build", "suffix": "sites", "prefix_color": "#112233", "rotate_color": "#445566", "rotate_words": "fast\\nwell", "suffix_color": "#778899"}, "type": "animated_text"}, {"id": "pwL6e0446bV8", "data": {"alt": "pixel", "size": "wide", "image": "COUOJiK8gwgW", "width": 320, "height": 240}, "type": "image"}, {"id": "7KH3UxFxku2L", "data": {"slides": [{"id": "H5zKKzOqYJDe", "data": {"content": "<p>slide</p>"}, "type": "rich_text"}], "transition_duration": 1.5}, "type": "carousel"}, {"id": "zZxB54Tpnk1H", "data": {"gap": 12, "margin": {"top": 8, "bottom": 8}, "radius": {"top": 12, "left": 12, "right": 12, "bottom": 12}, "shadow": "md", "content": [{"id": "5hVL83cx8kQb", "data": {"margin": "small", "shadow": "lg", "content": [{"id": "TSLYN3ZDfVGY", "data": {"text": "Nested", "align": "center", "level": "h3"}, "type": "heading"}], "padding": "medium", "class_hook": "promo", "shadow_color": "#123456", "shadow_opacity": 40}, "type": "style"}], "padding": {"top": 17, "left": 17, "right": 17, "bottom": 17}, "max_width": 900, "min_height": "half", "border_color": "#123456", "border_style": "solid", "border_width": 2, "min_height_px": 420, "overlay_color": "#000000", "padding_preset": "large", "overlay_opacity": 40, "background_color": "#f0f0f0"}, "type": "container"}], "title": "Draft delta"}	1	1	user00000001	2026-09-14 07:35:13
\.


--
-- Data for Name: entry_publications; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.entry_publications (id, entry_uuid, locale, version_uuid, published_by, published_at) FROM stdin;
1	FblJoFGImtwf	en	mjw39sg4I1HG	user00000001	2026-09-14 07:35:13.595767
2	37k4MCyYH9Qp	en	bOIe9tX7LTQB	user00000001	2026-09-14 07:35:13.603172
\.


--
-- Data for Name: entry_redirects; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.entry_redirects (id, uuid, content_type_uuid, locale, source_slug, target_content_type_uuid, target_locale, target_entry_uuid, target_url, status, origin, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: entry_references; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.entry_references (id, source_entry_uuid, source_field, target_entry_uuid, locale) FROM stdin;
\.


--
-- Data for Name: entry_routes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.entry_routes (id, entry_uuid, content_type_uuid, locale, slug) FROM stdin;
1	FblJoFGImtwf	WEi3J5SJiG16	en	published-alpha
2	37k4MCyYH9Qp	WEi3J5SJiG16	en	published-beta
\.


--
-- Data for Name: entry_schedules; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.entry_schedules (id, uuid, entry_uuid, locale, action, run_at, status, attempts, failure_reason, created_by, created_at, updated_at, canceled_at, canceled_by, locked_by) FROM stdin;
\.


--
-- Data for Name: entry_schema_migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.entry_schema_migrations (id, uuid, content_type_uuid, from_version, to_version, ops, status, work_items_total, work_items_done, work_items_failed, failure_report, created_by, created_at, started_at, completed_at) FROM stdin;
\.


--
-- Data for Name: entry_versions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.entry_versions (id, uuid, entry_uuid, locale, version, fields, schema_version, created_by, created_at) FROM stdin;
1	ejkQN8l5X8FZ	FblJoFGImtwf	en	1	{"body": [{"id": "FTeQDnVttL4i", "data": {"text": "Heading alpha v1", "align": "start", "color": "#ff0000", "level": "h2"}, "type": "heading"}, {"id": "F2CL0eUPomPN", "data": {"url": "/go", "size": "lg", "align": "center", "label": "Go", "shape": "square", "variant": "solid"}, "type": "button"}, {"id": "yk94C2WFBKfy", "data": {"prefix": "Build", "suffix": "sites", "prefix_color": "#112233", "rotate_color": "#445566", "rotate_words": "fast\\nwell", "suffix_color": "#778899"}, "type": "animated_text"}, {"id": "enxVMeXmSOfj", "data": {"alt": "pixel", "size": "wide", "image": "COUOJiK8gwgW", "width": 320, "height": 240}, "type": "image"}, {"id": "JwYiMu6Onnfm", "data": {"slides": [{"id": "fDCTqUP6LYRI", "data": [], "type": "rich_text"}], "transition_duration": 1.5}, "type": "carousel"}, {"id": "cwfW7fEqK5Gg", "data": {"gap": 12, "margin": {"top": 8, "bottom": 8}, "radius": {"top": 12, "left": 12, "right": 12, "bottom": 12}, "shadow": "md", "content": [{"id": "VOFFQQT0Vl7C", "data": {"margin": "small", "shadow": "lg", "content": [{"id": "DFQh57ii3guY", "data": {"text": "Nested", "align": "center", "level": "h3"}, "type": "heading"}], "padding": "medium", "class_hook": "promo", "shadow_color": "#123456", "shadow_opacity": 40}, "type": "style"}], "padding": {"top": 17, "left": 17, "right": 17, "bottom": 17}, "max_width": 900, "min_height": "half", "border_color": "#123456", "border_style": "solid", "border_width": 2, "min_height_px": 420, "overlay_color": "#000000", "padding_preset": "large", "overlay_opacity": 40, "background_color": "#f0f0f0"}, "type": "container"}], "title": "Published alpha"}	1	user00000001	2026-09-14 07:35:13.590092
2	mjw39sg4I1HG	FblJoFGImtwf	en	2	{"body": [{"id": "SioLHcLsdEDY", "data": {"text": "Heading alpha v2", "align": "start", "color": "#ff0000", "level": "h2"}, "type": "heading"}, {"id": "aiguJWhEurXd", "data": {"url": "/go", "size": "lg", "align": "center", "label": "Go", "shape": "square", "variant": "solid"}, "type": "button"}, {"id": "EnE84CSl1Bxd", "data": {"prefix": "Build", "suffix": "sites", "prefix_color": "#112233", "rotate_color": "#445566", "rotate_words": "fast\\nwell", "suffix_color": "#778899"}, "type": "animated_text"}, {"id": "P24zjqFmOTnz", "data": {"alt": "pixel", "size": "wide", "image": "COUOJiK8gwgW", "width": 320, "height": 240}, "type": "image"}, {"id": "fbqxcdeF5acx", "data": {"slides": [{"id": "PxYbKmBmQa8U", "data": [], "type": "rich_text"}], "transition_duration": 1.5}, "type": "carousel"}, {"id": "YMMsTdoiLzQM", "data": {"gap": 12, "margin": {"top": 8, "bottom": 8}, "radius": {"top": 12, "left": 12, "right": 12, "bottom": 12}, "shadow": "md", "content": [{"id": "NzCRNjGNesiM", "data": {"margin": "small", "shadow": "lg", "content": [{"id": "ZvAinlxJ4BCm", "data": {"text": "Nested", "align": "center", "level": "h3"}, "type": "heading"}], "padding": "medium", "class_hook": "promo", "shadow_color": "#123456", "shadow_opacity": 40}, "type": "style"}], "padding": {"top": 17, "left": 17, "right": 17, "bottom": 17}, "max_width": 900, "min_height": "half", "border_color": "#123456", "border_style": "solid", "border_width": 2, "min_height_px": 420, "overlay_color": "#000000", "padding_preset": "large", "overlay_opacity": 40, "background_color": "#f0f0f0"}, "type": "container"}], "title": "Published alpha again"}	1	user00000001	2026-09-14 07:35:13.595368
3	2L3oVZ9HcvVc	37k4MCyYH9Qp	en	1	{"body": [{"id": "jj5fufUkCDTJ", "data": {"text": "Heading beta v1", "align": "start", "color": "#ff0000", "level": "h2"}, "type": "heading"}, {"id": "UMqyb5xDfInm", "data": {"url": "/go", "size": "lg", "align": "center", "label": "Go", "shape": "square", "variant": "solid"}, "type": "button"}, {"id": "61G5Y0F42A30", "data": {"prefix": "Build", "suffix": "sites", "prefix_color": "#112233", "rotate_color": "#445566", "rotate_words": "fast\\nwell", "suffix_color": "#778899"}, "type": "animated_text"}, {"id": "YrfrZURM9zme", "data": {"alt": "pixel", "size": "wide", "image": "COUOJiK8gwgW", "width": 320, "height": 240}, "type": "image"}, {"id": "TsMl9oMspDsz", "data": {"slides": [{"id": "h7p2y1VqL1wg", "data": [], "type": "rich_text"}], "transition_duration": 1.5}, "type": "carousel"}, {"id": "rQU71yGJAOUv", "data": {"gap": 12, "margin": {"top": 8, "bottom": 8}, "radius": {"top": 12, "left": 12, "right": 12, "bottom": 12}, "shadow": "md", "content": [{"id": "2sVPntbOWs8q", "data": {"margin": "small", "shadow": "lg", "content": [{"id": "BvyqM2QQRHKy", "data": {"text": "Nested", "align": "center", "level": "h3"}, "type": "heading"}], "padding": "medium", "class_hook": "promo", "shadow_color": "#123456", "shadow_opacity": 40}, "type": "style"}], "padding": {"top": 17, "left": 17, "right": 17, "bottom": 17}, "max_width": 900, "min_height": "half", "border_color": "#123456", "border_style": "solid", "border_width": 2, "min_height_px": 420, "overlay_color": "#000000", "padding_preset": "large", "overlay_opacity": 40, "background_color": "#f0f0f0"}, "type": "container"}], "title": "Published beta"}	1	user00000001	2026-09-14 07:35:13.599446
4	bOIe9tX7LTQB	37k4MCyYH9Qp	en	2	{"body": [{"id": "kjxPPMwnvWoH", "data": {"text": "Heading beta v2", "align": "start", "color": "#ff0000", "level": "h2"}, "type": "heading"}, {"id": "tujzFKwkVfE1", "data": {"url": "/go", "size": "lg", "align": "center", "label": "Go", "shape": "square", "variant": "solid"}, "type": "button"}, {"id": "jifSCu6OI72s", "data": {"prefix": "Build", "suffix": "sites", "prefix_color": "#112233", "rotate_color": "#445566", "rotate_words": "fast\\nwell", "suffix_color": "#778899"}, "type": "animated_text"}, {"id": "eQhQrmDcfN0D", "data": {"alt": "pixel", "size": "wide", "image": "COUOJiK8gwgW", "width": 320, "height": 240}, "type": "image"}, {"id": "LWK8V4QpiRSR", "data": {"slides": [{"id": "bW1wk9e6IlBO", "data": [], "type": "rich_text"}], "transition_duration": 1.5}, "type": "carousel"}, {"id": "3f0oMrrGiJ3U", "data": {"gap": 12, "margin": {"top": 8, "bottom": 8}, "radius": {"top": 12, "left": 12, "right": 12, "bottom": 12}, "shadow": "md", "content": [{"id": "uJnpKxx1m7oT", "data": {"margin": "small", "shadow": "lg", "content": [{"id": "evEAq8xX7U8j", "data": {"text": "Nested", "align": "center", "level": "h3"}, "type": "heading"}], "padding": "medium", "class_hook": "promo", "shadow_color": "#123456", "shadow_opacity": 40}, "type": "style"}], "padding": {"top": 17, "left": 17, "right": 17, "bottom": 17}, "max_width": 900, "min_height": "half", "border_color": "#123456", "border_style": "solid", "border_width": 2, "min_height_px": 420, "overlay_color": "#000000", "padding_preset": "large", "overlay_opacity": 40, "background_color": "#f0f0f0"}, "type": "container"}], "title": "Published beta again"}	1	user00000001	2026-09-14 07:35:13.602769
\.


--
-- Data for Name: extension_operations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.extension_operations (id, package, operation, step, status, actor, failed_migration, error, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: filter_indexes; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.filter_indexes (id, uuid, content_type_uuid, field, filter_type, index_name, status, created_at) FROM stdin;
\.


--
-- Data for Name: form_submissions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.form_submissions (id, uuid, form_key, form_name, source_url, fields_snapshot, submitted_values, descriptor_version, status, ip, user_agent, submitted_at) FROM stdin;
\.


--
-- Data for Name: i18n_locales; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.i18n_locales (id, uuid, code, name, native_name, enabled, is_default, fallback_locale, direction, region, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: i18n_missing_translations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.i18n_missing_translations (id, uuid, domain, locale, key, first_seen_at, last_seen_at, hits) FROM stdin;
\.


--
-- Data for Name: i18n_translations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.i18n_translations (id, uuid, domain, locale, key, value, status, source, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: import_export_batches; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.import_export_batches (id, uuid, job_uuid, sequence, status, "offset", "limit", processed_records, failed_records, attempts, locked_at, started_at, finished_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: import_export_errors; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.import_export_errors (id, uuid, job_uuid, batch_uuid, record_number, severity, code, message, context, created_at) FROM stdin;
\.


--
-- Data for Name: import_export_files; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.import_export_files (id, uuid, job_uuid, role, disk, path, mime_type, size_bytes, checksum, created_at) FROM stdin;
\.


--
-- Data for Name: import_export_jobs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.import_export_jobs (id, uuid, type, adapter, status, mode, format, source_disk, source_path, result_disk, result_path, filters, options, total_records, processed_records, failed_records, error_overflow_count, created_by, started_at, finished_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: import_export_reports; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.import_export_reports (id, uuid, job_uuid, summary, report_disk, report_path, failed_records_disk, failed_records_path, created_at) FROM stdin;
\.


--
-- Data for Name: job_executions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.job_executions (id, uuid, job_uuid, status, started_at, completed_at, result, error_message, execution_time, created_at) FROM stdin;
\.


--
-- Data for Name: locks; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.locks (key_id, token, expiration) FROM stdin;
\.


--
-- Data for Name: media_assets; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.media_assets (id, blob_uuid, tenant_uuid, created_at) FROM stdin;
\.


--
-- Data for Name: media_meta; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.media_meta (id, blob_uuid, tenant_uuid, alt_text, caption, tags, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: media_usage; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.media_usage (id, blob_uuid, entry_uuid, tenant_uuid, created_at) FROM stdin;
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch, applied_at, checksum, description, extension, source) FROM stdin;
1	001_CreateApiMetricsTables.php	1	2026-09-14 07:35:09.923596	8d7c500665781e3af8dd7f8a16c6b69ff120c90115b1a4b9ba31b0bf1b53bddd	Creates API metrics tables (api_metrics, api_metrics_daily, api_rate_limits)	metrics	glueful/framework:metrics
2	001_CreateAuthSessionsTable.php	1	2026-09-14 07:35:09.928631	b872408698eefc2cebbbe2e7879ef2e557b91bf3d3e6d70cfbc54199bdf9c836	Create the auth_sessions table (core; user_uuid indexed, no FK; session_version).	auth	glueful/framework
3	001_CreateBlobsTable.php	1	2026-09-14 07:35:09.931456	e16ee7f6e07a91a8b82d917bcf9e6b98bcab04e8c12e33e3cd536f712de09980	Creates the blobs table (uploads/storage metadata)	uploads	glueful/framework:uploads
4	001_CreateExtensionOperationsTable.php	1	2026-09-14 07:35:09.934367	93e7b657be7eb942c4a3ca928551527b369dce34478a25df93070230b6c865df	Core-owned extension enable/disable operation records for the schema executor	extensions	glueful/framework:extensions
5	001_CreateLocksTable.php	1	2026-09-14 07:35:09.936612	1a245aab147e058a63c5d029853f7ae63e0b1ded08b18d20b95493939f373d69	Creates locks table for the database lock driver (Symfony Lock store)	locks	glueful/framework:locks
6	001_CreateNotificationSystemTables.php	1	2026-09-14 07:35:09.938765	d3e8a4089ecf0ff2562e0d9f3cd5628003bad7880c0ed6dfcd81f29c04887221	Creates notification system tables (notifications, deliveries, preferences, templates)	notifications	glueful/framework:notifications
7	001_CreateQueueSystemTables.php	1	2026-09-14 07:35:09.948343	bd5a722af3a1f0f0a21d0e3bccb8ab06619dd3a1e9e7e0a1d65cde780ffeacce	Creates queue system tables (jobs, failed jobs, batches) for the database queue driver	queue	glueful/framework:queue
8	001_CreateScheduledJobsTables.php	1	2026-09-14 07:35:10.043194	a9273859fce8ea17f2162a3e20ef0e0b14657fa2de6ccfb191548cbe2002b704	Creates scheduler tables (scheduled_jobs, job_executions)	scheduler	glueful/framework:scheduler
9	002_CreateAuthRefreshTokensTable.php	1	2026-09-14 07:35:10.120075	541da800919b19f4e5609c438aee4aede9121ba728a642caa897ab1b6527d1c2	Create the auth_refresh_tokens table (core; FK to auth_sessions; user_uuid no FK).	auth	glueful/framework
10	002_CreateNotificationRetryQueueTable.php	1	2026-09-14 07:35:10.165906	ae6ecd72d975ca4d6910262a5cb3253647a5813db4fdcd8f58c7dc106a549019	Creates the notification_retry_queue table (was previously created at runtime)	notifications	glueful/framework:notifications
11	003_CreateApiKeysTable.php	1	2026-09-14 07:35:10.209757	f5708b3b311d6cbc95d4a8ed457f6a2f887663ad67e3b756b3425a31e72e01ec	Create the api_keys table (core; user_uuid indexed, no FK — external principal id).	auth	glueful/framework
12	001_CreateUsersTable.php	1	2026-09-14 07:35:10.24057	cc30e1454a115e5d80df18b0b2aef27b381d3371bf73d5c23d4b9259d9ffbcee	Create the canonical users table (identity, credentials, status, 2FA flag).	migrations	glueful/users
13	002_CreateProfilesTable.php	1	2026-09-14 07:35:10.260363	dc72dd411104f7838e4c45754030f0f839f541de8199c6d941075eea819637d5	Create the profiles table (FK to users; photo_uuid indexed, no cross-package FK).	migrations	glueful/users
14	001_CreateTenantsTable.php	1	2026-09-14 07:35:10.289238	34d707e94232f085bcdccc3b304251ebe9ab07597ef8095298c8e40942b865c1	Creates the tenants registry table.	migrations	glueful/tenancy
15	002_CreateTenantMembershipsTable.php	1	2026-09-14 07:35:10.305958	e06e84b899a472b55c4ca81f952aaa94046adbba803fa96acb21c9c05932faa4	Creates the tenant_memberships bridge table.	migrations	glueful/tenancy
16	003_CreateTenantDomainsTable.php	1	2026-09-14 07:35:10.319494	dff90a058249948d16f03494904814ff8c5afa0690ff4306858bb169dc553629	Creates normalized tenant domains with independent verification and status.	migrations	glueful/tenancy
17	004_CreateReleasedHostsTable.php	1	2026-09-14 07:35:10.339777	105b409bf161fc267a8e3470676dbf3ed0ae9de2d88a329023d8513f487096dc	Creates the released-host cooldown ledger.	migrations	glueful/tenancy
18	001_CreateAuditLogsTable.php	1	2026-09-14 07:35:10.352824	bd2d6903e59de41045fcd58b591429fced6314e53acea8854f8b26595d29daa8	Create the append-only audit_logs table with actor/target/category indexes.	migrations	glueful/audit
19	001_CreateContentTypesTable.php	1	2026-09-14 07:35:10.380234	8efeb5ff5acee4949d567d9c06c26d759c8232d18f7958c055088e367bae7d99	Create content_types (content models + JSONB field schema).	migrations	glueful/thallo-core
20	001_CreateEmailTemplatesTable.php	1	2026-09-14 07:35:10.404264	c4cbdf8f74539f612252400dacc7d00b096709972eb666cd53fa63cbc6c840eb	Creates email template override rows.	migrations	glueful/email-notification
21	001_CreateI18nTables.php	1	2026-09-14 07:35:10.416775	d199cfc37b264508529483d2577133848d5d8e838309b9bf51505d69a3c418dc	Create i18n locale, translation, and missing translation tables.	migrations	glueful/i18n
22	001_CreateImportExportTables.php	1	2026-09-14 07:35:10.469014	dac798ddb584fb28283f36ffd8098e779adb13a0624cef5cf717c790b123790e	Create import/export jobs, batches, files, errors, and reports tables.	migrations	glueful/import-export
23	002_CreateEmailSettingsTable.php	1	2026-09-14 07:35:10.547264	dfc1a16ca09116437d9ad860977f9828d29a6143dd80d11b2a9336771ae6cc36	Creates email notification settings override rows.	migrations	glueful/email-notification
24	002_CreateEntriesTable.php	1	2026-09-14 07:35:10.558563	35b209ecc265d70a52a1c48c0e3471f621db63865c94675bd3497204709e23b9	Create entries (locale-neutral identity spine).	migrations	glueful/thallo-core
25	003_CreateEntryDraftsTable.php	1	2026-09-14 07:35:10.576666	d48b468dcff28a97ef200c967387aebb5ec5e22a1bbd7d593b93cafb9e053f52	Create entry_drafts (single mutable working copy per entry+locale).	migrations	glueful/thallo-core
26	004_CreateEntryVersionsTable.php	1	2026-09-14 07:35:10.587653	1003e18f78ad8b71d1ccdff50766e07b6a61efd87a4471c93bd98b90f69ec4fc	Create entry_versions (immutable append-only snapshots written at publish).	migrations	glueful/thallo-core
27	005_CreateEntryPublicationsTable.php	1	2026-09-14 07:35:10.60228	df7ce4c92c53b9b5c74cb62b4434f5c54678352e1e41202e90f391a14d39cda6	Create entry_publications (the published-version pin, one per entry+locale).	migrations	glueful/thallo-core
28	006_CreateEntryRoutesTable.php	1	2026-09-14 07:35:10.613167	5e62444967000a4c6f74b80756c2d2a00dc43f85245886e8d8d62198d3f8e994	Create entry_routes (per content-type + locale slug uniqueness).	migrations	glueful/thallo-core
29	007_CreateEntryReferencesTable.php	1	2026-09-14 07:35:10.621659	59c493a7b329d7d94cf59c9614565e46939a7819251abd0693643ace9d3dc54c	Create entry_references (normalized reference index; projection deferred to delivery plan).	migrations	glueful/thallo-core
30	009_AddFilterIndexRegistry.php	1	2026-09-14 07:35:10.631643	e07f9a700e25687ce2f1e53a8666a3d056a9083a7f350404024e1a72800a42ce	Track filterable-field expression indexes (name, type, field, status) for the delivery API.	migrations	glueful/thallo-core
31	010_CreateEntryRedirectsTable.php	1	2026-09-14 07:35:10.647724	b27d848c17fe1c806c7e06194ed176ff939886313b94d4dde0f1c66ed0abda9e	Create entry_redirects for entry-targeted and literal SEO redirects.	migrations	glueful/thallo-core
32	011_CreateEntrySchemaMigrationsTable.php	1	2026-09-14 07:35:10.665192	636af225c33ee03d1b372b26fa88912202d8427f8ed5ee5201c03c529d060d8c	Create entry_schema_migrations for explicit content schema backfills.	migrations	glueful/thallo-core
33	012_CreateEntrySchedulesTable.php	1	2026-09-14 07:35:10.678911	bf8949918ee614f109f00bd552769c9ab83eeff2981ef9e815cad88dfd8f9e56	Create entry_schedules for deferred publish and unpublish actions.	migrations	glueful/thallo-core
34	013_CreateSettingsTable.php	1	2026-09-14 07:35:10.695344	861e8ab34009e442324afe9b874c2bd780399569d310624d15638463fea91bca	Create settings (key/value store for site settings and install state).	migrations	glueful/thallo-core
35	014_AddLocaleToEntryReferences.php	1	2026-09-14 07:35:10.705567	aee7574423eb85a48a502606f17fcfdbe79f326d488e39155104466709ab5b0c	Add locale to entry_references (locale-aware reference projection); recreated empty.	migrations	glueful/thallo-core
36	015_AddLockedByToEntrySchedules.php	1	2026-09-14 07:35:10.713343	197eb793a3a5bcd990358684b1fb4cd3313938e765cfe53be3210e0fd0e33bc7	Add locked_by lease token to entry_schedules for safe reclaim/outcome scoping.	migrations	glueful/thallo-core
37	016_CreatePublishedEntryReferencesTable.php	1	2026-09-14 07:35:10.719036	74da15b26e9746a93a77a94f736e4bcceb1b2a843ca30ef62ec0df87efe4bb86	Create published_entry_references (published-reference projection for term archives/facets).	migrations	glueful/thallo-core
38	017_CreateBlockTypesTable.php	1	2026-09-14 07:35:10.727766	1adcde0cbfd05d698583204c8bc3ad7d906c37deb62243291850058dea8a7da0	Create block_types (global block-type registry for blocks fields).	migrations	glueful/thallo-core
39	018_CreateBlockTypeMigrationsTable.php	1	2026-09-14 07:35:10.736205	59f6488bb7848930e213086932c97e3765376e5c45de18f2b4d07a5520874459	Create block_type_migrations (eager block-schema migrations; microsecond created_at is the chain identity).	migrations	glueful/thallo-core
40	019_CreateRegionsTable.php	1	2026-09-14 07:35:10.755259	ccd27deefc581bde8816b7d0b3054a29f15fb56eee4dc820f9d38751bcee8c25	Create regions (global header/footer block regions).	migrations	glueful/thallo-core
41	020_ReseedNavigationBlockType.php	1	2026-09-14 07:35:10.773919	8b5f60c988edd5c438d3dd0508acd0f52ff7962c829e1dcb70fcb9172ea7e36a	Re-seed the navigation block type with the v2 variant/color/highlight schema.	migrations	glueful/thallo-core
42	021_ReseedBlockTypesForThemeRewrite.php	1	2026-09-14 07:35:10.821645	b4f5dfd30533fe6a28ec2760411fcab01cd3d9fe3b5eaba48a9d99d04463b189	Reseed block types for the default-theme rewrite (drop 11 legacy, add 12 primitives, refresh 4 drifted schemas).	migrations	glueful/thallo-core
43	022_CreateFormSubmissionsTable.php	1	2026-09-14 07:35:10.834472	9ccee3bf51acea1a031446dbd026f191bcacf214d807325e3d917f5e0164f4de	Create form_submissions (stored contact-form submissions with normalized values).	migrations	glueful/thallo-core
44	001_CreateAnalyticsFactsTable.php	1	2026-09-14 07:35:10.850508	3d47a8ded85d46a0cb35f33e44c6aee50c925f757f76c9d385afafdcbf599ec1	Create analytics_facts (append-only raw analytics events).	migrations	glueful/thallo-analytics
45	001_CreateCollectionDefinitionsTable.php	1	2026-09-14 07:35:10.866526	7ca52a79abb183e0f1c20c278a6e90fb663e3d7ac68fdbebd0904ceb8b6e6944	Create collection_definitions table (developer-defined data collections).	migrations	glueful/thallo-collections
46	001_CreateNavigationMenusTable.php	1	2026-09-14 07:35:10.878956	82993ad98ee66bf618421b052026858ef7f113bf854f642917c46b40cbdfb503	Create navigation_menus (menu identity + tree lock_version).	migrations	glueful/thallo-navigation
47	001_CreateProductLinkTable.php	1	2026-09-14 07:35:10.88676	a4ebaf68f499f178fbe280c4182cf0134ed33705ea1dc82a5ef982198458138e	Create thallo_commerce_product_links (canonical product-to-entry enrichment links).	migrations	glueful/thallo-commerce
48	001_CreateRenderTemplatesTable.php	1	2026-09-14 07:35:10.899662	2e830a67a9f23a10a6747462332af7bab2c8dd8cf3663e425a374957e19489c7	Create render_templates (DB template override identity per theme+path).	migrations	glueful/thallo-render
49	001_CreateRolesTables.php	1	2026-09-14 07:35:10.907994	46abf7be383712550897f1e342012bc57156d720790fbc2285db9d4c819686c8	Create RBAC roles and user roles tables with hierarchical support	migrations	glueful/aegis
50	001_CreateSeoMetaTable.php	1	2026-09-14 07:35:10.931346	215c43be195fcfbc9b2f3283d6df732a0e96c3a170fedf8df8f8b2f386f380a9	Create seo_meta (per-entry+locale SEO overrides).	migrations	glueful/thallo-seo
51	001_CreateSubscriptionsTable.php	1	2026-09-14 07:35:10.939118	9226c1f03a9a690e52677a708c9f06d1e66af955543bd04600909d1032171aba	Creates tenant subscriptions with optional payment-provider linkage.	migrations	glueful/subscriptions
52	001_CreateSystemFlagsTable.php	1	2026-09-14 07:35:10.948388	de93d6e1a4386aa13bf6854698c15cddd7c7d41ba13b82a13310c9e93627640d	Create thallo_system_flags: unscoped system-global key/value store for tenancy runtime state.	migrations	glueful/thallo-tenancy
53	001_CreateWorkflowReviewStatesTable.php	1	2026-09-14 07:35:10.954083	3c731a4e9fb849c9ed00f109ec3d146b6a96a2fbafb2224767fc73ef8bbc3b8f	Create workflow_review_states (per entry+locale review state).	migrations	glueful/thallo-workflow
54	002_CreateAnalyticsDailyTable.php	1	2026-09-14 07:35:10.962845	0588e5bd56a5f26e43efa78ed3beeccc99f7006976ee5e2753a7008a4828098f	Create analytics_daily (per-day event-count rollups).	migrations	glueful/thallo-analytics
55	002_CreateCollectionSchemaChangesTable.php	1	2026-09-14 07:35:10.967559	5c707a9b1dac34f3efcc07f1d8c78b446bd6bfcf887a091ee5ba58ee99f01df9	Create collection_schema_changes table (DDL audit log with recovery invariant).	migrations	glueful/thallo-collections
56	002_CreateNavigationItemsTable.php	1	2026-09-14 07:35:10.97842	c953e5735485a5b144addfd3ffe1a980047fa881660aa87584bd910c2bc6043e	Create navigation_items (menu tree nodes with per-locale labels).	migrations	glueful/thallo-navigation
57	002_CreatePermissionsTables.php	1	2026-09-14 07:35:10.983656	90757fe11254f380b74fab1f8d2f35673a0994c4a7162b5b077c9052e8938445	Create RBAC permissions, role-permissions, user-permissions, and audit tables	migrations	glueful/aegis
58	002_CreateRenderTemplateVersionsTable.php	1	2026-09-14 07:35:11.018599	ba829b3941d12ddbc84e2dc7d48b7f9dd3e348a3de9cf5183323fdc900c60067	Create render_template_versions (append-only, immutable template sources).	migrations	glueful/thallo-render
59	002_CreateSubscriptionOverridesTable.php	1	2026-09-14 07:35:11.025804	7211caf8cd4e1dbb3b1b349d5b83bc32ae95e80ec04cd682a16cf1bab071db05	Creates per-tenant subscription entitlement overrides.	migrations	glueful/subscriptions
60	002_CreateTenantPurgeRunsTable.php	1	2026-09-14 07:35:11.032211	edcb0e8ed55da82bb64e61e1a28cfa3a5fd9270bb328b7303427132867e4f88e	Create the system-global, lease-owned tenant purge run ledger.	migrations	glueful/thallo-tenancy
61	002_CreateWorkflowTransitionsTable.php	1	2026-09-14 07:35:11.040504	328ccae318fa611a12909c353b9787a132450fba6e8d53c9287a58d00d05713e	Create workflow_transitions (append-only review history).	migrations	glueful/thallo-workflow
62	002_SeedCommercePermissions.php	1	2026-09-14 07:35:11.047916	45fb8ff0d97099b44eaf22706f1ca4d8bba9f153257ee48bea84aa778697b1df	Declare the commerce.view and commerce.manage permissions.	migrations	glueful/thallo-commerce
63	002_SeedSeoPermissions.php	1	2026-09-14 07:35:11.05243	1fb5536f9063782b970b69195fcaad56608e500b42ea5a6fcf8e30fb186477b9	Declare the seo.manage permission.	migrations	glueful/thallo-seo
64	003_CreateAnalyticsActiveActorsTable.php	1	2026-09-14 07:35:11.053925	f45f75b82c4f1aa93dfcedfa9c062c9e20c4a7ba402bdf2cd5e859fe14f4e8f2	Create analytics_active_actors (distinct-actor daily presence, privacy-minimized).	migrations	glueful/thallo-analytics
65	003_CreateProductSlugLedger.php	1	2026-09-14 07:35:11.056958	00b924aae4e4097019203a02abc24ee1f4cc442b05e7158df7879153569a14ab	Create thallo_commerce_product_slugs (slug-history ledger for 301 redirects, storefront-rendering spec §4).	migrations	glueful/thallo-commerce
66	003_CreateSubscriptionEventsTable.php	1	2026-09-14 07:35:11.066753	aa32ed0bdd56ed3d09246b9b3417454fa9699a9ad8b346b9f55ef72aeca41281	Creates subscription lifecycle event log with provider logical-key dedupe.	migrations	glueful/subscriptions
67	003_CreateTenantApiKeyBindingsTable.php	1	2026-09-14 07:35:11.072669	a435495a18cf1dc0d87a2c509285f2d793750473da0c7b7be313d25c99637d1f	Creates system-global API-key to tenant bindings.	migrations	glueful/thallo-tenancy
68	003_SeedCollectionsPermissions.php	1	2026-09-14 07:35:11.080661	8cee38bd1bfc64a4e5ad3d7087d6930d36cfacf48efe344ddb290c842bb0d1ca	Declare the collections.* admin permissions (granted to roles by the host app).	migrations	glueful/thallo-collections
69	003_SeedDefaultRoles.php	1	2026-09-14 07:35:11.08374	96470f2009a6acac0aab20872a554b2d469b6120b4942f14bbe2f8cfacb82a16	Seed default RBAC roles, permissions, and role-permission assignments	migrations	glueful/aegis
70	003_SeedNavigationPermissions.php	1	2026-09-14 07:35:11.138709	4e60c4df2d7e9ea2c54b4b6c47d4985f64240273f35fb27dc8b526be6f5634b8	Declare the navigation.manage permission.	migrations	glueful/thallo-navigation
71	003_SeedTemplatesPermission.php	1	2026-09-14 07:35:11.141588	0c10169651e5b380dbdf130d24af37f476c041af787cb4a43681dd2051a60ac4	Declare the templates.manage permission.	migrations	glueful/thallo-render
72	003_SeedWorkflowPermissions.php	1	2026-09-14 07:35:11.144163	f8c21a37d23c1c0d6c445c1f1ed541179c26f684c53000cfa26b9cb74c506864	Declare the workflow.review and workflow.bypass permissions.	migrations	glueful/thallo-workflow
73	004_CreateCheckoutAttempts.php	1	2026-09-14 07:35:11.146215	bf5fa7a88fc1ef43033e794dd77779529a1aadb27a1b47074f6fdba72b38eed3	Create thallo_commerce_checkout_attempts (durable checkout-attempt idempotency ledger, storefront-rendering spec §7).	migrations	glueful/thallo-commerce
74	004_CreateSubscriptionPlansTable.php	1	2026-09-14 07:35:11.155373	620cfaf4b46a6059798118b242be60d0fb3c1a22c5cc7f4510397446778038a6	Create managed subscription plans table	migrations	glueful/subscriptions
75	004_CreateTenantRolePolicyTables.php	1	2026-09-14 07:35:11.168638	c87dd7b92fa51dec60e422fddc1e15f382d138bbddb08a59179ac4ac774ce315	Creates per-tenant role overrides and transactional policy versions.	migrations	glueful/thallo-tenancy
76	004_SeedAnalyticsPermissions.php	1	2026-09-14 07:35:11.200548	5896e04bfb312bf2401c81049a3b1bd2cd722e1edba025591c51007d96f1c82d	Declare the analytics.read permission.	migrations	glueful/thallo-analytics
77	004_SeedRolesAndPermissions.php	1	2026-09-14 07:35:11.203908	0580197bf6cedcdc270f84061fadeb8cfb95da40dd17941b3a87ffbe4df307cb	Seed Thallo content permissions + the editor role onto Aegis standard roles.	dependent-migrations	glueful/thallo-core:dependent
78	005_CreatePaymentLinkDeliveries.php	1	2026-09-14 07:35:11.248397	ba9caa98fcfd98c7d079f75662d0b2287c958fedf19076af3765646e0aca4efa	Create thallo_commerce_payment_link_deliveries (payment-link delivery idempotency ledger, payment-links spec §2.4).	migrations	glueful/thallo-commerce
79	005_CreateTenantRolesTable.php	1	2026-09-14 07:35:11.257622	7cfce9a885dadc45088b48caf933ca695bfc8dd02350033c7fc3cc64cc593106	Creates per-tenant custom membership roles.	migrations	glueful/thallo-tenancy
80	005_CreateV2PreparationState.php	1	2026-09-14 07:35:11.268859	7b4e48d718d4d6c36257ee99ca1df2eb54e1a8138bcde5c999b19fe8daf28ad8	Create the v2 preparation-state table (upgrade bridge, spec §3)	migrations	glueful/subscriptions
81	005_GrantI18nPermissionsToAdministrator.php	1	2026-09-14 07:35:11.279226	f4301db1ffe309d60a1b1ccd7499c2d7bdb430adf6e5375ef372bb83cd39a919	Grant i18n.view/manage/import/export to the administrator role.	dependent-migrations	glueful/thallo-core:dependent
82	006_CreateMediaTables.php	1	2026-09-14 07:35:11.326091	21c2c1cb6f898c3662e013df7a83009daccbe48ccfd239742027082d55334720	Create media_meta (alt/caption/tags) and media_usage (blob→entry index) sidecars.	dependent-migrations	glueful/thallo-core:dependent
83	006_CreateSignupTables.php	1	2026-09-14 07:35:11.346887	d30fa11b8343008f63024f82bd8df45ea12e7f0e192a18e6b108446333b9cca3	Creates the system-global public signup intent and abuse-control tables.	migrations	glueful/thallo-tenancy
84	006_SubjectModel.php	1	2026-09-14 07:35:11.385577	bd3b03d55b95841b341cc61d9306b0a4b36ab9991979c19896fac2b80462253d	Subject-model: tenant/user subject triple, scoped plan catalog, provider-event receipts (spec §2).	migrations	glueful/subscriptions
85	007_CheckoutReservations.php	1	2026-09-14 07:35:11.463326	a5f1407dbce2d55c5de7d6069f1c9793a1c75bf83f483230703c82abd872b55b	Adds subscriptions.checkout_origination_uuid (design spec §4.1): the origination-bound checkout reservation seam.	migrations	glueful/subscriptions
86	007_CreateWebhookTables.php	1	2026-09-14 07:35:11.469066	0bd3b847559c3e96104568bf74c92db033390fff27694feb63f9968594f8b5af	Materialize webhook_subscriptions and webhook_deliveries (framework auto-migrated tables).	dependent-migrations	glueful/thallo-core:dependent
87	008_AddUsersRolesManagePermission.php	1	2026-09-14 07:35:11.481572	e407280184af43e78565bbca708095b5472d59d3afded6bafca3d7feee51bdd9	Add users.roles.manage and grant it to superuser + administrator.	dependent-migrations	glueful/thallo-core:dependent
88	008_PlanProviderIdentifiers.php	1	2026-09-14 07:35:11.537962	43ca671396902b9daf7abc65272d80b08a0dc109020c90ac5f30d1087f2e99d4	Adds subscription_plans.provider_identifiers (design spec §4.2): the per-gateway checkout-purchasability map.	migrations	glueful/subscriptions
89	009_GrantWorkflowPermissionsToAdministrator.php	1	2026-09-14 07:35:11.546184	86a730d7b6d02bd1a7859a6d182dd7e77eb11b803947bf96e31fe3e7297f7423	Grant workflow.review/bypass to the administrator role.	dependent-migrations	glueful/thallo-core:dependent
90	010_GrantNavigationPermissionsToAdministrator.php	1	2026-09-14 07:35:11.601436	aa429dd6c2683f0942f3bc7f962e64ad2c1486c4b72a857cff9685a274c73446	Grant navigation.manage to the administrator role.	dependent-migrations	glueful/thallo-core:dependent
91	011_CreateMediaAssetsTable.php	1	2026-09-14 07:35:11.648635	0526ba082fd8d30fb24f5d66e290eca2aeabf4f033d596693d4b9433900deb49	Create the one-owner media_assets tenant ledger.	dependent-migrations	glueful/thallo-core:dependent
92	012_CreateStarterProvenanceTable.php	1	2026-09-14 07:35:11.651375	dc1d6c073d9a11c6c54396de304f682c26737445e70c41434ae0e7a35a36d59c	Create the tenant starter provenance ledger.	dependent-migrations	glueful/thallo-core:dependent
93	013_CreateTenancyAuthorityRoles.php	1	2026-09-14 07:35:11.722648	d1321ce9911ed625a4acbf6082afc02c3950d4e92407549056c147a511f800d6	Create workspace_manager and grant cross-workspace authority to it and superuser.	dependent-migrations	glueful/thallo-core:dependent
94	014_GrantCommercePermissionsToAdministrator.php	1	2026-09-14 07:35:11.781284	c1d5f24d375aacb4940af4106d774568141eeee80e0911997ea2bff5fc2b8279	Grant commerce.manage to the administrator role.	dependent-migrations	glueful/thallo-core:dependent
\.


--
-- Data for Name: navigation_items; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.navigation_items (id, uuid, menu_uuid, parent_uuid, "position", kind, entry_uuid, url, icon, labels, descriptions, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: navigation_menus; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.navigation_menus (id, uuid, slug, name, lock_version, "position", created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: notification_deliveries; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.notification_deliveries (id, notification_uuid, channel, status, attempt_count, last_error, last_attempt_at, sent_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: notification_preferences; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.notification_preferences (id, uuid, notifiable_type, notifiable_id, notification_type, channels, enabled, settings, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: notification_retry_queue; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.notification_retry_queue (id, notification_id, notifiable_type, notifiable_id, channel, retry_count, retry_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: notification_templates; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.notification_templates (id, uuid, name, notification_type, channel, content, parameters, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: notifications; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.notifications (id, uuid, type, subject, idempotency_key, data, priority, notifiable_type, notifiable_id, read_at, scheduled_at, sent_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: permission_audit; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.permission_audit (id, uuid, action, subject_type, subject_uuid, permission_uuid, target_uuid, old_data, new_data, reason, performed_by, ip_address, user_agent, created_at) FROM stdin;
\.


--
-- Data for Name: permissions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.permissions (id, uuid, name, slug, description, category, resource_type, is_system, managed_by, metadata, created_at, deleted_at) FROM stdin;
8	987ZnCtRJS5z	System Configuration	system.config	Modify system configuration	system	\N	t	\N	\N	2026-09-14 07:35:11.131242	\N
13	fVdoVDC9GGCN	View Roles	roles.view	View role definitions	roles	\N	t	\N	\N	2026-09-14 07:35:11.131242	\N
14	L4KjF0CRIc6z	Create Roles	roles.create	Create new roles	roles	\N	t	\N	\N	2026-09-14 07:35:11.131242	\N
15	Ob5YG0UZeW5d	Edit Roles	roles.edit	Edit role definitions	roles	\N	t	\N	\N	2026-09-14 07:35:11.131242	\N
16	bECHQJPOGC9C	Delete Roles	roles.delete	Delete roles	roles	\N	t	\N	\N	2026-09-14 07:35:11.131242	\N
17	o7P5vqudthcC	Assign Roles	roles.assign	Assign roles to users	roles	\N	t	\N	\N	2026-09-14 07:35:11.131242	\N
25	8NZWkNhSD52w	Publish without an approved review	workflow.bypass	Publish without an approved review	workflow	\N	t	\N	\N	2026-09-14 07:35:11.144163	\N
35	n8jJiCqBrFHs	Assign and revoke user roles	users.roles.manage	Assign and revoke user roles	users	\N	t	\N	\N	2026-09-14 07:35:11.523982	\N
36	MVrj9UkpVPgE	Manage tenants	tenancy.manage	Manage tenants	tenancy	\N	t	\N	\N	2026-09-14 07:35:11.762408	\N
37	Xs8M7BYRm3bk	Access any tenant	tenancy.access_any	Access any tenant	tenancy	\N	t	\N	\N	2026-09-14 07:35:11.762408	\N
7	hEL8Wx0vnbZG	system.access	system.access	\N	\N	\N	t	glueful/framework	\N	2026-09-14 07:35:11.131242	\N
9	BsQWyX39bCaq	users.view	users.view	\N	\N	\N	t	glueful/framework	\N	2026-09-14 07:35:11.131242	\N
10	IETS5JOGQdEj	users.create	users.create	\N	\N	\N	t	glueful/framework	\N	2026-09-14 07:35:11.131242	\N
11	S3gbEwARm9rH	users.edit	users.edit	\N	\N	\N	t	glueful/framework	\N	2026-09-14 07:35:11.131242	\N
12	pZcDHbyPwJbP	users.delete	users.delete	\N	\N	\N	t	glueful/framework	\N	2026-09-14 07:35:11.131242	\N
18	gb9GopJJsGt6	View content	content.view	View content	Content	content	t	glueful/thallo-core	\N	2026-09-14 07:35:11.131242	\N
19	635tT7yy6JUj	Create content	content.create	Create content	Content	content	t	glueful/thallo-core	\N	2026-09-14 07:35:11.131242	\N
20	ru4SVLwBKITD	Edit content	content.edit	Edit content	Content	content	t	glueful/thallo-core	\N	2026-09-14 07:35:11.131242	\N
27	zJrRDCSl49tj	Publish content	content.publish	Publish content	Content	content	t	glueful/thallo-core	\N	2026-09-14 07:35:11.238733	\N
21	cseqNlQyJ9ah	Delete content	content.delete	Delete content	Content	content	t	glueful/thallo-core	\N	2026-09-14 07:35:11.131242	\N
28	GiNNquK2kSHi	Manage content models	content.manage	Manage content models	Content	content	t	glueful/thallo-core	\N	2026-09-14 07:35:11.238733	\N
29	2ARBxHM5ZbVU	Manage routes	content.routes	Manage routes	Content	content	t	glueful/thallo-core	\N	2026-09-14 07:35:11.238733	\N
22	XrX3uSO1stge	Manage navigation	navigation.manage	Manage navigation	Experience	navigation	t	glueful/thallo-core	\N	2026-09-14 07:35:11.138709	\N
3	qbK1kmqm8BAA	Manage SEO	seo.manage	Manage SEO	Experience	seo	t	glueful/thallo-core	\N	2026-09-14 07:35:11.05243	\N
23	lM02C0kCrjbj	Manage templates	templates.manage	Manage templates	Experience	templates	t	glueful/thallo-core	\N	2026-09-14 07:35:11.141588	\N
26	uo1RPd6mEUw3	View analytics	analytics.read	View analytics	Operations	analytics	t	glueful/thallo-core	\N	2026-09-14 07:35:11.200548	\N
24	TxO9YdE4kSLK	Review workflow	workflow.review	Review workflow	Operations	workflow	t	glueful/thallo-core	\N	2026-09-14 07:35:11.144163	\N
38	imfu6FciXZM1	Manage members	tenant.members.manage	Manage members	Workspace	tenant	f	glueful/thallo-core	\N	2026-09-14 07:35:11.960401	\N
39	3wyvmGiYSslR	Manage domains	tenant.domains.manage	Manage domains	Workspace	tenant	f	glueful/thallo-core	\N	2026-09-14 07:35:11.961887	\N
40	nbdjhUp9D7rW	Manage roles	tenant.roles.manage	Manage roles	Workspace	tenant	f	glueful/thallo-core	\N	2026-09-14 07:35:11.963774	\N
4	OlvPuhy15xdo	Manage collections	collections.manage	Manage collections	Collections	collections	t	glueful/thallo-core	\N	2026-09-14 07:35:11.080661	\N
5	ivirbFRuDfQ4	Manage collection schemas	collections.schema.manage	Manage collection schemas	Collections	collections	t	glueful/thallo-core	\N	2026-09-14 07:35:11.080661	\N
6	8IeMW7hL9Xhh	Manage collection data	collections.data.manage	Manage collection data	Collections	collections	t	glueful/thallo-core	\N	2026-09-14 07:35:11.080661	\N
1	AriSY4xYCqIv	View commerce	commerce.view	View commerce	Commerce	commerce	t	glueful/thallo-core	\N	2026-09-14 07:35:11.047916	\N
2	KeedTSS6qwSM	Manage commerce	commerce.manage	Manage commerce	Commerce	commerce	t	glueful/thallo-core	\N	2026-09-14 07:35:11.047916	\N
41	olMAQPIKl0ey	Manage billing	billing.manage	Manage billing	Workspace	billing	f	glueful/thallo-core	\N	2026-09-14 07:35:11.970133	\N
42	VwSqhucz6wW0	View audit log	audit.view	\N	Audit	audit	f	glueful/audit	\N	2026-09-14 07:35:11.971662	\N
30	RCgaM4KnVpJR	Manage email templates & settings	email.templates.manage	\N	Email	email	t	glueful/email-notification	\N	2026-09-14 07:35:11.238733	\N
31	KP9botZO5Trb	View locales and translations	i18n.view	\N	I18n	i18n	t	glueful/i18n	\N	2026-09-14 07:35:11.313371	\N
32	nxqneH6MLAd6	Manage locales and translations	i18n.manage	\N	I18n	i18n	t	glueful/i18n	\N	2026-09-14 07:35:11.313371	\N
33	seH0lWtn0PHG	Import translation catalogs	i18n.import	\N	I18n	i18n	t	glueful/i18n	\N	2026-09-14 07:35:11.313371	\N
34	vArMNEpaVVQ3	Export translation catalogs	i18n.export	\N	I18n	i18n	t	glueful/i18n	\N	2026-09-14 07:35:11.313371	\N
43	poImLKAQ9THQ	View import/export jobs	import_export.view	\N	Import Export	import_export	f	glueful/import-export	\N	2026-09-14 07:35:11.980407	\N
44	hDzXCjPVoVGy	Run imports	import_export.run_import	\N	Import Export	import_export	f	glueful/import-export	\N	2026-09-14 07:35:11.98171	\N
45	DlIZgFbKA58s	Run exports	import_export.run_export	\N	Import Export	import_export	f	glueful/import-export	\N	2026-09-14 07:35:11.984678	\N
46	9nFbpStH5Q3D	Cancel import/export jobs	import_export.cancel	\N	Import Export	import_export	f	glueful/import-export	\N	2026-09-14 07:35:11.985522	\N
47	0FPwSMQLjndL	Retry import/export jobs	import_export.retry	\N	Import Export	import_export	f	glueful/import-export	\N	2026-09-14 07:35:11.986565	\N
48	JoBLoJPShi4M	Export failed import/export records	import_export.export_failed_records	\N	Import Export	import_export	f	glueful/import-export	\N	2026-09-14 07:35:11.988655	\N
49	vpVjdx5VKoXG	Manage all import/export jobs	import_export.manage_all	\N	Import Export	import_export	f	glueful/import-export	\N	2026-09-14 07:35:11.990261	\N
\.


--
-- Data for Name: profiles; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.profiles (id, uuid, user_uuid, first_name, last_name, photo_uuid, photo_url, status, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: published_entry_references; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.published_entry_references (id, source_entry_uuid, source_content_type_uuid, field, target_entry_uuid, locale) FROM stdin;
\.


--
-- Data for Name: queue_batches; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.queue_batches (id, uuid, name, total_jobs, pending_jobs, processed_jobs, failed_jobs, cancelled_at, finished_at, options, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: queue_failed_jobs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.queue_failed_jobs (id, uuid, connection, queue, payload, exception, batch_uuid, failed_at) FROM stdin;
\.


--
-- Data for Name: queue_jobs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.queue_jobs (id, uuid, queue, payload, attempts, reserved_at, available_at, priority, batch_uuid, created_at) FROM stdin;
\.


--
-- Data for Name: regions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.regions (slug, blocks, settings, updated_at, updated_by) FROM stdin;
header	[{"id": "l9AsUW4sH7OO", "data": {"content": [{"id": "NJLg85eHsdJM", "data": {"url": "/docs", "label": "Docs", "shape": "pill"}, "type": "button"}], "padding_preset": "small", "background_color": "#ffffff"}, "type": "container"}]	{"width": "contained", "sticky": true}	2026-09-14 07:35:13	user00000001
footer	[{"id": "v0LjUDCEWOMO", "data": {"content": "<p>Footer</p>"}, "type": "rich_text"}]	{"width": "contained"}	2026-09-14 07:35:13	user00000001
\.


--
-- Data for Name: released_hosts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.released_hosts (id, host, released_by_tenant, retained_until, created_at) FROM stdin;
\.


--
-- Data for Name: render_template_versions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.render_template_versions (id, uuid, template_uuid, source, created_by, created_at) FROM stdin;
\.


--
-- Data for Name: render_templates; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.render_templates (id, uuid, theme, path, current_version_uuid, active, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: role_permissions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.role_permissions (id, uuid, role_uuid, permission_uuid, resource_filter, constraints, granted_by, expires_at, created_at, updated_at, deleted_at) FROM stdin;
1	EyptFctnYfh2	1qAN65zVgXIs	hEL8Wx0vnbZG	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
2	MQxCOtRmwlEM	1qAN65zVgXIs	987ZnCtRJS5z	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
3	yzT5JzLix0gK	1qAN65zVgXIs	BsQWyX39bCaq	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
4	4s8JNmfokyF8	1qAN65zVgXIs	IETS5JOGQdEj	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
5	JTLxSdCisSdG	1qAN65zVgXIs	S3gbEwARm9rH	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
6	1eiJcxDToexW	1qAN65zVgXIs	pZcDHbyPwJbP	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
7	Ja643MhG0JRm	1qAN65zVgXIs	fVdoVDC9GGCN	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
8	xU2Sk9a7aj25	1qAN65zVgXIs	L4KjF0CRIc6z	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
9	JkDKAQmMN36P	1qAN65zVgXIs	Ob5YG0UZeW5d	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
10	AAFzjakIsyMr	1qAN65zVgXIs	bECHQJPOGC9C	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
11	HqDdH6pH6JsQ	1qAN65zVgXIs	o7P5vqudthcC	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
12	iqFUDTVmY9SP	1qAN65zVgXIs	gb9GopJJsGt6	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
13	N3hBKTh7EOUj	1qAN65zVgXIs	635tT7yy6JUj	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
14	J9r3FHhuKTNE	1qAN65zVgXIs	ru4SVLwBKITD	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
15	awounpBwi1kl	1qAN65zVgXIs	cseqNlQyJ9ah	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
16	mM9undBm3Vf3	3YlQHTbZnj9h	hEL8Wx0vnbZG	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
17	RggbMRSyUaLV	3YlQHTbZnj9h	BsQWyX39bCaq	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
18	TmXUUoXA0pWe	3YlQHTbZnj9h	IETS5JOGQdEj	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
19	F6IQYak8NNKX	3YlQHTbZnj9h	S3gbEwARm9rH	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
20	wnFK2MsCG4fm	3YlQHTbZnj9h	pZcDHbyPwJbP	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
21	vx9fh5X1PwAT	3YlQHTbZnj9h	fVdoVDC9GGCN	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
22	vt6RkRa7t1hg	3YlQHTbZnj9h	L4KjF0CRIc6z	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
23	vltGmrw45v1V	3YlQHTbZnj9h	Ob5YG0UZeW5d	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
24	ovfVjM9puaeT	3YlQHTbZnj9h	bECHQJPOGC9C	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
25	ItiV7uaFkJKq	3YlQHTbZnj9h	o7P5vqudthcC	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
26	1dfiFnmzl8wB	3YlQHTbZnj9h	gb9GopJJsGt6	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
27	1lnGg2ZR1o3E	3YlQHTbZnj9h	635tT7yy6JUj	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
28	l3LwVmoXkA0C	3YlQHTbZnj9h	ru4SVLwBKITD	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
29	E9Ji6y8SJMzj	3YlQHTbZnj9h	cseqNlQyJ9ah	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
30	8tRwMmR56HoE	Wm7G9jjWOCwb	gb9GopJJsGt6	\N	\N	\N	\N	2026-09-14 07:35:11.136871	\N	\N
31	iCkKDX8CDu6l	buVEuMnrPytp	gb9GopJJsGt6	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
32	XujV7uheu1R7	buVEuMnrPytp	635tT7yy6JUj	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
33	4I8vOwzPW4aB	buVEuMnrPytp	ru4SVLwBKITD	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
34	Y4iv11e411vh	buVEuMnrPytp	zJrRDCSl49tj	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
35	tYJJBUDKg2TX	3YlQHTbZnj9h	zJrRDCSl49tj	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
36	frUOF8E3d9NI	3YlQHTbZnj9h	GiNNquK2kSHi	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
37	Ch1RboFZAXO9	3YlQHTbZnj9h	2ARBxHM5ZbVU	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
38	CSkMdk3ufVxV	3YlQHTbZnj9h	OlvPuhy15xdo	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
39	XPbf9PamEjqT	3YlQHTbZnj9h	ivirbFRuDfQ4	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
40	sKQI8LM18y88	3YlQHTbZnj9h	8IeMW7hL9Xhh	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
41	XdLgFnH423Aq	3YlQHTbZnj9h	uo1RPd6mEUw3	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
42	Em9cRkyq8KV9	3YlQHTbZnj9h	qbK1kmqm8BAA	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
43	KjyYYPvok5Ny	3YlQHTbZnj9h	RCgaM4KnVpJR	\N	\N	\N	\N	2026-09-14 07:35:11.24597	\N	\N
44	fe0UhyQJlM23	3YlQHTbZnj9h	KP9botZO5Trb	\N	\N	\N	\N	2026-09-14 07:35:11.321938	\N	\N
45	3eNmuilRCvMP	3YlQHTbZnj9h	nxqneH6MLAd6	\N	\N	\N	\N	2026-09-14 07:35:11.321938	\N	\N
46	gNhlhNQmki8Y	3YlQHTbZnj9h	seH0lWtn0PHG	\N	\N	\N	\N	2026-09-14 07:35:11.321938	\N	\N
47	1lypOC1bvtBb	3YlQHTbZnj9h	vArMNEpaVVQ3	\N	\N	\N	\N	2026-09-14 07:35:11.321938	\N	\N
48	44UwssbG4ff2	1qAN65zVgXIs	n8jJiCqBrFHs	\N	\N	\N	\N	2026-09-14 07:35:11.534875	\N	\N
49	KLUir4fTqvLR	3YlQHTbZnj9h	n8jJiCqBrFHs	\N	\N	\N	\N	2026-09-14 07:35:11.535809	\N	\N
50	k3kvGX2AYgF9	3YlQHTbZnj9h	TxO9YdE4kSLK	\N	\N	\N	\N	2026-09-14 07:35:11.596907	\N	\N
51	sZN33aUEHOTR	3YlQHTbZnj9h	8NZWkNhSD52w	\N	\N	\N	\N	2026-09-14 07:35:11.596907	\N	\N
52	y1bmBFJGNlF7	3YlQHTbZnj9h	XrX3uSO1stge	\N	\N	\N	\N	2026-09-14 07:35:11.645607	\N	\N
53	HBatdgYQ6F8d	4ZlPBz0lgP4i	MVrj9UkpVPgE	\N	\N	\N	\N	2026-09-14 07:35:11.773895	\N	\N
54	gwoA04OcERtf	4ZlPBz0lgP4i	Xs8M7BYRm3bk	\N	\N	\N	\N	2026-09-14 07:35:11.773895	\N	\N
55	1DMCvuSDOuzq	1qAN65zVgXIs	MVrj9UkpVPgE	\N	\N	\N	\N	2026-09-14 07:35:11.776125	\N	\N
56	VXvU9ip3TTsW	1qAN65zVgXIs	Xs8M7BYRm3bk	\N	\N	\N	\N	2026-09-14 07:35:11.776125	\N	\N
57	C8XBizaAhDwq	3YlQHTbZnj9h	KeedTSS6qwSM	\N	\N	\N	\N	2026-09-14 07:35:11.831899	\N	\N
58	aUsZZ3q9CqEY	1qAN65zVgXIs	VwSqhucz6wW0	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
59	gzUvVbpXLNrq	1qAN65zVgXIs	8IeMW7hL9Xhh	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
60	MwUKREbAIOlY	1qAN65zVgXIs	ivirbFRuDfQ4	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
61	UZd9wxmWXYhW	1qAN65zVgXIs	OlvPuhy15xdo	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
62	c1BubDeEzQ22	1qAN65zVgXIs	KeedTSS6qwSM	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
63	efYLChwZuNo9	1qAN65zVgXIs	AriSY4xYCqIv	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
64	fqP3eOdTZqyu	1qAN65zVgXIs	GiNNquK2kSHi	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
65	LkXLLUboAoUF	1qAN65zVgXIs	2ARBxHM5ZbVU	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
66	7zERrYECu69O	1qAN65zVgXIs	zJrRDCSl49tj	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
67	PxBdp9yhMaPW	1qAN65zVgXIs	RCgaM4KnVpJR	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
68	Bo3vHxqnolMw	1qAN65zVgXIs	XrX3uSO1stge	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
69	0VB5w90QkfV3	1qAN65zVgXIs	qbK1kmqm8BAA	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
70	9YnDcdVAE2zw	1qAN65zVgXIs	lM02C0kCrjbj	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
71	h21NSZGCpzBi	1qAN65zVgXIs	vArMNEpaVVQ3	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
72	goVicOzjacxO	1qAN65zVgXIs	seH0lWtn0PHG	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
73	pbeBpnoNyvaU	1qAN65zVgXIs	nxqneH6MLAd6	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
74	wrjYZdQwecxw	1qAN65zVgXIs	KP9botZO5Trb	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
75	Fi7TU0nIkWRd	1qAN65zVgXIs	9nFbpStH5Q3D	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
76	0DH1R5KCARyg	1qAN65zVgXIs	JoBLoJPShi4M	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
77	MVdsCWXzqibx	1qAN65zVgXIs	vpVjdx5VKoXG	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
78	Rp0zd0vIiXak	1qAN65zVgXIs	0FPwSMQLjndL	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
79	6YZnkjXRIAV2	1qAN65zVgXIs	DlIZgFbKA58s	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
80	xgEd9Y990cWW	1qAN65zVgXIs	hDzXCjPVoVGy	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
81	rliP4YAzRWt4	1qAN65zVgXIs	poImLKAQ9THQ	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
82	EZ31Hv6g8EuG	1qAN65zVgXIs	TxO9YdE4kSLK	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
83	1gM5EyTTbdYI	1qAN65zVgXIs	uo1RPd6mEUw3	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
84	FjM02QiIbwm6	1qAN65zVgXIs	8NZWkNhSD52w	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
85	elGHjGeqBRe0	1qAN65zVgXIs	olMAQPIKl0ey	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
86	FzmlNP3pwqQU	1qAN65zVgXIs	3wyvmGiYSslR	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
87	hlfnDGXibER4	1qAN65zVgXIs	imfu6FciXZM1	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
88	8Xm8JoNDY6l5	1qAN65zVgXIs	nbdjhUp9D7rW	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
89	mDP0lWsx113g	3YlQHTbZnj9h	VwSqhucz6wW0	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
90	XOPiglx8Z4yJ	3YlQHTbZnj9h	AriSY4xYCqIv	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
91	EUzXtx8V651O	3YlQHTbZnj9h	lM02C0kCrjbj	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
92	or3dixcHkikl	3YlQHTbZnj9h	9nFbpStH5Q3D	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
93	Np5i1BRFepZY	3YlQHTbZnj9h	JoBLoJPShi4M	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
94	gV8kSjBO98GP	3YlQHTbZnj9h	vpVjdx5VKoXG	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
95	vEhWs5wYZIbH	3YlQHTbZnj9h	0FPwSMQLjndL	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
96	JO3o0fTB4eeW	3YlQHTbZnj9h	DlIZgFbKA58s	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
97	b4Oz2NsxGRPl	3YlQHTbZnj9h	hDzXCjPVoVGy	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
98	jGvA40g9Wurj	3YlQHTbZnj9h	poImLKAQ9THQ	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
99	FWEE8QdHjJKc	3YlQHTbZnj9h	Xs8M7BYRm3bk	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
100	5wn1FpxfjR2M	3YlQHTbZnj9h	MVrj9UkpVPgE	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
101	naoKekeTM8Wi	3YlQHTbZnj9h	olMAQPIKl0ey	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
102	DqSEhy9Vlf3E	3YlQHTbZnj9h	3wyvmGiYSslR	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
103	gE9BUCjE0OxS	3YlQHTbZnj9h	imfu6FciXZM1	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
104	gJrKaVbo6wXJ	3YlQHTbZnj9h	nbdjhUp9D7rW	\N	\N	\N	\N	2026-09-14 07:35:12	2026-09-14 07:35:12	\N
\.


--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.roles (id, uuid, name, slug, description, parent_uuid, level, is_system, managed_by, metadata, status, created_at, updated_at, deleted_at) FROM stdin;
1	1qAN65zVgXIs	Superuser	superuser	System administrator with full access	\N	100	t	\N	\N	active	2026-09-14 07:35:11.126621	\N	\N
2	3YlQHTbZnj9h	Administrator	administrator	Site administrator with management access	\N	80	t	\N	\N	active	2026-09-14 07:35:11.126621	\N	\N
3	Wm7G9jjWOCwb	User	user	Standard user with basic access	\N	10	t	\N	\N	active	2026-09-14 07:35:11.126621	\N	\N
4	buVEuMnrPytp	Editor	editor	Editor	\N	50	t	\N	\N	active	2026-09-14 07:35:11.242591	\N	\N
5	4ZlPBz0lgP4i	Workspace Manager	workspace_manager	Create, suspend, manage, and access every workspace. Does not include global user management or system administration.	\N	90	t	\N	\N	active	2026-09-14 07:35:11.767246	\N	\N
\.


--
-- Data for Name: scheduled_jobs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.scheduled_jobs (id, uuid, name, schedule, handler_class, parameters, is_enabled, last_run, next_run, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: seo_meta; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.seo_meta (id, entry_uuid, locale, title, description, og_title, og_description, og_image, twitter_card, robots, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: settings; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.settings (key, value, updated_at) FROM stdin;
\.


--
-- Data for Name: signup_continuations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.signup_continuations (id, intent_uuid, current_hash, previous_hash, previous_operation_id, previous_valid_until, last_operation_id, last_operation_payload_hash, last_operation_status, last_operation_result, updated_at) FROM stdin;
\.


--
-- Data for Name: signup_daily_counters; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.signup_daily_counters (id, capability, day, count, updated_at) FROM stdin;
\.


--
-- Data for Name: signup_intents; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.signup_intents (id, uuid, kind, origin, email, username, first_name, last_name, password_hash, tenant_uuid, desired_slug, workspace_name, result_user_uuid, result_tenant_uuid, status, completion_outcome, request_ip_hash, expires_at, consumed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: signup_rate_counters; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.signup_rate_counters (id, dimension, bucket_hash, window_start, count, updated_at) FROM stdin;
\.


--
-- Data for Name: signup_verifiers; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.signup_verifiers (id, intent_uuid, otp_hash, attempts, expires_at, consumed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: starter_provenance; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.starter_provenance (id, uuid, tenant_uuid, definition_kind, definition_key, source_id, fingerprint, state, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: subscription_events; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.subscription_events (id, uuid, tenant_uuid, type, from_status, to_status, source, provider_gateway, provider_logical_event_key, data, created_at, subject_type, subject_uuid) FROM stdin;
\.


--
-- Data for Name: subscription_overrides; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.subscription_overrides (id, uuid, tenant_uuid, entitlement, value, expires_at, reason, created_at, updated_at, subject_type, subject_uuid) FROM stdin;
\.


--
-- Data for Name: subscription_plans; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.subscription_plans (id, uuid, plan_key, display_name, description, entitlements, provider_price_id, status, sort_order, created_at, updated_at, audience, owner_tenant_uuid, provider_identifiers) FROM stdin;
\.


--
-- Data for Name: subscription_provider_event_receipts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.subscription_provider_event_receipts (id, uuid, provider_gateway, provider_logical_event_key, event_type, candidate_tenant_uuid, candidate_subject_type, candidate_subject_uuid, candidate_plan_uuid, tenant_uuid, subject_type, subject_uuid, plan_uuid, outcome, rejection_code, data, created_at) FROM stdin;
\.


--
-- Data for Name: subscription_v2_preparation; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.subscription_v2_preparation (id, marker_key, catalog_signature, report, prepared_at) FROM stdin;
\.


--
-- Data for Name: subscriptions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.subscriptions (id, uuid, tenant_uuid, plan_key, status, trial_ends_at, current_period_end, grace_ends_at, canceled_at, provider_gateway, provider_customer_id, provider_subscription_id, provider_price_id, metadata, created_at, updated_at, subject_type, subject_uuid, plan_uuid, checkout_origination_uuid) FROM stdin;
\.


--
-- Data for Name: tenant_domains; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.tenant_domains (id, uuid, tenant_uuid, host, verification_status, status, verification_token, verified_at, last_checked_at, last_check_status, consecutive_failures, first_failure_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: tenant_memberships; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.tenant_memberships (id, uuid, tenant_uuid, user_uuid, role, status, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: tenant_role_availability; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.tenant_role_availability (id, tenant_uuid, role, status, updated_by, updated_at) FROM stdin;
\.


--
-- Data for Name: tenant_role_overrides; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.tenant_role_overrides (id, tenant_uuid, role_slug, capability, effect, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: tenant_role_policy; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.tenant_role_policy (id, tenant_uuid, version, updated_at) FROM stdin;
\.


--
-- Data for Name: tenant_roles; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.tenant_roles (id, tenant_uuid, slug, name, status, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: tenants; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.tenants (id, uuid, slug, name, status, settings, created_at, updated_at, deleted_at, deleted_from_status, purge_after) FROM stdin;
\.


--
-- Data for Name: thallo_commerce_checkout_attempts; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.thallo_commerce_checkout_attempts (id, tenant_uuid, idempotency_key, request_fingerprint, status, order_uuid, order_ref, guest_credential_ciphertext, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: thallo_commerce_payment_link_deliveries; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.thallo_commerce_payment_link_deliveries (id, uuid, tenant_uuid, idempotency_key, fingerprint, order_uuid, link_uuid, recipient_hash, mode, status, error_code, provider_message_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: thallo_commerce_product_links; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.thallo_commerce_product_links (id, uuid, tenant_uuid, product_uuid, entry_uuid, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: thallo_commerce_product_slugs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.thallo_commerce_product_slugs (id, tenant_uuid, slug, product_uuid, created_at) FROM stdin;
\.


--
-- Data for Name: thallo_system_flags; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.thallo_system_flags (key, value, updated_at) FROM stdin;
\.


--
-- Data for Name: thallo_tenant_api_key_bindings; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.thallo_tenant_api_key_bindings (id, api_key_uuid, tenant_uuid, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: thallo_tenant_purge_runs; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.thallo_tenant_purge_runs (id, uuid, tenant_uuid, requested_by_uuid, status, lease_expires_at, lease_owner, attempts, plan, artifacts, failed_handler, failed_phase, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: user_permissions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.user_permissions (id, uuid, user_uuid, permission_uuid, resource_filter, constraints, granted_by, expires_at, created_at) FROM stdin;
\.


--
-- Data for Name: user_roles; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.user_roles (id, uuid, user_uuid, role_uuid, scope, granted_by, expires_at, created_at) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.users (id, uuid, username, email, password, status, two_factor_enabled, email_verified_at, created_at, updated_at, deleted_at) FROM stdin;
\.


--
-- Data for Name: webhook_deliveries; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.webhook_deliveries (id, uuid, subscription_id, event, payload, status, attempts, response_code, response_body, delivered_at, next_retry_at, created_at) FROM stdin;
\.


--
-- Data for Name: webhook_subscriptions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.webhook_subscriptions (id, uuid, url, events, secret, is_active, metadata, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: workflow_review_states; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.workflow_review_states (id, entry_uuid, locale, state, submitted_by, submitted_at, reviewed_by, reviewed_at, updated_at) FROM stdin;
\.


--
-- Data for Name: workflow_transitions; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.workflow_transitions (id, entry_uuid, locale, from_state, to_state, action, actor_uuid, note, metadata, created_at) FROM stdin;
\.


--
-- Name: analytics_daily_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.analytics_daily_id_seq', 1, false);


--
-- Name: analytics_facts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.analytics_facts_id_seq', 1, false);


--
-- Name: api_keys_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.api_keys_id_seq', 1, false);


--
-- Name: api_metrics_daily_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.api_metrics_daily_id_seq', 1, false);


--
-- Name: api_metrics_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.api_metrics_id_seq', 1, false);


--
-- Name: api_rate_limits_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.api_rate_limits_id_seq', 1, false);


--
-- Name: audit_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.audit_logs_id_seq', 47, true);


--
-- Name: auth_refresh_tokens_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.auth_refresh_tokens_id_seq', 1, false);


--
-- Name: auth_sessions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.auth_sessions_id_seq', 1, false);


--
-- Name: blobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.blobs_id_seq', 1, true);


--
-- Name: block_type_migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.block_type_migrations_id_seq', 1, false);


--
-- Name: block_types_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.block_types_id_seq', 51, true);


--
-- Name: collection_definitions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.collection_definitions_id_seq', 1, false);


--
-- Name: collection_schema_changes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.collection_schema_changes_id_seq', 1, false);


--
-- Name: content_types_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.content_types_id_seq', 1, true);


--
-- Name: email_settings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.email_settings_id_seq', 1, false);


--
-- Name: email_templates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.email_templates_id_seq', 1, false);


--
-- Name: entries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.entries_id_seq', 4, true);


--
-- Name: entry_drafts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.entry_drafts_id_seq', 4, true);


--
-- Name: entry_publications_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.entry_publications_id_seq', 2, true);


--
-- Name: entry_redirects_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.entry_redirects_id_seq', 1, false);


--
-- Name: entry_references_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.entry_references_id_seq', 1, false);


--
-- Name: entry_routes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.entry_routes_id_seq', 2, true);


--
-- Name: entry_schedules_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.entry_schedules_id_seq', 1, false);


--
-- Name: entry_schema_migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.entry_schema_migrations_id_seq', 1, false);


--
-- Name: entry_versions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.entry_versions_id_seq', 4, true);


--
-- Name: extension_operations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.extension_operations_id_seq', 1, false);


--
-- Name: filter_indexes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.filter_indexes_id_seq', 1, false);


--
-- Name: form_submissions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.form_submissions_id_seq', 1, false);


--
-- Name: i18n_locales_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.i18n_locales_id_seq', 1, false);


--
-- Name: i18n_missing_translations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.i18n_missing_translations_id_seq', 1, false);


--
-- Name: i18n_translations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.i18n_translations_id_seq', 1, false);


--
-- Name: import_export_batches_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.import_export_batches_id_seq', 1, false);


--
-- Name: import_export_errors_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.import_export_errors_id_seq', 1, false);


--
-- Name: import_export_files_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.import_export_files_id_seq', 1, false);


--
-- Name: import_export_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.import_export_jobs_id_seq', 1, false);


--
-- Name: import_export_reports_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.import_export_reports_id_seq', 1, false);


--
-- Name: job_executions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.job_executions_id_seq', 1, false);


--
-- Name: media_assets_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.media_assets_id_seq', 1, false);


--
-- Name: media_meta_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.media_meta_id_seq', 1, false);


--
-- Name: media_usage_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.media_usage_id_seq', 1, false);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 94, true);


--
-- Name: navigation_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.navigation_items_id_seq', 1, false);


--
-- Name: navigation_menus_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.navigation_menus_id_seq', 1, false);


--
-- Name: notification_deliveries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.notification_deliveries_id_seq', 1, false);


--
-- Name: notification_preferences_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.notification_preferences_id_seq', 1, false);


--
-- Name: notification_retry_queue_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.notification_retry_queue_id_seq', 1, false);


--
-- Name: notification_templates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.notification_templates_id_seq', 1, false);


--
-- Name: notifications_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.notifications_id_seq', 1, false);


--
-- Name: permission_audit_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.permission_audit_id_seq', 1, false);


--
-- Name: permissions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.permissions_id_seq', 49, true);


--
-- Name: profiles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.profiles_id_seq', 1, false);


--
-- Name: published_entry_references_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.published_entry_references_id_seq', 1, false);


--
-- Name: queue_batches_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.queue_batches_id_seq', 1, false);


--
-- Name: queue_failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.queue_failed_jobs_id_seq', 1, false);


--
-- Name: queue_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.queue_jobs_id_seq', 1, false);


--
-- Name: released_hosts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.released_hosts_id_seq', 1, false);


--
-- Name: render_template_versions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.render_template_versions_id_seq', 1, false);


--
-- Name: render_templates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.render_templates_id_seq', 1, false);


--
-- Name: role_permissions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.role_permissions_id_seq', 104, true);


--
-- Name: roles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.roles_id_seq', 5, true);


--
-- Name: scheduled_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.scheduled_jobs_id_seq', 1, false);


--
-- Name: seo_meta_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.seo_meta_id_seq', 1, false);


--
-- Name: signup_continuations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.signup_continuations_id_seq', 1, false);


--
-- Name: signup_daily_counters_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.signup_daily_counters_id_seq', 1, false);


--
-- Name: signup_intents_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.signup_intents_id_seq', 1, false);


--
-- Name: signup_rate_counters_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.signup_rate_counters_id_seq', 1, false);


--
-- Name: signup_verifiers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.signup_verifiers_id_seq', 1, false);


--
-- Name: starter_provenance_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.starter_provenance_id_seq', 1, false);


--
-- Name: subscription_events_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.subscription_events_id_seq', 1, false);


--
-- Name: subscription_overrides_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.subscription_overrides_id_seq', 1, false);


--
-- Name: subscription_plans_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.subscription_plans_id_seq', 1, false);


--
-- Name: subscription_provider_event_receipts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.subscription_provider_event_receipts_id_seq', 1, false);


--
-- Name: subscription_v2_preparation_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.subscription_v2_preparation_id_seq', 1, false);


--
-- Name: subscriptions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.subscriptions_id_seq', 1, false);


--
-- Name: tenant_domains_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tenant_domains_id_seq', 1, false);


--
-- Name: tenant_memberships_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tenant_memberships_id_seq', 1, false);


--
-- Name: tenant_role_availability_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tenant_role_availability_id_seq', 1, false);


--
-- Name: tenant_role_overrides_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tenant_role_overrides_id_seq', 1, false);


--
-- Name: tenant_role_policy_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tenant_role_policy_id_seq', 1, false);


--
-- Name: tenant_roles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tenant_roles_id_seq', 1, false);


--
-- Name: tenants_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tenants_id_seq', 1, false);


--
-- Name: thallo_commerce_checkout_attempts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.thallo_commerce_checkout_attempts_id_seq', 1, false);


--
-- Name: thallo_commerce_payment_link_deliveries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.thallo_commerce_payment_link_deliveries_id_seq', 1, false);


--
-- Name: thallo_commerce_product_links_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.thallo_commerce_product_links_id_seq', 1, false);


--
-- Name: thallo_commerce_product_slugs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.thallo_commerce_product_slugs_id_seq', 1, false);


--
-- Name: thallo_tenant_api_key_bindings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.thallo_tenant_api_key_bindings_id_seq', 1, false);


--
-- Name: thallo_tenant_purge_runs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.thallo_tenant_purge_runs_id_seq', 1, false);


--
-- Name: user_permissions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.user_permissions_id_seq', 1, false);


--
-- Name: user_roles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.user_roles_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.users_id_seq', 1, false);


--
-- Name: webhook_deliveries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.webhook_deliveries_id_seq', 1, false);


--
-- Name: webhook_subscriptions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.webhook_subscriptions_id_seq', 1, false);


--
-- Name: workflow_review_states_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.workflow_review_states_id_seq', 1, false);


--
-- Name: workflow_transitions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.workflow_transitions_id_seq', 1, false);


--
-- Name: analytics_active_actors analytics_active_actors_day_metric_actor_type_actor_id_hash_uni; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_active_actors
    ADD CONSTRAINT analytics_active_actors_day_metric_actor_type_actor_id_hash_uni UNIQUE (day, metric, actor_type, actor_id_hash);


--
-- Name: analytics_daily analytics_daily_day_event_subject_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_daily
    ADD CONSTRAINT analytics_daily_day_event_subject_unique UNIQUE (day, event, subject);


--
-- Name: analytics_daily analytics_daily_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_daily
    ADD CONSTRAINT analytics_daily_pkey PRIMARY KEY (id);


--
-- Name: analytics_facts analytics_facts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.analytics_facts
    ADD CONSTRAINT analytics_facts_pkey PRIMARY KEY (id);


--
-- Name: api_keys api_keys_key_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_keys
    ADD CONSTRAINT api_keys_key_hash_unique UNIQUE (key_hash);


--
-- Name: api_keys api_keys_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_keys
    ADD CONSTRAINT api_keys_pkey PRIMARY KEY (id);


--
-- Name: api_keys api_keys_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_keys
    ADD CONSTRAINT api_keys_uuid_unique UNIQUE (uuid);


--
-- Name: api_metrics_daily api_metrics_daily_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_metrics_daily
    ADD CONSTRAINT api_metrics_daily_pkey PRIMARY KEY (id);


--
-- Name: api_metrics api_metrics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_metrics
    ADD CONSTRAINT api_metrics_pkey PRIMARY KEY (id);


--
-- Name: api_rate_limits api_rate_limits_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_rate_limits
    ADD CONSTRAINT api_rate_limits_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_uuid_unique UNIQUE (uuid);


--
-- Name: auth_refresh_tokens auth_refresh_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_refresh_tokens
    ADD CONSTRAINT auth_refresh_tokens_pkey PRIMARY KEY (id);


--
-- Name: auth_refresh_tokens auth_refresh_tokens_token_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_refresh_tokens
    ADD CONSTRAINT auth_refresh_tokens_token_hash_unique UNIQUE (token_hash);


--
-- Name: auth_refresh_tokens auth_refresh_tokens_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_refresh_tokens
    ADD CONSTRAINT auth_refresh_tokens_uuid_unique UNIQUE (uuid);


--
-- Name: auth_sessions auth_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_sessions
    ADD CONSTRAINT auth_sessions_pkey PRIMARY KEY (id);


--
-- Name: auth_sessions auth_sessions_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_sessions
    ADD CONSTRAINT auth_sessions_uuid_unique UNIQUE (uuid);


--
-- Name: blobs blobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blobs
    ADD CONSTRAINT blobs_pkey PRIMARY KEY (id);


--
-- Name: blobs blobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.blobs
    ADD CONSTRAINT blobs_uuid_unique UNIQUE (uuid);


--
-- Name: block_type_migrations block_type_migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.block_type_migrations
    ADD CONSTRAINT block_type_migrations_pkey PRIMARY KEY (id);


--
-- Name: block_type_migrations block_type_migrations_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.block_type_migrations
    ADD CONSTRAINT block_type_migrations_uuid_unique UNIQUE (uuid);


--
-- Name: block_types block_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.block_types
    ADD CONSTRAINT block_types_pkey PRIMARY KEY (id);


--
-- Name: block_types block_types_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.block_types
    ADD CONSTRAINT block_types_uuid_unique UNIQUE (uuid);


--
-- Name: collection_definitions collection_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collection_definitions
    ADD CONSTRAINT collection_definitions_pkey PRIMARY KEY (id);


--
-- Name: collection_definitions collection_definitions_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collection_definitions
    ADD CONSTRAINT collection_definitions_uuid_unique UNIQUE (uuid);


--
-- Name: collection_schema_changes collection_schema_changes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collection_schema_changes
    ADD CONSTRAINT collection_schema_changes_pkey PRIMARY KEY (id);


--
-- Name: collection_schema_changes collection_schema_changes_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collection_schema_changes
    ADD CONSTRAINT collection_schema_changes_uuid_unique UNIQUE (uuid);


--
-- Name: content_types content_types_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.content_types
    ADD CONSTRAINT content_types_pkey PRIMARY KEY (id);


--
-- Name: content_types content_types_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.content_types
    ADD CONSTRAINT content_types_slug_unique UNIQUE (slug);


--
-- Name: content_types content_types_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.content_types
    ADD CONSTRAINT content_types_uuid_unique UNIQUE (uuid);


--
-- Name: email_settings email_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_settings
    ADD CONSTRAINT email_settings_pkey PRIMARY KEY (id);


--
-- Name: email_settings email_settings_setting_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_settings
    ADD CONSTRAINT email_settings_setting_key_unique UNIQUE (setting_key);


--
-- Name: email_templates email_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_templates
    ADD CONSTRAINT email_templates_pkey PRIMARY KEY (id);


--
-- Name: email_templates email_templates_template_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_templates
    ADD CONSTRAINT email_templates_template_key_unique UNIQUE (template_key);


--
-- Name: email_templates email_templates_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_templates
    ADD CONSTRAINT email_templates_uuid_unique UNIQUE (uuid);


--
-- Name: entries entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entries
    ADD CONSTRAINT entries_pkey PRIMARY KEY (id);


--
-- Name: entries entries_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entries
    ADD CONSTRAINT entries_uuid_unique UNIQUE (uuid);


--
-- Name: entry_drafts entry_drafts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_drafts
    ADD CONSTRAINT entry_drafts_pkey PRIMARY KEY (id);


--
-- Name: entry_publications entry_publications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_publications
    ADD CONSTRAINT entry_publications_pkey PRIMARY KEY (id);


--
-- Name: entry_redirects entry_redirects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_redirects
    ADD CONSTRAINT entry_redirects_pkey PRIMARY KEY (id);


--
-- Name: entry_redirects entry_redirects_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_redirects
    ADD CONSTRAINT entry_redirects_uuid_unique UNIQUE (uuid);


--
-- Name: entry_references entry_references_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_references
    ADD CONSTRAINT entry_references_pkey PRIMARY KEY (id);


--
-- Name: entry_routes entry_routes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_routes
    ADD CONSTRAINT entry_routes_pkey PRIMARY KEY (id);


--
-- Name: entry_schedules entry_schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_schedules
    ADD CONSTRAINT entry_schedules_pkey PRIMARY KEY (id);


--
-- Name: entry_schedules entry_schedules_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_schedules
    ADD CONSTRAINT entry_schedules_uuid_unique UNIQUE (uuid);


--
-- Name: entry_schema_migrations entry_schema_migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_schema_migrations
    ADD CONSTRAINT entry_schema_migrations_pkey PRIMARY KEY (id);


--
-- Name: entry_schema_migrations entry_schema_migrations_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_schema_migrations
    ADD CONSTRAINT entry_schema_migrations_uuid_unique UNIQUE (uuid);


--
-- Name: entry_versions entry_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_versions
    ADD CONSTRAINT entry_versions_pkey PRIMARY KEY (id);


--
-- Name: entry_versions entry_versions_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_versions
    ADD CONSTRAINT entry_versions_uuid_unique UNIQUE (uuid);


--
-- Name: extension_operations extension_operations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.extension_operations
    ADD CONSTRAINT extension_operations_pkey PRIMARY KEY (id);


--
-- Name: filter_indexes filter_indexes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.filter_indexes
    ADD CONSTRAINT filter_indexes_pkey PRIMARY KEY (id);


--
-- Name: filter_indexes filter_indexes_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.filter_indexes
    ADD CONSTRAINT filter_indexes_uuid_unique UNIQUE (uuid);


--
-- Name: form_submissions form_submissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.form_submissions
    ADD CONSTRAINT form_submissions_pkey PRIMARY KEY (id);


--
-- Name: form_submissions form_submissions_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.form_submissions
    ADD CONSTRAINT form_submissions_uuid_unique UNIQUE (uuid);


--
-- Name: i18n_locales i18n_locales_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_locales
    ADD CONSTRAINT i18n_locales_code_unique UNIQUE (code);


--
-- Name: i18n_locales i18n_locales_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_locales
    ADD CONSTRAINT i18n_locales_pkey PRIMARY KEY (id);


--
-- Name: i18n_locales i18n_locales_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_locales
    ADD CONSTRAINT i18n_locales_uuid_unique UNIQUE (uuid);


--
-- Name: i18n_missing_translations i18n_missing_translations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_missing_translations
    ADD CONSTRAINT i18n_missing_translations_pkey PRIMARY KEY (id);


--
-- Name: i18n_missing_translations i18n_missing_translations_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_missing_translations
    ADD CONSTRAINT i18n_missing_translations_uuid_unique UNIQUE (uuid);


--
-- Name: i18n_translations i18n_translations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_translations
    ADD CONSTRAINT i18n_translations_pkey PRIMARY KEY (id);


--
-- Name: i18n_translations i18n_translations_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_translations
    ADD CONSTRAINT i18n_translations_uuid_unique UNIQUE (uuid);


--
-- Name: api_metrics_daily idx_api_metrics_daily_date_endpoint_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_metrics_daily
    ADD CONSTRAINT idx_api_metrics_daily_date_endpoint_key UNIQUE (date, endpoint_key);


--
-- Name: api_rate_limits idx_api_rate_limits_ip_endpoint; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_rate_limits
    ADD CONSTRAINT idx_api_rate_limits_ip_endpoint UNIQUE (ip, endpoint);


--
-- Name: import_export_batches import_export_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_batches
    ADD CONSTRAINT import_export_batches_pkey PRIMARY KEY (id);


--
-- Name: import_export_batches import_export_batches_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_batches
    ADD CONSTRAINT import_export_batches_uuid_unique UNIQUE (uuid);


--
-- Name: import_export_errors import_export_errors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_errors
    ADD CONSTRAINT import_export_errors_pkey PRIMARY KEY (id);


--
-- Name: import_export_errors import_export_errors_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_errors
    ADD CONSTRAINT import_export_errors_uuid_unique UNIQUE (uuid);


--
-- Name: import_export_files import_export_files_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_files
    ADD CONSTRAINT import_export_files_pkey PRIMARY KEY (id);


--
-- Name: import_export_files import_export_files_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_files
    ADD CONSTRAINT import_export_files_uuid_unique UNIQUE (uuid);


--
-- Name: import_export_jobs import_export_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_jobs
    ADD CONSTRAINT import_export_jobs_pkey PRIMARY KEY (id);


--
-- Name: import_export_jobs import_export_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_jobs
    ADD CONSTRAINT import_export_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: import_export_reports import_export_reports_job_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_reports
    ADD CONSTRAINT import_export_reports_job_uuid_unique UNIQUE (job_uuid);


--
-- Name: import_export_reports import_export_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_reports
    ADD CONSTRAINT import_export_reports_pkey PRIMARY KEY (id);


--
-- Name: import_export_reports import_export_reports_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_reports
    ADD CONSTRAINT import_export_reports_uuid_unique UNIQUE (uuid);


--
-- Name: job_executions job_executions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_executions
    ADD CONSTRAINT job_executions_pkey PRIMARY KEY (id);


--
-- Name: job_executions job_executions_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_executions
    ADD CONSTRAINT job_executions_uuid_unique UNIQUE (uuid);


--
-- Name: locks locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.locks
    ADD CONSTRAINT locks_pkey PRIMARY KEY (key_id);


--
-- Name: media_assets media_assets_blob_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_assets
    ADD CONSTRAINT media_assets_blob_uuid_unique UNIQUE (blob_uuid);


--
-- Name: media_assets media_assets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_assets
    ADD CONSTRAINT media_assets_pkey PRIMARY KEY (id);


--
-- Name: media_meta media_meta_blob_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_meta
    ADD CONSTRAINT media_meta_blob_uuid_unique UNIQUE (blob_uuid);


--
-- Name: media_meta media_meta_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_meta
    ADD CONSTRAINT media_meta_pkey PRIMARY KEY (id);


--
-- Name: media_usage media_usage_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_usage
    ADD CONSTRAINT media_usage_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_source_migration_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_source_migration_unique UNIQUE (source, migration);


--
-- Name: navigation_items navigation_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.navigation_items
    ADD CONSTRAINT navigation_items_pkey PRIMARY KEY (id);


--
-- Name: navigation_menus navigation_menus_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.navigation_menus
    ADD CONSTRAINT navigation_menus_pkey PRIMARY KEY (id);


--
-- Name: navigation_menus navigation_menus_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.navigation_menus
    ADD CONSTRAINT navigation_menus_uuid_unique UNIQUE (uuid);


--
-- Name: notification_deliveries notification_deliveries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_deliveries
    ADD CONSTRAINT notification_deliveries_pkey PRIMARY KEY (id);


--
-- Name: notification_preferences notification_preferences_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_preferences
    ADD CONSTRAINT notification_preferences_pkey PRIMARY KEY (id);


--
-- Name: notification_preferences notification_preferences_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_preferences
    ADD CONSTRAINT notification_preferences_uuid_unique UNIQUE (uuid);


--
-- Name: notification_retry_queue notification_retry_queue_notification_id_channel_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_retry_queue
    ADD CONSTRAINT notification_retry_queue_notification_id_channel_unique UNIQUE (notification_id, channel);


--
-- Name: notification_retry_queue notification_retry_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_retry_queue
    ADD CONSTRAINT notification_retry_queue_pkey PRIMARY KEY (id);


--
-- Name: notification_templates notification_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates
    ADD CONSTRAINT notification_templates_pkey PRIMARY KEY (id);


--
-- Name: notification_templates notification_templates_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates
    ADD CONSTRAINT notification_templates_uuid_unique UNIQUE (uuid);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_uuid_unique UNIQUE (uuid);


--
-- Name: permission_audit permission_audit_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permission_audit
    ADD CONSTRAINT permission_audit_pkey PRIMARY KEY (id);


--
-- Name: permission_audit permission_audit_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permission_audit
    ADD CONSTRAINT permission_audit_uuid_unique UNIQUE (uuid);


--
-- Name: permissions permissions_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_name_unique UNIQUE (name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: permissions permissions_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_slug_unique UNIQUE (slug);


--
-- Name: permissions permissions_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_uuid_unique UNIQUE (uuid);


--
-- Name: profiles profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.profiles
    ADD CONSTRAINT profiles_pkey PRIMARY KEY (id);


--
-- Name: profiles profiles_user_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.profiles
    ADD CONSTRAINT profiles_user_uuid_unique UNIQUE (user_uuid);


--
-- Name: profiles profiles_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.profiles
    ADD CONSTRAINT profiles_uuid_unique UNIQUE (uuid);


--
-- Name: published_entry_references published_entry_references_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.published_entry_references
    ADD CONSTRAINT published_entry_references_pkey PRIMARY KEY (id);


--
-- Name: queue_batches queue_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_batches
    ADD CONSTRAINT queue_batches_pkey PRIMARY KEY (id);


--
-- Name: queue_batches queue_batches_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_batches
    ADD CONSTRAINT queue_batches_uuid_unique UNIQUE (uuid);


--
-- Name: queue_failed_jobs queue_failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_failed_jobs
    ADD CONSTRAINT queue_failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: queue_failed_jobs queue_failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_failed_jobs
    ADD CONSTRAINT queue_failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: queue_jobs queue_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_jobs
    ADD CONSTRAINT queue_jobs_pkey PRIMARY KEY (id);


--
-- Name: queue_jobs queue_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_jobs
    ADD CONSTRAINT queue_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: regions regions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regions
    ADD CONSTRAINT regions_pkey PRIMARY KEY (slug);


--
-- Name: released_hosts released_hosts_host_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.released_hosts
    ADD CONSTRAINT released_hosts_host_unique UNIQUE (host);


--
-- Name: released_hosts released_hosts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.released_hosts
    ADD CONSTRAINT released_hosts_pkey PRIMARY KEY (id);


--
-- Name: render_template_versions render_template_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.render_template_versions
    ADD CONSTRAINT render_template_versions_pkey PRIMARY KEY (id);


--
-- Name: render_template_versions render_template_versions_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.render_template_versions
    ADD CONSTRAINT render_template_versions_uuid_unique UNIQUE (uuid);


--
-- Name: render_templates render_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.render_templates
    ADD CONSTRAINT render_templates_pkey PRIMARY KEY (id);


--
-- Name: render_templates render_templates_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.render_templates
    ADD CONSTRAINT render_templates_uuid_unique UNIQUE (uuid);


--
-- Name: role_permissions role_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_pkey PRIMARY KEY (id);


--
-- Name: role_permissions role_permissions_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_uuid_unique UNIQUE (uuid);


--
-- Name: roles roles_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_unique UNIQUE (name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: roles roles_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_slug_unique UNIQUE (slug);


--
-- Name: roles roles_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_uuid_unique UNIQUE (uuid);


--
-- Name: scheduled_jobs scheduled_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scheduled_jobs
    ADD CONSTRAINT scheduled_jobs_pkey PRIMARY KEY (id);


--
-- Name: scheduled_jobs scheduled_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scheduled_jobs
    ADD CONSTRAINT scheduled_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: seo_meta seo_meta_entry_uuid_locale_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_meta
    ADD CONSTRAINT seo_meta_entry_uuid_locale_unique UNIQUE (entry_uuid, locale);


--
-- Name: seo_meta seo_meta_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seo_meta
    ADD CONSTRAINT seo_meta_pkey PRIMARY KEY (id);


--
-- Name: settings settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settings
    ADD CONSTRAINT settings_pkey PRIMARY KEY (key);


--
-- Name: signup_continuations signup_continuations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_continuations
    ADD CONSTRAINT signup_continuations_pkey PRIMARY KEY (id);


--
-- Name: signup_daily_counters signup_daily_counters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_daily_counters
    ADD CONSTRAINT signup_daily_counters_pkey PRIMARY KEY (id);


--
-- Name: signup_intents signup_intents_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_intents
    ADD CONSTRAINT signup_intents_pkey PRIMARY KEY (id);


--
-- Name: signup_rate_counters signup_rate_counters_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_rate_counters
    ADD CONSTRAINT signup_rate_counters_pkey PRIMARY KEY (id);


--
-- Name: signup_verifiers signup_verifiers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_verifiers
    ADD CONSTRAINT signup_verifiers_pkey PRIMARY KEY (id);


--
-- Name: starter_provenance starter_provenance_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.starter_provenance
    ADD CONSTRAINT starter_provenance_pkey PRIMARY KEY (id);


--
-- Name: starter_provenance starter_provenance_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.starter_provenance
    ADD CONSTRAINT starter_provenance_uuid_unique UNIQUE (uuid);


--
-- Name: subscription_events subscription_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_events
    ADD CONSTRAINT subscription_events_pkey PRIMARY KEY (id);


--
-- Name: subscription_events subscription_events_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_events
    ADD CONSTRAINT subscription_events_uuid_unique UNIQUE (uuid);


--
-- Name: subscription_overrides subscription_overrides_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_overrides
    ADD CONSTRAINT subscription_overrides_pkey PRIMARY KEY (id);


--
-- Name: subscription_overrides subscription_overrides_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_overrides
    ADD CONSTRAINT subscription_overrides_uuid_unique UNIQUE (uuid);


--
-- Name: subscription_plans subscription_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_plans
    ADD CONSTRAINT subscription_plans_pkey PRIMARY KEY (id);


--
-- Name: subscription_plans subscription_plans_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_plans
    ADD CONSTRAINT subscription_plans_uuid_unique UNIQUE (uuid);


--
-- Name: subscription_provider_event_receipts subscription_provider_event_receipts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_provider_event_receipts
    ADD CONSTRAINT subscription_provider_event_receipts_pkey PRIMARY KEY (id);


--
-- Name: subscription_v2_preparation subscription_v2_preparation_marker_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_v2_preparation
    ADD CONSTRAINT subscription_v2_preparation_marker_key_unique UNIQUE (marker_key);


--
-- Name: subscription_v2_preparation subscription_v2_preparation_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_v2_preparation
    ADD CONSTRAINT subscription_v2_preparation_pkey PRIMARY KEY (id);


--
-- Name: subscriptions subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_pkey PRIMARY KEY (id);


--
-- Name: subscriptions subscriptions_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_uuid_unique UNIQUE (uuid);


--
-- Name: tenant_domains tenant_domains_host_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_domains
    ADD CONSTRAINT tenant_domains_host_unique UNIQUE (host);


--
-- Name: tenant_domains tenant_domains_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_domains
    ADD CONSTRAINT tenant_domains_pkey PRIMARY KEY (id);


--
-- Name: tenant_domains tenant_domains_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_domains
    ADD CONSTRAINT tenant_domains_uuid_unique UNIQUE (uuid);


--
-- Name: tenant_memberships tenant_memberships_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_memberships
    ADD CONSTRAINT tenant_memberships_pkey PRIMARY KEY (id);


--
-- Name: tenant_memberships tenant_memberships_tenant_uuid_user_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_memberships
    ADD CONSTRAINT tenant_memberships_tenant_uuid_user_uuid_unique UNIQUE (tenant_uuid, user_uuid);


--
-- Name: tenant_memberships tenant_memberships_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_memberships
    ADD CONSTRAINT tenant_memberships_uuid_unique UNIQUE (uuid);


--
-- Name: tenant_role_availability tenant_role_availability_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_role_availability
    ADD CONSTRAINT tenant_role_availability_pkey PRIMARY KEY (id);


--
-- Name: tenant_role_overrides tenant_role_overrides_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_role_overrides
    ADD CONSTRAINT tenant_role_overrides_pkey PRIMARY KEY (id);


--
-- Name: tenant_role_policy tenant_role_policy_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_role_policy
    ADD CONSTRAINT tenant_role_policy_pkey PRIMARY KEY (id);


--
-- Name: tenant_roles tenant_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_roles
    ADD CONSTRAINT tenant_roles_pkey PRIMARY KEY (id);


--
-- Name: tenants tenants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_pkey PRIMARY KEY (id);


--
-- Name: tenants tenants_slug_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_slug_unique UNIQUE (slug);


--
-- Name: tenants tenants_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenants
    ADD CONSTRAINT tenants_uuid_unique UNIQUE (uuid);


--
-- Name: thallo_commerce_checkout_attempts thallo_commerce_checkout_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_checkout_attempts
    ADD CONSTRAINT thallo_commerce_checkout_attempts_pkey PRIMARY KEY (id);


--
-- Name: thallo_commerce_payment_link_deliveries thallo_commerce_payment_link_deliveries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_payment_link_deliveries
    ADD CONSTRAINT thallo_commerce_payment_link_deliveries_pkey PRIMARY KEY (id);


--
-- Name: thallo_commerce_product_links thallo_commerce_product_links_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_product_links
    ADD CONSTRAINT thallo_commerce_product_links_pkey PRIMARY KEY (id);


--
-- Name: thallo_commerce_product_slugs thallo_commerce_product_slugs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_product_slugs
    ADD CONSTRAINT thallo_commerce_product_slugs_pkey PRIMARY KEY (id);


--
-- Name: thallo_system_flags thallo_system_flags_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_system_flags
    ADD CONSTRAINT thallo_system_flags_pkey PRIMARY KEY (key);


--
-- Name: thallo_tenant_api_key_bindings thallo_tenant_api_key_bindings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_tenant_api_key_bindings
    ADD CONSTRAINT thallo_tenant_api_key_bindings_pkey PRIMARY KEY (id);


--
-- Name: thallo_tenant_purge_runs thallo_tenant_purge_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_tenant_purge_runs
    ADD CONSTRAINT thallo_tenant_purge_runs_pkey PRIMARY KEY (id);


--
-- Name: thallo_tenant_purge_runs thallo_tenant_purge_runs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_tenant_purge_runs
    ADD CONSTRAINT thallo_tenant_purge_runs_uuid_unique UNIQUE (uuid);


--
-- Name: block_types uniq_block_type_slug; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.block_types
    ADD CONSTRAINT uniq_block_type_slug UNIQUE (slug);


--
-- Name: collection_definitions uniq_collection_def_table_name; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collection_definitions
    ADD CONSTRAINT uniq_collection_def_table_name UNIQUE (table_name);


--
-- Name: collection_definitions uniq_collection_def_tenant_name; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.collection_definitions
    ADD CONSTRAINT uniq_collection_def_tenant_name UNIQUE (tenant_uuid, name);


--
-- Name: thallo_commerce_checkout_attempts uniq_commerce_checkout_attempt_tenant_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_checkout_attempts
    ADD CONSTRAINT uniq_commerce_checkout_attempt_tenant_key UNIQUE (tenant_uuid, idempotency_key);


--
-- Name: thallo_commerce_payment_link_deliveries uniq_commerce_link_delivery_tenant_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_payment_link_deliveries
    ADD CONSTRAINT uniq_commerce_link_delivery_tenant_key UNIQUE (tenant_uuid, idempotency_key);


--
-- Name: thallo_commerce_product_links uniq_commerce_product_link_tenant_entry; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_product_links
    ADD CONSTRAINT uniq_commerce_product_link_tenant_entry UNIQUE (tenant_uuid, entry_uuid);


--
-- Name: thallo_commerce_product_links uniq_commerce_product_link_tenant_product; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_product_links
    ADD CONSTRAINT uniq_commerce_product_link_tenant_product UNIQUE (tenant_uuid, product_uuid);


--
-- Name: thallo_commerce_product_slugs uniq_commerce_product_slug_tenant_slug; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_commerce_product_slugs
    ADD CONSTRAINT uniq_commerce_product_slug_tenant_slug UNIQUE (tenant_uuid, slug);


--
-- Name: entry_drafts uniq_draft_entry_locale; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_drafts
    ADD CONSTRAINT uniq_draft_entry_locale UNIQUE (entry_uuid, locale);


--
-- Name: subscription_events uniq_event_gateway_logical_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_events
    ADD CONSTRAINT uniq_event_gateway_logical_key UNIQUE (provider_gateway, provider_logical_event_key);


--
-- Name: filter_indexes uniq_filter_index_type_field; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.filter_indexes
    ADD CONSTRAINT uniq_filter_index_type_field UNIQUE (content_type_uuid, field);


--
-- Name: i18n_missing_translations uniq_i18n_missing_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_missing_translations
    ADD CONSTRAINT uniq_i18n_missing_key UNIQUE (domain, locale, key);


--
-- Name: i18n_translations uniq_i18n_translation_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.i18n_translations
    ADD CONSTRAINT uniq_i18n_translation_key UNIQUE (domain, locale, key);


--
-- Name: import_export_batches uniq_import_export_batch_sequence; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_batches
    ADD CONSTRAINT uniq_import_export_batch_sequence UNIQUE (job_uuid, sequence);


--
-- Name: media_usage uniq_media_usage; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_usage
    ADD CONSTRAINT uniq_media_usage UNIQUE (blob_uuid, entry_uuid);


--
-- Name: navigation_menus uniq_navigation_menu_slug; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.navigation_menus
    ADD CONSTRAINT uniq_navigation_menu_slug UNIQUE (slug);


--
-- Name: entry_publications uniq_publication_entry_locale; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_publications
    ADD CONSTRAINT uniq_publication_entry_locale UNIQUE (entry_uuid, locale);


--
-- Name: published_entry_references uniq_pubref_source_locale_field_target; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.published_entry_references
    ADD CONSTRAINT uniq_pubref_source_locale_field_target UNIQUE (source_entry_uuid, locale, field, target_entry_uuid);


--
-- Name: subscription_provider_event_receipts uniq_receipts_gateway_logical_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_provider_event_receipts
    ADD CONSTRAINT uniq_receipts_gateway_logical_key UNIQUE (provider_gateway, provider_logical_event_key);


--
-- Name: entry_redirects uniq_redirect_type_locale_source; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_redirects
    ADD CONSTRAINT uniq_redirect_type_locale_source UNIQUE (content_type_uuid, locale, source_slug);


--
-- Name: entry_references uniq_reference_source_field_target_locale; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_references
    ADD CONSTRAINT uniq_reference_source_field_target_locale UNIQUE (source_entry_uuid, source_field, target_entry_uuid, locale);


--
-- Name: render_templates uniq_render_template_theme_path; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.render_templates
    ADD CONSTRAINT uniq_render_template_theme_path UNIQUE (theme, path);


--
-- Name: entry_routes uniq_route_type_locale_slug; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_routes
    ADD CONSTRAINT uniq_route_type_locale_slug UNIQUE (content_type_uuid, locale, slug);


--
-- Name: signup_continuations uniq_signup_continuation_intent; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_continuations
    ADD CONSTRAINT uniq_signup_continuation_intent UNIQUE (intent_uuid);


--
-- Name: signup_daily_counters uniq_signup_daily_cap; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_daily_counters
    ADD CONSTRAINT uniq_signup_daily_cap UNIQUE (capability, day);


--
-- Name: signup_intents uniq_signup_intent_uuid; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_intents
    ADD CONSTRAINT uniq_signup_intent_uuid UNIQUE (uuid);


--
-- Name: signup_rate_counters uniq_signup_rate_bucket; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_rate_counters
    ADD CONSTRAINT uniq_signup_rate_bucket UNIQUE (dimension, bucket_hash, window_start);


--
-- Name: signup_verifiers uniq_signup_verifier_intent; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.signup_verifiers
    ADD CONSTRAINT uniq_signup_verifier_intent UNIQUE (intent_uuid);


--
-- Name: starter_provenance uniq_starter_provenance_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.starter_provenance
    ADD CONSTRAINT uniq_starter_provenance_key UNIQUE (tenant_uuid, definition_kind, definition_key);


--
-- Name: starter_provenance uniq_starter_provenance_source; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.starter_provenance
    ADD CONSTRAINT uniq_starter_provenance_source UNIQUE (tenant_uuid, definition_kind, source_id);


--
-- Name: subscriptions uniq_subscriptions_provider_sub; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT uniq_subscriptions_provider_sub UNIQUE (provider_gateway, provider_subscription_id);


--
-- Name: thallo_tenant_api_key_bindings uniq_tenant_api_key_binding; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thallo_tenant_api_key_bindings
    ADD CONSTRAINT uniq_tenant_api_key_binding UNIQUE (api_key_uuid);


--
-- Name: tenant_role_availability uniq_tenant_role_availability; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_role_availability
    ADD CONSTRAINT uniq_tenant_role_availability UNIQUE (tenant_uuid, role);


--
-- Name: tenant_role_overrides uniq_tenant_role_override; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_role_overrides
    ADD CONSTRAINT uniq_tenant_role_override UNIQUE (tenant_uuid, role_slug, capability);


--
-- Name: tenant_role_policy uniq_tenant_role_policy; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_role_policy
    ADD CONSTRAINT uniq_tenant_role_policy UNIQUE (tenant_uuid);


--
-- Name: tenant_roles uniq_tenant_role_slug; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_roles
    ADD CONSTRAINT uniq_tenant_role_slug UNIQUE (tenant_uuid, slug);


--
-- Name: entry_versions uniq_version_entry_locale_version; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entry_versions
    ADD CONSTRAINT uniq_version_entry_locale_version UNIQUE (entry_uuid, locale, version);


--
-- Name: workflow_review_states uniq_workflow_state_entry_locale; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_review_states
    ADD CONSTRAINT uniq_workflow_state_entry_locale UNIQUE (entry_uuid, locale);


--
-- Name: notification_deliveries unique_notification_delivery_channel; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_deliveries
    ADD CONSTRAINT unique_notification_delivery_channel UNIQUE (notification_uuid, channel);


--
-- Name: notification_preferences unique_notification_pref; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_preferences
    ADD CONSTRAINT unique_notification_pref UNIQUE (notifiable_type, notifiable_id, notification_type);


--
-- Name: notification_templates unique_notification_template; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_templates
    ADD CONSTRAINT unique_notification_template UNIQUE (notification_type, channel, name);


--
-- Name: user_permissions user_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_permissions
    ADD CONSTRAINT user_permissions_pkey PRIMARY KEY (id);


--
-- Name: user_permissions user_permissions_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_permissions
    ADD CONSTRAINT user_permissions_uuid_unique UNIQUE (uuid);


--
-- Name: user_roles user_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_pkey PRIMARY KEY (id);


--
-- Name: user_roles user_roles_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT user_roles_uuid_unique UNIQUE (uuid);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_unique UNIQUE (username);


--
-- Name: users users_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_uuid_unique UNIQUE (uuid);


--
-- Name: webhook_deliveries webhook_deliveries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhook_deliveries
    ADD CONSTRAINT webhook_deliveries_pkey PRIMARY KEY (id);


--
-- Name: webhook_deliveries webhook_deliveries_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhook_deliveries
    ADD CONSTRAINT webhook_deliveries_uuid_unique UNIQUE (uuid);


--
-- Name: webhook_subscriptions webhook_subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhook_subscriptions
    ADD CONSTRAINT webhook_subscriptions_pkey PRIMARY KEY (id);


--
-- Name: webhook_subscriptions webhook_subscriptions_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.webhook_subscriptions
    ADD CONSTRAINT webhook_subscriptions_uuid_unique UNIQUE (uuid);


--
-- Name: workflow_review_states workflow_review_states_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_review_states
    ADD CONSTRAINT workflow_review_states_pkey PRIMARY KEY (id);


--
-- Name: workflow_transitions workflow_transitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.workflow_transitions
    ADD CONSTRAINT workflow_transitions_pkey PRIMARY KEY (id);


--
-- Name: analytics_facts_category_occurred_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX analytics_facts_category_occurred_at_index ON public.analytics_facts USING btree (category, occurred_at);


--
-- Name: analytics_facts_event_occurred_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX analytics_facts_event_occurred_at_index ON public.analytics_facts USING btree (event, occurred_at);


--
-- Name: analytics_facts_occurred_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX analytics_facts_occurred_at_index ON public.analytics_facts USING btree (occurred_at);


--
-- Name: api_keys_key_prefix_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX api_keys_key_prefix_index ON public.api_keys USING btree (key_prefix);


--
-- Name: api_keys_user_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX api_keys_user_uuid_index ON public.api_keys USING btree (user_uuid);


--
-- Name: audit_logs_occurred_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_occurred_at_index ON public.audit_logs USING btree (occurred_at);


--
-- Name: auth_refresh_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auth_refresh_tokens_expires_at_index ON public.auth_refresh_tokens USING btree (expires_at);


--
-- Name: auth_refresh_tokens_parent_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auth_refresh_tokens_parent_uuid_index ON public.auth_refresh_tokens USING btree (parent_uuid);


--
-- Name: auth_refresh_tokens_session_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auth_refresh_tokens_session_uuid_index ON public.auth_refresh_tokens USING btree (session_uuid);


--
-- Name: auth_refresh_tokens_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auth_refresh_tokens_status_index ON public.auth_refresh_tokens USING btree (status);


--
-- Name: auth_refresh_tokens_user_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auth_refresh_tokens_user_uuid_index ON public.auth_refresh_tokens USING btree (user_uuid);


--
-- Name: auth_sessions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auth_sessions_status_index ON public.auth_sessions USING btree (status);


--
-- Name: auth_sessions_user_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auth_sessions_user_uuid_index ON public.auth_sessions USING btree (user_uuid);


--
-- Name: blobs_created_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blobs_created_by_index ON public.blobs USING btree (created_by);


--
-- Name: blobs_visibility_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX blobs_visibility_index ON public.blobs USING btree (visibility);


--
-- Name: collection_definitions_tenant_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX collection_definitions_tenant_uuid_index ON public.collection_definitions USING btree (tenant_uuid);


--
-- Name: collection_schema_changes_collection_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX collection_schema_changes_collection_uuid_index ON public.collection_schema_changes USING btree (collection_uuid);


--
-- Name: entries_content_type_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX entries_content_type_uuid_index ON public.entries USING btree (content_type_uuid);


--
-- Name: entries_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX entries_status_index ON public.entries USING btree (status);


--
-- Name: entry_publications_version_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX entry_publications_version_uuid_index ON public.entry_publications USING btree (version_uuid);


--
-- Name: entry_references_target_entry_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX entry_references_target_entry_uuid_index ON public.entry_references USING btree (target_entry_uuid);


--
-- Name: entry_routes_entry_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX entry_routes_entry_uuid_index ON public.entry_routes USING btree (entry_uuid);


--
-- Name: entry_versions_entry_uuid_locale_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX entry_versions_entry_uuid_locale_index ON public.entry_versions USING btree (entry_uuid, locale);


--
-- Name: extension_operations_package_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX extension_operations_package_index ON public.extension_operations USING btree (package);


--
-- Name: extension_operations_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX extension_operations_status_index ON public.extension_operations USING btree (status);


--
-- Name: i18n_locales_enabled_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX i18n_locales_enabled_index ON public.i18n_locales USING btree (enabled);


--
-- Name: i18n_locales_is_default_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX i18n_locales_is_default_index ON public.i18n_locales USING btree (is_default);


--
-- Name: i18n_translations_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX i18n_translations_status_index ON public.i18n_translations USING btree (status);


--
-- Name: idx_api_metrics_daily_date; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_api_metrics_daily_date ON public.api_metrics_daily USING btree (date);


--
-- Name: idx_api_metrics_endpoint_method; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_api_metrics_endpoint_method ON public.api_metrics USING btree (endpoint, method);


--
-- Name: idx_api_metrics_timestamp; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_api_metrics_timestamp ON public.api_metrics USING btree ("timestamp");


--
-- Name: idx_audit_actor; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_actor ON public.audit_logs USING btree (actor_uuid, occurred_at);


--
-- Name: idx_audit_category; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_category ON public.audit_logs USING btree (category, occurred_at);


--
-- Name: idx_audit_target; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_audit_target ON public.audit_logs USING btree (target_type, target_uuid, occurred_at);


--
-- Name: idx_batch_pending; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_batch_pending ON public.queue_batches USING btree (pending_jobs, created_at);


--
-- Name: idx_block_type_migrations_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_block_type_migrations_status ON public.block_type_migrations USING btree (status);


--
-- Name: idx_block_type_migrations_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_block_type_migrations_type ON public.block_type_migrations USING btree (block_type_uuid);


--
-- Name: idx_collection_changes_tenant_collection; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_collection_changes_tenant_collection ON public.collection_schema_changes USING btree (tenant_uuid, collection_uuid);


--
-- Name: idx_commerce_checkout_attempt_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_commerce_checkout_attempt_created_at ON public.thallo_commerce_checkout_attempts USING btree (created_at);


--
-- Name: idx_commerce_link_delivery_tenant_order; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_commerce_link_delivery_tenant_order ON public.thallo_commerce_payment_link_deliveries USING btree (tenant_uuid, order_uuid);


--
-- Name: idx_commerce_product_link_tenant_product; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_commerce_product_link_tenant_product ON public.thallo_commerce_product_links USING btree (tenant_uuid, product_uuid);


--
-- Name: idx_commerce_product_slug_tenant_product; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_commerce_product_slug_tenant_product ON public.thallo_commerce_product_slugs USING btree (tenant_uuid, product_uuid);


--
-- Name: idx_events_tenant_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_events_tenant_created ON public.subscription_events USING btree (tenant_uuid, created_at);


--
-- Name: idx_failed_connection_queue; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_failed_connection_queue ON public.queue_failed_jobs USING btree (connection, queue);


--
-- Name: idx_form_submissions_form_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_form_submissions_form_key ON public.form_submissions USING btree (form_key);


--
-- Name: idx_form_submissions_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_form_submissions_status ON public.form_submissions USING btree (status);


--
-- Name: idx_form_submissions_submitted_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_form_submissions_submitted_at ON public.form_submissions USING btree (submitted_at);


--
-- Name: idx_i18n_missing_bundle; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_i18n_missing_bundle ON public.i18n_missing_translations USING btree (locale, domain);


--
-- Name: idx_i18n_translation_bundle; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_i18n_translation_bundle ON public.i18n_translations USING btree (locale, domain);


--
-- Name: idx_import_export_batch_job_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_import_export_batch_job_status ON public.import_export_batches USING btree (job_uuid, status, sequence);


--
-- Name: idx_navigation_items_tree; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_navigation_items_tree ON public.navigation_items USING btree (menu_uuid, parent_uuid, "position");


--
-- Name: idx_notifications_idempotency_lookup; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_notifications_idempotency_lookup ON public.notifications USING btree (notifiable_type, notifiable_id, type, idempotency_key, created_at);


--
-- Name: idx_overrides_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_overrides_tenant ON public.subscription_overrides USING btree (tenant_uuid);


--
-- Name: idx_priority_available; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_priority_available ON public.queue_jobs USING btree (priority, available_at);


--
-- Name: idx_pubref_type_field_locale_target; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pubref_type_field_locale_target ON public.published_entry_references USING btree (source_content_type_uuid, field, locale, target_entry_uuid);


--
-- Name: idx_queue_available; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_queue_available ON public.queue_jobs USING btree (queue, available_at);


--
-- Name: idx_queue_reserved; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_queue_reserved ON public.queue_jobs USING btree (queue, reserved_at);


--
-- Name: idx_redirect_target_entry; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_redirect_target_entry ON public.entry_redirects USING btree (target_entry_uuid);


--
-- Name: idx_render_template_versions_template; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_render_template_versions_template ON public.render_template_versions USING btree (template_uuid);


--
-- Name: idx_schedules_status_run_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schedules_status_run_at ON public.entry_schedules USING btree (status, run_at);


--
-- Name: idx_schema_migrations_type_from; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_schema_migrations_type_from ON public.entry_schema_migrations USING btree (content_type_uuid, from_version);


--
-- Name: idx_signup_intents_email; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_signup_intents_email ON public.signup_intents USING btree (email);


--
-- Name: idx_signup_intents_expires; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_signup_intents_expires ON public.signup_intents USING btree (expires_at);


--
-- Name: idx_signup_intents_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_signup_intents_status ON public.signup_intents USING btree (status);


--
-- Name: idx_subscription_plans_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subscription_plans_status ON public.subscription_plans USING btree (status);


--
-- Name: idx_subscription_plans_updated_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subscription_plans_updated_at ON public.subscription_plans USING btree (updated_at);


--
-- Name: idx_subscriptions_checkout_origination; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_subscriptions_checkout_origination ON public.subscriptions USING btree (checkout_origination_uuid);


--
-- Name: idx_tenant_api_key_bindings_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tenant_api_key_bindings_tenant ON public.thallo_tenant_api_key_bindings USING btree (tenant_uuid);


--
-- Name: idx_tenant_role_overrides_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tenant_role_overrides_tenant ON public.tenant_role_overrides USING btree (tenant_uuid);


--
-- Name: idx_tenant_roles_tenant; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_tenant_roles_tenant ON public.tenant_roles USING btree (tenant_uuid);


--
-- Name: idx_workflow_transitions_entry_locale; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_workflow_transitions_entry_locale ON public.workflow_transitions USING btree (entry_uuid, locale);


--
-- Name: import_export_batches_locked_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_export_batches_locked_at_index ON public.import_export_batches USING btree (locked_at);


--
-- Name: import_export_errors_batch_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_export_errors_batch_uuid_index ON public.import_export_errors USING btree (batch_uuid);


--
-- Name: import_export_errors_job_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_export_errors_job_uuid_index ON public.import_export_errors USING btree (job_uuid);


--
-- Name: import_export_errors_severity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_export_errors_severity_index ON public.import_export_errors USING btree (severity);


--
-- Name: import_export_files_job_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_export_files_job_uuid_index ON public.import_export_files USING btree (job_uuid);


--
-- Name: import_export_files_role_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_export_files_role_index ON public.import_export_files USING btree (role);


--
-- Name: import_export_jobs_adapter_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_export_jobs_adapter_index ON public.import_export_jobs USING btree (adapter);


--
-- Name: import_export_jobs_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_export_jobs_created_at_index ON public.import_export_jobs USING btree (created_at);


--
-- Name: import_export_jobs_created_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_export_jobs_created_by_index ON public.import_export_jobs USING btree (created_by);


--
-- Name: import_export_jobs_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_export_jobs_status_index ON public.import_export_jobs USING btree (status);


--
-- Name: import_export_jobs_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX import_export_jobs_type_index ON public.import_export_jobs USING btree (type);


--
-- Name: job_executions_job_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX job_executions_job_uuid_index ON public.job_executions USING btree (job_uuid);


--
-- Name: job_executions_started_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX job_executions_started_at_index ON public.job_executions USING btree (started_at);


--
-- Name: job_executions_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX job_executions_status_index ON public.job_executions USING btree (status);


--
-- Name: locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locks_expiration_index ON public.locks USING btree (expiration);


--
-- Name: locks_token_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX locks_token_index ON public.locks USING btree (token);


--
-- Name: media_assets_tenant_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_assets_tenant_uuid_index ON public.media_assets USING btree (tenant_uuid);


--
-- Name: media_meta_tenant_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_meta_tenant_uuid_index ON public.media_meta USING btree (tenant_uuid);


--
-- Name: media_usage_blob_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_usage_blob_uuid_index ON public.media_usage USING btree (blob_uuid);


--
-- Name: media_usage_entry_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_usage_entry_uuid_index ON public.media_usage USING btree (entry_uuid);


--
-- Name: media_usage_tenant_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_usage_tenant_uuid_index ON public.media_usage USING btree (tenant_uuid);


--
-- Name: notification_deliveries_channel_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_deliveries_channel_index ON public.notification_deliveries USING btree (channel);


--
-- Name: notification_deliveries_notification_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_deliveries_notification_uuid_index ON public.notification_deliveries USING btree (notification_uuid);


--
-- Name: notification_deliveries_sent_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_deliveries_sent_at_index ON public.notification_deliveries USING btree (sent_at);


--
-- Name: notification_deliveries_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_deliveries_status_index ON public.notification_deliveries USING btree (status);


--
-- Name: notification_preferences_notifiable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_preferences_notifiable_id_index ON public.notification_preferences USING btree (notifiable_id);


--
-- Name: notification_preferences_notifiable_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_preferences_notifiable_type_index ON public.notification_preferences USING btree (notifiable_type);


--
-- Name: notification_preferences_notification_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_preferences_notification_type_index ON public.notification_preferences USING btree (notification_type);


--
-- Name: notification_retry_queue_notifiable_type_notifiable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_retry_queue_notifiable_type_notifiable_id_index ON public.notification_retry_queue USING btree (notifiable_type, notifiable_id);


--
-- Name: notification_retry_queue_notification_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_retry_queue_notification_id_index ON public.notification_retry_queue USING btree (notification_id);


--
-- Name: notification_retry_queue_retry_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_retry_queue_retry_at_index ON public.notification_retry_queue USING btree (retry_at);


--
-- Name: notification_templates_channel_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_templates_channel_index ON public.notification_templates USING btree (channel);


--
-- Name: notification_templates_notification_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notification_templates_notification_type_index ON public.notification_templates USING btree (notification_type);


--
-- Name: notifications_notifiable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notifications_notifiable_id_index ON public.notifications USING btree (notifiable_id);


--
-- Name: notifications_notifiable_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notifications_notifiable_type_index ON public.notifications USING btree (notifiable_type);


--
-- Name: notifications_read_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notifications_read_at_index ON public.notifications USING btree (read_at);


--
-- Name: notifications_scheduled_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notifications_scheduled_at_index ON public.notifications USING btree (scheduled_at);


--
-- Name: notifications_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX notifications_type_index ON public.notifications USING btree (type);


--
-- Name: permission_audit_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permission_audit_created_at_index ON public.permission_audit USING btree (created_at);


--
-- Name: permission_audit_performed_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permission_audit_performed_by_index ON public.permission_audit USING btree (performed_by);


--
-- Name: permission_audit_permission_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permission_audit_permission_uuid_index ON public.permission_audit USING btree (permission_uuid);


--
-- Name: permission_audit_subject_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permission_audit_subject_type_index ON public.permission_audit USING btree (subject_type);


--
-- Name: permission_audit_subject_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permission_audit_subject_uuid_index ON public.permission_audit USING btree (subject_uuid);


--
-- Name: permission_audit_target_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permission_audit_target_uuid_index ON public.permission_audit USING btree (target_uuid);


--
-- Name: permissions_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permissions_category_index ON public.permissions USING btree (category);


--
-- Name: permissions_resource_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX permissions_resource_type_index ON public.permissions USING btree (resource_type);


--
-- Name: profiles_photo_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX profiles_photo_uuid_index ON public.profiles USING btree (photo_uuid);


--
-- Name: published_entry_references_target_entry_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX published_entry_references_target_entry_uuid_index ON public.published_entry_references USING btree (target_entry_uuid);


--
-- Name: queue_batches_cancelled_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_batches_cancelled_at_index ON public.queue_batches USING btree (cancelled_at);


--
-- Name: queue_batches_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_batches_created_at_index ON public.queue_batches USING btree (created_at);


--
-- Name: queue_batches_finished_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_batches_finished_at_index ON public.queue_batches USING btree (finished_at);


--
-- Name: queue_batches_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_batches_name_index ON public.queue_batches USING btree (name);


--
-- Name: queue_failed_jobs_batch_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_failed_jobs_batch_uuid_index ON public.queue_failed_jobs USING btree (batch_uuid);


--
-- Name: queue_failed_jobs_connection_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_failed_jobs_connection_index ON public.queue_failed_jobs USING btree (connection);


--
-- Name: queue_failed_jobs_failed_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_failed_jobs_failed_at_index ON public.queue_failed_jobs USING btree (failed_at);


--
-- Name: queue_failed_jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_failed_jobs_queue_index ON public.queue_failed_jobs USING btree (queue);


--
-- Name: queue_jobs_available_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_jobs_available_at_index ON public.queue_jobs USING btree (available_at);


--
-- Name: queue_jobs_batch_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_jobs_batch_uuid_index ON public.queue_jobs USING btree (batch_uuid);


--
-- Name: queue_jobs_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_jobs_priority_index ON public.queue_jobs USING btree (priority);


--
-- Name: queue_jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_jobs_queue_index ON public.queue_jobs USING btree (queue);


--
-- Name: queue_jobs_reserved_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX queue_jobs_reserved_at_index ON public.queue_jobs USING btree (reserved_at);


--
-- Name: released_hosts_released_by_tenant_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX released_hosts_released_by_tenant_index ON public.released_hosts USING btree (released_by_tenant);


--
-- Name: released_hosts_retained_until_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX released_hosts_retained_until_index ON public.released_hosts USING btree (retained_until);


--
-- Name: role_permissions_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX role_permissions_expires_at_index ON public.role_permissions USING btree (expires_at);


--
-- Name: role_permissions_granted_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX role_permissions_granted_by_index ON public.role_permissions USING btree (granted_by);


--
-- Name: role_permissions_permission_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX role_permissions_permission_uuid_index ON public.role_permissions USING btree (permission_uuid);


--
-- Name: role_permissions_role_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX role_permissions_role_uuid_index ON public.role_permissions USING btree (role_uuid);


--
-- Name: roles_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX roles_level_index ON public.roles USING btree (level);


--
-- Name: roles_parent_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX roles_parent_uuid_index ON public.roles USING btree (parent_uuid);


--
-- Name: roles_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX roles_status_index ON public.roles USING btree (status);


--
-- Name: scheduled_jobs_is_enabled_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX scheduled_jobs_is_enabled_index ON public.scheduled_jobs USING btree (is_enabled);


--
-- Name: scheduled_jobs_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX scheduled_jobs_name_index ON public.scheduled_jobs USING btree (name);


--
-- Name: scheduled_jobs_next_run_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX scheduled_jobs_next_run_index ON public.scheduled_jobs USING btree (next_run);


--
-- Name: starter_provenance_tenant_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX starter_provenance_tenant_uuid_index ON public.starter_provenance USING btree (tenant_uuid);


--
-- Name: tenant_domains_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tenant_domains_status_index ON public.tenant_domains USING btree (status);


--
-- Name: tenant_domains_tenant_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tenant_domains_tenant_uuid_index ON public.tenant_domains USING btree (tenant_uuid);


--
-- Name: tenant_domains_verification_status_last_checked_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tenant_domains_verification_status_last_checked_at_index ON public.tenant_domains USING btree (verification_status, last_checked_at);


--
-- Name: tenant_memberships_tenant_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tenant_memberships_tenant_uuid_index ON public.tenant_memberships USING btree (tenant_uuid);


--
-- Name: tenant_memberships_user_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tenant_memberships_user_uuid_index ON public.tenant_memberships USING btree (user_uuid);


--
-- Name: tenants_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tenants_status_index ON public.tenants USING btree (status);


--
-- Name: thallo_tenant_purge_runs_lease_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX thallo_tenant_purge_runs_lease_expires_at_index ON public.thallo_tenant_purge_runs USING btree (lease_expires_at);


--
-- Name: thallo_tenant_purge_runs_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX thallo_tenant_purge_runs_status_index ON public.thallo_tenant_purge_runs USING btree (status);


--
-- Name: thallo_tenant_purge_runs_tenant_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX thallo_tenant_purge_runs_tenant_uuid_index ON public.thallo_tenant_purge_runs USING btree (tenant_uuid);


--
-- Name: uniq_active_tenant_purge_run; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_active_tenant_purge_run ON public.thallo_tenant_purge_runs USING btree (tenant_uuid) WHERE ((status)::text <> 'completed'::text);


--
-- Name: uniq_entry_schema_migrations_active; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_entry_schema_migrations_active ON public.entry_schema_migrations USING btree (content_type_uuid) WHERE ((status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying])::text[]));


--
-- Name: uniq_override_subject_entitlement; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_override_subject_entitlement ON public.subscription_overrides USING btree (tenant_uuid, subject_type, subject_uuid, entitlement);


--
-- Name: uniq_pending_schedule; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_pending_schedule ON public.entry_schedules USING btree (entry_uuid, locale, action) WHERE ((status)::text = 'pending'::text);


--
-- Name: uniq_plans_scope_key; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_plans_scope_key ON public.subscription_plans USING btree (audience, owner_tenant_uuid, plan_key);


--
-- Name: uniq_subscriptions_subject; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX uniq_subscriptions_subject ON public.subscriptions USING btree (tenant_uuid, subject_type, subject_uuid);


--
-- Name: user_permissions_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_permissions_expires_at_index ON public.user_permissions USING btree (expires_at);


--
-- Name: user_permissions_granted_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_permissions_granted_by_index ON public.user_permissions USING btree (granted_by);


--
-- Name: user_permissions_permission_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_permissions_permission_uuid_index ON public.user_permissions USING btree (permission_uuid);


--
-- Name: user_permissions_user_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_permissions_user_uuid_index ON public.user_permissions USING btree (user_uuid);


--
-- Name: user_roles_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_roles_expires_at_index ON public.user_roles USING btree (expires_at);


--
-- Name: user_roles_granted_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_roles_granted_by_index ON public.user_roles USING btree (granted_by);


--
-- Name: user_roles_role_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_roles_role_uuid_index ON public.user_roles USING btree (role_uuid);


--
-- Name: user_roles_user_uuid_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_roles_user_uuid_index ON public.user_roles USING btree (user_uuid);


--
-- Name: webhook_deliveries_next_retry_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX webhook_deliveries_next_retry_at_index ON public.webhook_deliveries USING btree (next_retry_at);


--
-- Name: webhook_deliveries_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX webhook_deliveries_status_index ON public.webhook_deliveries USING btree (status);


--
-- Name: webhook_deliveries_subscription_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX webhook_deliveries_subscription_id_index ON public.webhook_deliveries USING btree (subscription_id);


--
-- Name: webhook_subscriptions_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX webhook_subscriptions_is_active_index ON public.webhook_subscriptions USING btree (is_active);


--
-- Name: workflow_review_states_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX workflow_review_states_state_index ON public.workflow_review_states USING btree (state);


--
-- Name: auth_refresh_tokens fk_auth_refresh_tokens_session_uuid_auth_sessions; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_refresh_tokens
    ADD CONSTRAINT fk_auth_refresh_tokens_session_uuid_auth_sessions FOREIGN KEY (session_uuid) REFERENCES public.auth_sessions(uuid) ON DELETE RESTRICT;


--
-- Name: import_export_batches fk_import_export_batches_job_uuid_import_export_jobs; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_batches
    ADD CONSTRAINT fk_import_export_batches_job_uuid_import_export_jobs FOREIGN KEY (job_uuid) REFERENCES public.import_export_jobs(uuid) ON DELETE CASCADE;


--
-- Name: import_export_errors fk_import_export_errors_batch_uuid_import_export_batches; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_errors
    ADD CONSTRAINT fk_import_export_errors_batch_uuid_import_export_batches FOREIGN KEY (batch_uuid) REFERENCES public.import_export_batches(uuid) ON DELETE CASCADE;


--
-- Name: import_export_errors fk_import_export_errors_job_uuid_import_export_jobs; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_errors
    ADD CONSTRAINT fk_import_export_errors_job_uuid_import_export_jobs FOREIGN KEY (job_uuid) REFERENCES public.import_export_jobs(uuid) ON DELETE CASCADE;


--
-- Name: import_export_files fk_import_export_files_job_uuid_import_export_jobs; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_files
    ADD CONSTRAINT fk_import_export_files_job_uuid_import_export_jobs FOREIGN KEY (job_uuid) REFERENCES public.import_export_jobs(uuid) ON DELETE CASCADE;


--
-- Name: import_export_reports fk_import_export_reports_job_uuid_import_export_jobs; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_export_reports
    ADD CONSTRAINT fk_import_export_reports_job_uuid_import_export_jobs FOREIGN KEY (job_uuid) REFERENCES public.import_export_jobs(uuid) ON DELETE CASCADE;


--
-- Name: job_executions fk_job_executions_job_uuid_scheduled_jobs; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_executions
    ADD CONSTRAINT fk_job_executions_job_uuid_scheduled_jobs FOREIGN KEY (job_uuid) REFERENCES public.scheduled_jobs(uuid) ON DELETE CASCADE;


--
-- Name: media_assets fk_media_assets_blob_uuid_blobs; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_assets
    ADD CONSTRAINT fk_media_assets_blob_uuid_blobs FOREIGN KEY (blob_uuid) REFERENCES public.blobs(uuid) ON DELETE CASCADE;


--
-- Name: profiles fk_profiles_user_uuid_users; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.profiles
    ADD CONSTRAINT fk_profiles_user_uuid_users FOREIGN KEY (user_uuid) REFERENCES public.users(uuid) ON DELETE RESTRICT;


--
-- Name: role_permissions fk_role_permissions_permission_uuid_permissions; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT fk_role_permissions_permission_uuid_permissions FOREIGN KEY (permission_uuid) REFERENCES public.permissions(uuid) ON DELETE CASCADE;


--
-- Name: role_permissions fk_role_permissions_role_uuid_roles; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT fk_role_permissions_role_uuid_roles FOREIGN KEY (role_uuid) REFERENCES public.roles(uuid) ON DELETE CASCADE;


--
-- Name: roles fk_roles_parent_uuid_roles; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT fk_roles_parent_uuid_roles FOREIGN KEY (parent_uuid) REFERENCES public.roles(uuid) ON DELETE SET NULL;


--
-- Name: tenant_domains fk_tenant_domains_tenant_uuid_tenants; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_domains
    ADD CONSTRAINT fk_tenant_domains_tenant_uuid_tenants FOREIGN KEY (tenant_uuid) REFERENCES public.tenants(uuid) ON DELETE CASCADE;


--
-- Name: tenant_memberships fk_tenant_memberships_tenant_uuid_tenants; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tenant_memberships
    ADD CONSTRAINT fk_tenant_memberships_tenant_uuid_tenants FOREIGN KEY (tenant_uuid) REFERENCES public.tenants(uuid) ON DELETE CASCADE;


--
-- Name: user_permissions fk_user_permissions_permission_uuid_permissions; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_permissions
    ADD CONSTRAINT fk_user_permissions_permission_uuid_permissions FOREIGN KEY (permission_uuid) REFERENCES public.permissions(uuid) ON DELETE CASCADE;


--
-- Name: user_roles fk_user_roles_role_uuid_roles; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_roles
    ADD CONSTRAINT fk_user_roles_role_uuid_roles FOREIGN KEY (role_uuid) REFERENCES public.roles(uuid) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict 4Y5kGvQiBxs1hghe0v9Czap89ghCICAAXgFC5GI1eLQo7NB8dqqtOPhPDBD6on9

