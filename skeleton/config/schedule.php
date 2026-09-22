<?php

/**
 * Scheduler Configuration
 *
 * Each job runs inline, in the process that runs `php glueful queue:scheduler run`, when its cron
 * `schedule` is due. A job takes `name`, `schedule`, `handler_class`, optional `parameters`
 * (handed to the handler as its data), optional `enabled`, optional `persistence` and a
 * `description`. Nothing else is read: there is no per-job queue, timeout or retry count here.
 */

return [
    'jobs' => [
        [
            'name' => 'session_cleaner',
            'schedule' => '0 0 * * *',
            'handler_class' => 'Glueful\\Queue\\Jobs\\SessionCleanupJob',
            'parameters' => ['cleanupType' => 'expired'],
            'description' => 'Clean up expired user sessions',
            'enabled' => env('SESSION_CLEANER_ENABLED', true),
        ],
        [
            'name' => 'log_cleanup',
            'schedule' => '0 1 * * *',
            'handler_class' => 'Glueful\\Queue\\Jobs\\LogCleanupJob',
            // LogCleanupJob reads options.retention_days; any other key is ignored and it keeps 30.
            'parameters' => [
                'options' => [
                    'retention_days' => env('LOG_RETENTION_DAYS', 30),
                ],
            ],
            'description' => 'Clean up old log files',
            'enabled' => env('LOG_CLEANUP_ENABLED', true),
        ],
        [
            'name' => 'database_backup',
            'schedule' => env('DB_BACKUP_SCHEDULE', '0 2 * * *'),
            'handler_class' => 'Glueful\\Queue\\Jobs\\DatabaseBackupJob',
            'parameters' => [
                'backupType' => 'full',
                'options' => [
                    'retention_days' => env('BACKUP_RETENTION_DAYS', 7)
                ]
            ],
            // Off by default: it needs pg_dump on the scheduler host and writes to storage/backups on
            // the same machine. Turn it on deliberately (docs/operations/04-backups.md).
            'enabled' => env('DB_BACKUP_ENABLED', false),
            'description' => 'Create automated database backups',
        ],
        [
            'name' => 'cache_maintenance',
            'schedule' => '0 3 * * *',
            'handler_class' => 'Glueful\\Queue\\Jobs\\CacheMaintenanceJob',
            'parameters' => [
                'operation' => 'fullCleanup'
            ],
            'description' => 'Perform cache maintenance',
            'enabled' => env('CACHE_MAINTENANCE_ENABLED', true),
        ],
        [
            'name' => 'webhook_cleanup',
            'schedule' => '30 3 * * *',
            'handler_class' => 'Glueful\\Api\\Webhooks\\Jobs\\WebhookCleanupJob',
            'description' => 'Delete webhook delivery records past api.webhooks.cleanup retention',
            'enabled' => env('WEBHOOK_CLEANUP_ENABLED', true),
        ],
        [
            'name' => 'notification_retry_processor',
            'schedule' => '*/10 * * * *',
            'handler_class' => 'Glueful\\Queue\\Jobs\\NotificationRetryJob',
            'parameters' => ['options' => ['limit' => 50]],
            'description' => 'Process queued notification retries',
            'enabled' => env('NOTIFICATION_RETRIES_ENABLED', true),
        ],
        [
            'name' => 'schedules_run',
            'schedule' => '* * * * *',
            'handler_class' => \Thallo\Core\Content\Jobs\RunDueSchedulesJob::class,
            'parameters' => [],
            'description' => 'Fire due scheduled publish/unpublish actions',
        ],
        [
            'name' => 'domain_reverification_sweep',
            'schedule' => '0 * * * *',
            'handler_class' => \Thallo\Tenancy\Reverification\DomainReverificationSweepJob::class,
            'parameters' => [],
            'description' => 'Re-verify due custom-domain ownership proofs',
            'enabled' => env('TENANCY_REVERIFICATION_ENABLED', true),
        ],
        [
            'name' => 'update_check',
            'schedule' => '0 4 * * *',
            'handler_class' => \Thallo\Core\Updates\UpdateCheckJob::class,
            'parameters' => [],
            'description' => 'Ask Packagist whether a newer glueful/thallo-core is published (the update notice)',
            'enabled' => env('UPDATE_CHECK_ENABLED', true),
        ],
        // Maintenance commands, run through RunConsoleCommandJob by class (a string for classes
        // from optional extensions). Each can be turned off in .env; one whose extension is not
        // installed is skipped.
        [
            'name' => 'version_prune',
            'schedule' => '30 4 * * *',
            'handler_class' => \Thallo\Core\Jobs\RunConsoleCommandJob::class,
            'parameters' => ['command' => \Thallo\Core\Content\Console\PruneVersionsCommand::class],
            'description' => 'Prune entry version history by VERSION_KEEP / VERSION_MAX_AGE_DAYS (no-op when unset)',
            'enabled' => env('VERSION_PRUNE_ENABLED', true),
        ],
        [
            'name' => 'import_export_cleanup',
            'schedule' => '45 3 * * *',
            'handler_class' => \Thallo\Core\Jobs\RunConsoleCommandJob::class,
            'parameters' => ['command' => 'Glueful\\Extensions\\ImportExport\\Console\\ImportExportCleanupCommand'],
            'description' => 'Delete finished import/export files past import_export.retention_days',
            'enabled' => env('IMPORT_EXPORT_CLEANUP_ENABLED', true),
        ],
        [
            'name' => 'analytics_prune',
            'schedule' => '15 4 * * *',
            'handler_class' => \Thallo\Core\Jobs\RunConsoleCommandJob::class,
            'parameters' => ['command' => 'Thallo\\Analytics\\Console\\PruneAnalyticsCommand'],
            'description' => 'Delete raw analytics facts past analytics.retention_days (daily totals are kept)',
            'enabled' => env('ANALYTICS_PRUNE_ENABLED', true),
        ],
        [
            'name' => 'form_submissions_prune',
            'schedule' => '50 3 * * *',
            'handler_class' => \Thallo\Core\Jobs\RunConsoleCommandJob::class,
            'parameters' => ['command' => \Thallo\Core\Content\Console\PruneFormSubmissionsCommand::class],
            'description' => 'Delete form submissions past FORMS_RETENTION_DAYS (no-op when unset)',
            'enabled' => env('FORMS_PRUNE_ENABLED', true),
        ],
        [
            'name' => 'commerce_carts_prune',
            'schedule' => '20 * * * *',
            'handler_class' => \Thallo\Core\Jobs\RunConsoleCommandJob::class,
            'parameters' => ['command' => 'Glueful\\Extensions\\Commerce\\Console\\CartsPruneCommand'],
            'description' => 'Mark expired shopping carts as abandoned',
            'enabled' => env('COMMERCE_CARTS_PRUNE_ENABLED', true),
        ],
        [
            'name' => 'commerce_marketplace_payouts_reconcile',
            'schedule' => '*/15 * * * *',
            'handler_class' => \Thallo\Core\Jobs\RunConsoleCommandJob::class,
            'parameters' => ['command' => 'Glueful\\Extensions\\Commerce\\Console\\PayoutsReconcileSweepCommand'],
            'description' => 'Reconcile due marketplace payouts with the provider',
            'enabled' => env('COMMERCE_MARKETPLACE_ENABLED', false),
        ],
        [
            'name' => 'commerce_marketplace_payouts_retry',
            'schedule' => '*/15 * * * *',
            'handler_class' => \Thallo\Core\Jobs\RunConsoleCommandJob::class,
            'parameters' => ['command' => 'Glueful\\Extensions\\Commerce\\Console\\PayoutsRetrySweepCommand'],
            'description' => 'Retry due failed marketplace payouts',
            'enabled' => env('COMMERCE_MARKETPLACE_ENABLED', false),
        ],
        [
            'name' => 'commerce_marketplace_reserves_release',
            'schedule' => '0 * * * *',
            'handler_class' => \Thallo\Core\Jobs\RunConsoleCommandJob::class,
            'parameters' => ['command' => 'Glueful\\Extensions\\Commerce\\Console\\ReservesReleaseSweepCommand'],
            'description' => 'Release seller reserve holds that have come due',
            'enabled' => env('COMMERCE_MARKETPLACE_ENABLED', false),
        ],
        [
            'name' => 'commerce_marketplace_webhooks',
            'schedule' => '*/5 * * * *',
            'handler_class' => \Thallo\Core\Jobs\RunConsoleCommandJob::class,
            'parameters' => ['command' => 'Glueful\\Extensions\\Commerce\\Console\\SweepSellerWebhooksCommand'],
            'description' => 'Re-send due seller webhook deliveries',
            'enabled' => env('COMMERCE_MARKETPLACE_ENABLED', false),
        ],
        [
            'name' => 'signup_intent_sweep',
            'schedule' => '15 2 * * *',
            'handler_class' => \Thallo\Core\Signup\SignupIntentSweepJob::class,
            'parameters' => [],
            'description' => 'Remove expired and sanitized public-signup intents',
            'enabled' => env('SIGNUP_SWEEP_ENABLED', true),
        ],
    ],
];
