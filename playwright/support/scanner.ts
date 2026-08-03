/**
 * Support for the Scanner / WordPress-integrity spec — the typed seam over
 * tests/e2e-seed-scanner.sh.
 */
import { runPluginScript } from "./wp-cli";

const SEED_SCRIPT = "tests/e2e-seed-scanner.sh";

/**
 * Create the unknown-file baseline the integrity scanner reports on
 * (`wp-config-test.php` plus `wp-test-file-1..count.php`) and pin the
 * wp_update_plugins cron to a known schedule.
 *
 * `count` comes from the spec so that the same number also drives the
 * snapshot/restore file list; passing it keeps one source of truth.
 */
export function seedScannerFixtures(count: number): void {
  runPluginScript(SEED_SCRIPT, "seed", String(count));
}

/**
 * Reset the shared scanner state so each test starts from the seeded baseline:
 * delete the integrity false-positive cache and the ignore-scanning data store
 * (the plugin recreates them empty on next access). Restores the full dashboard
 * count and an empty ignore list.
 */
export function clearScannerDataStores(): void {
  runPluginScript(SEED_SCRIPT, "clear");
}
