<?php

return [
    // Days an installation's one-time enrollment code stays valid.
    'enrollment_days' => (int) env('CENTRAL_ENROLLMENT_DAYS', 7),

    // Installations not heard from for this long count as offline.
    'offline_after_minutes' => (int) env('CENTRAL_OFFLINE_AFTER', 60),

    // Default minutes between an installation's heartbeats (it may check sooner when asked to).
    'check_interval' => (int) env('CENTRAL_CHECK_INTERVAL', 15),

    // Signed requests older or newer than this (seconds) are rejected (replay protection).
    'request_tolerance' => 300,

    // API is HTTPS-only. Plain HTTP is accepted only when this is true (local testing).
    'allow_insecure_api' => (bool) env('CENTRAL_ALLOW_INSECURE_API', false),

    // A handed-over deployment with no report for this many minutes is marked "no response".
    'no_response_after_minutes' => (int) env('CENTRAL_NO_RESPONSE_AFTER', 180),

    // Minutes to wait before automatic retry number 1, 2, 3… of a retryable failure.
    'retry_backoff' => [5, 20, 60],

    // Where update packages and the signing key live (never inside public/).
    'packages_disk' => 'local',
    'packages_dir' => 'packages',
    'signing_key_path' => env('CENTRAL_SIGNING_KEY_PATH', storage_path('app/private/signing/ed25519.key')),

    // Largest accepted upload, in megabytes (PHP's upload_max_filesize must allow it too).
    'max_package_mb' => (int) env('CENTRAL_MAX_PACKAGE_MB', 300),

    // Website backups (requested from an installation's page; stored encrypted, never inside public/).
    'backups_disk' => 'local',
    // Largest backup accepted, in megabytes.
    'backup_max_mb' => (int) env('CENTRAL_BACKUP_MAX_MB', 4096),
    // Upload chunk size in megabytes (lowered automatically to fit PHP's post_max_size).
    'backup_chunk_mb' => (int) env('CENTRAL_BACKUP_CHUNK_MB', 8),
    // Completed backups kept per website (older ones are deleted automatically).
    'backups_keep' => (int) env('CENTRAL_BACKUPS_KEEP', 10),
    // A request the website hasn't picked up after this many hours is marked "no response".
    'backup_pickup_hours' => 24,
    // An upload with no progress for this many hours is marked failed.
    'backup_stall_hours' => 6,
];
