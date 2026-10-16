<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

return [

    'backup' => [

        'name' => env('APP_NAME', 'UVMASTER CI'),

        'source' => [

            'files' => [

                'include' => [
                    base_path(),
                ],

                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    base_path('.git'),
                    storage_path('logs'),
                    storage_path('app/laravel-excel'),
                ],

                'follow_links' => false,

                'ignore_unreadable_directories' => false,

                'relative_path' => base_path(),
            ],

            // Connexion réellement utilisée par l'application (mysql en production, sqlite en développement).
            // Elle était figée sur « sqlite » : une base MySQL n'était donc pas sauvegardée par backup:run.
            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],

        'database_dump_compressor' => null,

        'database_dump_filename' => 'database',

        'database_dump_file_extension' => '',

        'destination' => [

            'compression_method' => ZipArchive::CM_DEFAULT,

            'compression_level' => 9,

            'filename_prefix' => 'backup-',

            'disks' => [
                'local',
            ],
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        'encryption' => 'default',

        'tries' => 1,

        'retry_delay' => 0,
    ],

    'notifications' => [

        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => ['mail'],
            HealthyBackupWasFoundNotification::class => ['mail'],
            CleanupWasSuccessfulNotification::class => ['mail'],
        ],

        'notifiable' => Notifiable::class,

        'mail' => [
            'to' => env('BACKUP_NOTIFICATION_EMAIL', 'admin@uvmaster.ci'),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'backup@uvmaster.ci'),
                'name' => env('MAIL_FROM_NAME', 'UVMASTER Backup'),
            ],
        ],

        'slack' => [
            'webhook_url' => env('BACKUP_SLACK_WEBHOOK_URL', ''),
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => env('BACKUP_DISCORD_WEBHOOK_URL', ''),
            'username' => 'UVMASTER Backup',
            'avatar_url' => '',
        ],
    ],

    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'UVMASTER CI'),
            'disks' => ['local'],
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 5000,
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 16,
            'keep_weekly_backups_for_weeks' => 8,
            'keep_monthly_backups_for_months' => 4,
            'keep_yearly_backups_for_years' => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],
    ],
];
