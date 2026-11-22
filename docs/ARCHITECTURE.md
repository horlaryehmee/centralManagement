# Architecture

## The pieces

```
Custom Laravel Websites/
├── Master Ecommerce File/          the ecommerce application (one copy per client website)
│   ├── app/Services/Updater/       install engine: staging, file swap with automatic rollback, DB backup
│   ├── app/Services/Agent/         update agent: talks to this central app, runs deployments
│   ├── app/Support/Modules/        module/extension discovery and loading
│   ├── modules/<slug>/             optional shared modules (own versions and releases)
│   └── extensions/<slug>/          code written for this one client
└── central-management/             this app: separate Laravel 12 + Inertia/React project, own database
```

Both apps use the same stack (Laravel 12, Inertia 2, React 19, Tailwind 4, MySQL) and the same admin UI style.

| Central app | Ecommerce installation |
|---|---|
| Clients, installations, releases, deployments, event log | Its own shop, database, uploads, admin |
| Signs release manifests (Ed25519) | Verifies signatures with the key pinned at connection |
| Never connects to sites; only answers their HTTPS calls | All traffic is outgoing HTTPS (cron + admin) |
| Sees shop data only in backups you request (stored encrypted) | Sends versions, modules, PHP and basic server facts; sends a backup only when asked (the owner can switch this off) |

The storefront never calls central. If central is offline, sites keep selling; only check-ins fail (logged, retried).

## Three layers

| Layer | Lives in | Updated by | A core update… |
|---|---|---|---|
| **Core** | the app itself | core releases | replaces it |
| **Modules** | `modules/<slug>/` | module releases | never touches it |
| **Client extensions** | `extensions/<slug>/` | extension releases bound to **one client** | never touches it |

A module/extension is a folder with `module.json` (slug, name, version, type, namespace, provider, requires). The `ModuleServiceProvider` autoloads `src/`, registers its service provider, migrations, `routes/web.php` and views. A module whose requirements aren't met, or that throws while loading, is skipped and logged, so it can never take the shop down.

Client data is never part of any layer: `.env`, `storage/` (uploads, logs, sessions), `public/index.php`, `public/.htaccess` and the uploads link are never replaced.

## Compatibility

Checked by central **before** a deployment (the confirmation screen lists skipped sites and why), again when a site **picks it up**, and once more by the **agent itself** against its real state:

- Core: must be newer than installed; optional "upgrade from" min/max; every installed module's `requires.core` must accept the new version.
- Module/extension: `requires.core`, `requires.php`, `requires.modules` (version constraints like `^1.2`, `>=1.2 <2.0`, `1.2.*`, `||`); must be newer if installed; can't change type.
- Extension: the release's client must be the installation's client. Central won't target others; the agent refuses a manifest whose `client` key isn't its own.

The same `VersionConstraint` class (Composer semantics) is used on both sides.

## Hand-edited core files (customisation protection)

Before a core update, the agent compares its core files with the **signed file list of the version it runs** (kept locally after each update, or fetched from central). If any core file was changed by hand, the update is refused and the changed files are listed (deployment status: failed, `modified_files`, not retried). Nothing is overwritten. Options: move the change into an extension (see CUSTOMIZATIONS.md), or deploy again with "Overwrite locally modified core files" turned on.

## Deployment lifecycle

```
central:  waiting_canary → queued ──(site checks in)──► delivered → running → succeeded | failed
                                                                  ↘ retry_wait → queued   (retryable failures, backoff 5/20/60 min)
          succeeded → rollback_requested → rolling_back → rolled_back | rollback_failed
          silent for 3 h after hand-over → no_response    (never assumed successful)
```

On the site (`agent:sync`, every minute from cron, contacting central every N minutes):

1. Heartbeat (version, modules, PHP) → the answer carries at most one instruction.
2. **Accept** → signed manifest; verify signature, release, client, compatibility, hand-edited files.
3. If an order was placed in the last 3 minutes, **wait** (max 30 min) so checkouts aren't interrupted.
4. **Download** (resumable via HTTP Range), verify the package SHA-256 from the signed manifest.
5. **Back up** the database (plain-PHP gzipped SQL; works where `mysqldump`/`exec` are blocked).
6. **Unpack** into staging; every file must match the manifest (nothing changed, missing or extra).
7. **Install**: maintenance mode, rename-swap of top-level folders (seconds). Any error moves the originals straight back.
8. **Finish in a fresh PHP process** (the new code): migrations (batch recorded), cache clear, back online. If `proc_open` is disabled, the next cron run finishes it. If finishing fails, the previous files are restored automatically.
9. **Report** the actual result. If central can't be reached, the result waits in an outbox and is sent on the next run.

Rollback: the agent undoes that update's migration batch (only if the release says its migrations are reversible), then restores the files from its own backup. Last 3 versions are kept per site.

## Security

| Concern | Measure |
|---|---|
| Transport | HTTPS only (`RequireHttps` on central; agent refuses `http://` unless `AGENT_ALLOW_INSECURE=true` for local testing) |
| Identity | Per-installation UUID + 64-char secret, issued once at registration with a single-use, expiring, SHA-256-stored connection code |
| Request auth | `X-Signature = HMAC-SHA256(secret, METHOD\npath\ntimestamp\nnonce\nsha256(body))` |
| Replay | ±5 min timestamp window + nonce stored once per installation |
| Secrets at rest | Encrypted with each app's `APP_KEY` (both sides) |
| Revocation | Suspend (no updates, still monitored) or revoke (credentials stop working) per installation; suspend a client to pause all its sites |
| Packages | Ed25519-signed manifest containing package SHA-256 + per-file SHA-256; secret key stored outside `public/`, never sent |
| Isolation | Every API lookup is scoped to the calling installation; other installations' targets return 404 |
| No remote commands | Instructions are data only (`deploy` / `rollback` / `backup` + target ID). The agent runs only its own fixed steps |
| Rate limits | Registration 10/min per IP; signed calls 120/min per installation; admin login 5/min |
| Central never touches sites | No credentials to client hosting or databases exist anywhere in central |

## Backups

Requested from an installation's page (**Create backup**, with or without uploaded files). Pull-based, like updates:

1. The request waits for the website's next check-in. Only agents that report the `backup` capability get it, and an update already handed over finishes first.
2. The website builds one zip: `database.sql.gz` (plain-PHP dump, importable with phpMyAdmin), `files/public/*` (uploads) and `files/receipts/*` when files are included, and `backup.json`. Never `.env`, code or logs.
3. It uploads the zip in signed chunks (`X-Offset`; the size fits central's `post_max_size`). Each cron run sends about 45 seconds' worth and the next run continues. Chunks are idempotent; a gap is answered with the offset to continue from.
4. Central checks the size and SHA-256, then encrypts the file at rest (libsodium secretstream, key derived from `APP_KEY`) under `storage/app/private/backups/<installation>/`. **Download** decrypts on the fly; a changed file fails instead of producing a damaged zip.
5. The newest `CENTRAL_BACKUPS_KEEP` (10) per website are kept. Requests not picked up within 24 hours are marked "No response"; uploads idle for 6 hours fail.

Backups contain customers and orders. Only signed-in central admins can download them, every request and download is in the event log, and the website writes each backup to its own update log. Site owners can refuse backups with `AGENT_ALLOW_BACKUPS=false`. **Losing central's `APP_KEY` makes stored backups unreadable**, so keep a copy of it. Restoring is manual: import the SQL, copy the files back.

## Known limits and risks

- **Maintenance window**: each install shows the "back soon" page for seconds to a minute (finishing waits for the next minute when `proc_open` is disabled).
- **Database rollback** works only when the release's migrations have working `down()` methods (flag per release). A database backup is always taken and downloadable from the site's admin.
- **Modules can't add React pages** without their own build; backend code, routes, migrations and Blade views work. Ship prebuilt assets in the module if needed.
- **Trust on first use**: the signing key is received at connection (over HTTPS). Compare the fingerprint shown on the site with the central dashboard.
- **Losing the signing key** means every site must reconnect. Back up `storage/app/private/signing/ed25519.key`.
- The **first agent version** must be installed on existing sites by hand.
