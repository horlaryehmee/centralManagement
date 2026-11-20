# Client customisations: keeping them safe from updates

## Where things stand today

The ecommerce codebase was inspected for client-specific code mixed into the core. **None was found**: client identity (name, logo, colours, contact details, payment options, shipping zones, page content) lives entirely in each site's database and uploads, which updates never touch. The only client name in code is the demo seeder's sample content ("Nexa Stores"), which is demo data, not a customisation.

The risk is in the future and in existing deployed copies: the README's "each client has their own repository" model makes it easy to edit core files per client. Those edits would be overwritten by core updates.

## The rule

| Put it in… | When |
|---|---|
| **Settings / content** (database) | Anything the admin can already configure |
| **A module** (`modules/<slug>`) | A feature several clients may want (gift wrapping, a new payment gateway, a loyalty programme) |
| **A client extension** (`extensions/<slug>`, `"type": "extension"`) | Code for one client only (their payment provider integration, checkout rule, custom template) |
| **Core** | Only changes meant for every client, released as a new core version |

## What the system does about hand-edited core files

Before every core update the site compares its core files with the signed file list of the version it runs. If any were edited by hand, the update is **refused** with the list of changed files, and nothing is overwritten (tested end-to-end: an edited `app/Support/Money.php` blocked the update and was preserved). Central shows the failure with the file list.

## Moving an existing customisation out of core (per affected site)

1. **Find it.** Deploy the next core release with a test-first or single-site deployment; the refusal lists every changed file. Or compare a site's files with the release zip of its version.
2. **Classify** each change: configuration (move into settings), shared feature (module), client-only (extension).
3. **Rebuild it as an extension** in `extensions/<client-slug>/`:
   - a service provider that registers bindings, event listeners, view overrides (`View::prependLocation()` or a namespaced view), routes;
   - migrations for its own tables;
   - `requires.core` set to the core range it was tested with.
   If the core has no hook where the customisation needs one, add a generic hook (an event, a config option, a view slot) **to core** in a normal release. Never special-case one client in core.
4. **Release the extension** on central, assigned to that client; deploy it to that client's site and verify.
5. **Restore the original core files** on that site (or deploy the core release with "Overwrite locally modified core files" once the extension provides the behaviour).
6. From then on, core updates apply cleanly and the extension is versioned and updated on its own.

## Guarantees

- An extension release is bound to one client key inside its signed manifest. Central only targets that client's installations, and the site itself refuses a manifest for another client.
- Core releases cannot contain `modules/`, `extensions/`, `.env` or client storage (central rejects such packages), and the installer never replaces those paths.
- Module and extension updates replace only their own folder, with a backup and rollback like core.
