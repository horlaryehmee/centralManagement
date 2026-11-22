<?php

/*
 * One-click updates of this central app (Updates in the menu).
 *
 * Releases are published on GitHub by .github/workflows/release.yml whenever a
 * version tag (e.g. v1.2.0) is pushed. Each release carries a ready-to-run zip
 * (Composer packages and compiled assets included), so the server needs no
 * Composer, Node.js or terminal: the update downloads it, backs up the database,
 * swaps the files in (rolling back on any error) and runs the migrations.
 *
 * The repository and token can also be set on the Updates page; values saved
 * there take priority over these.
 */
return [
    // "owner/repository" on GitHub.
    'repository' => env('CENTRAL_UPDATE_REPO'),

    // Personal access token: needed for a private repository, recommended for a public one
    // (shared hosting IPs quickly hit GitHub's anonymous rate limit). A fine-grained token with
    // read-only "Contents" access to the repository is enough.
    'token' => env('CENTRAL_UPDATE_TOKEN'),

    // Name of the zip asset attached to each release ({tag} = e.g. v1.2.0).
    'asset' => 'central-{tag}.zip',

    // How long a "check for updates" result is cached (seconds).
    'check_ttl' => 6 * 3600,

    // Previous versions (files + database backup) kept for one-click rollback (never fewer than 2).
    'keep_backups' => 3,

    // Top-level entries of a release that are never copied over the live app:
    // .env and storage/ hold settings, the signing key, packages, backups and logs.
    'preserve' => ['.env', 'storage', 'public', '.git', '.github', 'node_modules', '.htaccess', '.user.ini', 'php.ini', 'error_log'],

    // Files in public/ that are never replaced (custom index.php for public_html setups,
    // cPanel's PHP-version .htaccess lines, the storage link).
    'public_preserve' => ['index.php', '.htaccess', '.user.ini', 'php.ini', 'storage', 'error_log'],
];
