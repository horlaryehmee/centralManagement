<?php

/*
 * One-click updates of this central app straight from its GitHub repository
 * (Updates in the menu). No release zips: "Update now" fetches the latest commit
 * of the branch with Git, backs up the database, updates the files, runs Composer
 * when the dependencies changed, then the migrations and cache clearing.
 *
 * Compiled assets (public/build) are committed in the repository, so the server
 * needs no Node.js. .env and storage/ are never touched (they aren't in Git).
 *
 * The repository, branch and token can also be set on the Updates page; values
 * saved there take priority over these.
 */
return [
    // "owner/repository" on GitHub.
    'repository' => env('CENTRAL_UPDATE_REPO'),

    // Branch to follow.
    'branch' => env('CENTRAL_UPDATE_BRANCH', 'main'),

    // Personal access token, needed for a private repository: a fine-grained token with
    // read-only "Contents" access is enough. Sent with each Git request only (never written
    // to .git/config).
    'token' => env('CENTRAL_UPDATE_TOKEN'),

    // Full remote URL instead of https://github.com/<repository>.git (self-hosted Git, tests).
    'remote' => env('CENTRAL_UPDATE_REMOTE'),

    // Programs. Left empty, they are found automatically (cPanel paths included). Composer is
    // downloaded into storage/ (checksum verified) if the server has none.
    'git_binary' => env('CENTRAL_GIT_BINARY'),
    'php_binary' => env('CENTRAL_PHP_BINARY'),
    'composer_binary' => env('CENTRAL_COMPOSER_BINARY'),

    // How long a "check for updates" result is cached (seconds).
    'check_ttl' => 3600,

    // Previous versions (commit + database backup) kept for one-click rollback (never fewer than 2).
    'keep_backups' => 5,
];
