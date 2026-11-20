# Installation API (v1)

Base: `https://<central>/api/v1/`. JSON in and out. HTTPS only. Errors look like
`{"error": "<code>", "message": "<text for people>"}` with a matching HTTP status.

## Authentication

Every call except `register` is signed by the installation:

| Header | Value |
|---|---|
| `X-Installation-Id` | installation UUID |
| `X-Timestamp` | Unix seconds (must be within ±300 s of the server) |
| `X-Nonce` | 16–64 chars `[A-Za-z0-9]`, never reused |
| `X-Signature` | hex `HMAC-SHA256(secret, METHOD + "\n" + path + "\n" + timestamp + "\n" + nonce + "\n" + sha256_hex(body))` |

`path` is the request path without a leading slash, e.g. `api/v1/heartbeat`. For GET the body is empty.
Failures: `401 unauthenticated` (unknown/revoked/wrong signature: same answer for all), `401 clock_skew`, `401 replayed`, `403 https_required`, `429` rate limit.
Large JSON answers are gzipped when the request sends `Accept-Encoding: gzip`.

## Endpoints

### `POST register` (unsigned, 10/min per IP)

```json
{ "code": "ABCDE-FGHIJ-…", "site_url": "https://shop.example.com", "version": "1.0.0" }
```

→ `200`

```json
{ "installation_id": "uuid", "secret": "64 chars, shown once", "client_key": "uuid",
  "signing": { "key_id": "b3f9cb5ec4c72cff", "public_key": "base64 Ed25519", "fingerprint": "B3F9:CB5E:…" },
  "check_interval": 15, "server_time": "…" }
```

Errors: `422 invalid_code` (unknown or already used), `422 expired_code`, `403 client_suspended`. A different reported domain is accepted but logged as a warning.

### `POST heartbeat`

```json
{ "version": "1.1.0", "php": "8.3.30", "framework": "12.72.0",
  "server": { "php_sapi": "cgi-fcgi", "zip": true, "disk_free_mb": 9000, "can_spawn_process": false, … },
  "modules": [ { "slug": "gift-wrap", "type": "module", "name": "Gift wrapping", "version": "1.1.0",
                 "enabled": true, "requires": { "core": "^1.0" } } ] }
```

→ `{ "status": "active" | "suspended", "check_interval": 15, "instructions": [ … ], "server_time": "…" }`

At most one instruction: `{ "type": "deploy" | "rollback", "target": 42, "release": { "type": "core", "slug": "core", "version": "1.2.0" } }`.
Suspended installations get no instructions. Modules not reported are removed from central's record.

### `GET updates`

Published releases this installation could take now (core, its installed modules, its own client's extensions), already compatibility-checked: `{ "updates": [ { "type", "slug", "version", "name", "notes", "published_at" } ] }`.

### `GET manifests/{type}/{slug}/{version}`

Signed manifest of a published or withdrawn release, used as the baseline for detecting hand-edited files: `{ "manifest": "<exact JSON string>", "signature": "base64", "key_id": "…" }`. Extensions: own client only (others: `404`).

### `POST deployments/{target}/accept`

Deploy instruction → `200`

```json
{ "target": 42, "action": "deploy",
  "release": { "uuid", "type", "slug", "version", "notes" },
  "manifest": "<exact signed JSON string>", "signature": "base64 Ed25519", "key_id": "…",
  "download": { "path": "api/v1/deployments/42/package", "size": 19755182, "sha256": "…" },
  "options": { "allow_modified_files": false } }
```

Rollback instruction → `{ "target", "action": "rollback", "release", "manifest", "signature", "key_id", "rollback_database": true }`.
Compatibility is re-checked on first accept (`409 incompatible` marks the target failed). Other `409`s: `not_deliverable`, `release_withdrawn`. `403 suspended`. Accepting again while in progress returns the same payload (resume).

### `GET deployments/{target}/package`

The zip (`application/zip`, supports `Range` for resuming). Only while the target is `delivered`/`running`.

### `POST deployments/{target}/progress`

`{ "step": "download|backup|extract|install|finish|waiting|rollback", "message": "…" }` → `{ "ok": true }`. Informational.

### `POST deployments/{target}/complete`

```json
{ "result": "succeeded" | "failed" | "rolled_back" | "rollback_failed",
  "version": "1.2.0", "error": "…", "error_code": "modified_files", "retryable": false,
  "log": [ "09:12:01 Downloaded 18.8 MB; checksum verified.", … ] }
```

The only way a target becomes `succeeded`. `retryable: true` failures are retried with backoff up to the deployment's attempt limit.

## Manifest format (signed)

```json
{
  "format": 1,
  "release": "uuid", "type": "core|module|extension", "slug": "core|<slug>", "version": "1.2.0",
  "client": null | "<client key>",
  "requires": { "core": "^1.0", "php": ">=8.2", "modules": { "payments": "^2.0" } },
  "upgrade_from": { "min": "1.1.0", "max": null },
  "migrations": true,
  "rollback": { "files": true, "database": false },
  "package": { "sha256": "…", "size": 19755182, "root": "" },
  "files": { "app/Http/Kernel.php": "<sha256>", … },
  "instructions": "…",
  "key_id": "…", "signed_at": "…"
}
```

The signature covers the exact bytes of this JSON string. The agent verifies the signature, then the package checksum, then every extracted file against `files`.

## Error codes the agent reports

`bad_signature`, `untrusted_key`, `bad_manifest`, `manifest_mismatch`, `incompatible`, `modified_files`, `checksum` (retryable), `network` (retryable), `server_not_ready`, `install_error`, `finish_failed`, `interrupted` (retryable), `no_backup`, `rollback_error`.
