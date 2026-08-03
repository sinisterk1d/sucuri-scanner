/**
 * Support for the Settings · General spec.
 *
 * Typed seam over tests/e2e-seed-settings-general.sh — see that script for why
 * the datastore file has to exist before the deletion test loads the page.
 */
import { runPluginScript } from "./wp-cli";

const SEED_SCRIPT = "tests/e2e-seed-settings-general.sh";

/**
 * Create an empty `sucuri-integrity.php` datastore so the deletion test starts
 * with exactly one writable file to select.
 */
export function writeIntegrityDatastore(): void {
  runPluginScript(SEED_SCRIPT, "write-integrity-datastore");
}
