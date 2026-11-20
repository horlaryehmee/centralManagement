<?php

namespace App\Services;

use App\Models\Installation;
use App\Models\Release;
use App\Support\VersionConstraint as V;

/**
 * Decides whether a release may be deployed to an installation, based on what
 * the installation last reported. The update agent repeats these checks
 * locally before installing, so a stale report can't cause a bad install.
 */
class Compatibility
{
    /**
     * @return array{ok: bool, action: ?string, reasons: string[]}
     *   action: "upgrade" | "install" (module not installed yet) | null
     */
    public function check(Release $release, Installation $installation): array
    {
        $reasons = [];
        $installation->loadMissing('modules', 'client');
        $modules = $installation->modules->keyBy('slug');
        $requires = $release->requires ?: [];
        $action = 'upgrade';

        if (! $release->isPublished()) {
            $reasons[] = 'The release is not published.';
        }
        if ($installation->status !== 'active') {
            $reasons[] = 'The installation is '.(Installation::STATUSES[$installation->status] ?? $installation->status).'.';
        } elseif (! $installation->client?->isActive()) {
            $reasons[] = 'The client is suspended.';
        }
        if (! $installation->core_version) {
            $reasons[] = 'The installation has not reported its version yet.';
        }

        // Client-specific extensions never go to another client.
        if ($release->type === 'extension' && $release->client_id !== $installation->client_id) {
            return ['ok' => false, 'action' => null, 'reasons' => ['This extension belongs to another client.']];
        }

        if ($release->type === 'core') {
            if ($installation->core_version && V::compare($release->version, $installation->core_version) <= 0) {
                $reasons[] = "Already on {$installation->core_version} (same or newer).";
            }
            if ($release->upgrade_from_min && $installation->core_version && V::compare($installation->core_version, $release->upgrade_from_min) < 0) {
                $reasons[] = "Needs core {$release->upgrade_from_min} or newer first (has {$installation->core_version}).";
            }
            if ($release->upgrade_from_max && $installation->core_version && V::compare($installation->core_version, $release->upgrade_from_max) > 0) {
                $reasons[] = "Only upgrades from {$release->upgrade_from_max} or older (has {$installation->core_version}).";
            }
            // Every installed module/extension must accept the new core version.
            foreach ($modules as $m) {
                $needs = $m->requires['core'] ?? null;
                if ($needs && ! V::satisfies($release->version, $needs)) {
                    $reasons[] = "{$m->slug} {$m->version} needs core {$needs}; update or remove it first.";
                }
            }
        } else {
            $current = $modules->get($release->slug);
            if (! $current) {
                $action = 'install';
            } elseif (V::compare($release->version, $current->version) <= 0) {
                $reasons[] = "{$release->slug} is already on {$current->version} (same or newer).";
            }
            if ($current && $current->type !== $release->type) {
                $reasons[] = "{$release->slug} is installed as a {$current->type}, not a {$release->type}.";
            }
            if ($current && $release->upgrade_from_min && V::compare($current->version, $release->upgrade_from_min) < 0) {
                $reasons[] = "Needs {$release->slug} {$release->upgrade_from_min} or newer first.";
            }
            if ($current && $release->upgrade_from_max && V::compare($current->version, $release->upgrade_from_max) > 0) {
                $reasons[] = "Only upgrades {$release->slug} from {$release->upgrade_from_max} or older.";
            }
        }

        if (! empty($requires['core']) && $release->type !== 'core' && ! V::satisfies($installation->core_version, $requires['core'])) {
            $reasons[] = "Needs core {$requires['core']} (has ".($installation->core_version ?: 'unknown').').';
        }
        if (! empty($requires['php']) && ! V::satisfies($installation->php_version, $requires['php'])) {
            $reasons[] = "Needs PHP {$requires['php']} (has ".($installation->php_version ?: 'unknown').').';
        }
        foreach ($requires['modules'] ?? [] as $slug => $constraint) {
            $m = $modules->get($slug);
            if (! $m) {
                $reasons[] = "Needs module {$slug} {$constraint}, which is not installed.";
            } elseif (! V::satisfies($m->version, $constraint)) {
                $reasons[] = "Needs {$slug} {$constraint} (has {$m->version}).";
            }
        }

        return ['ok' => ! $reasons, 'action' => $reasons ? null : $action, 'reasons' => array_values(array_unique($reasons))];
    }
}
