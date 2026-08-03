/**
 * Support for the Audit Logs spec.
 *
 * Typed seam over tests/e2e-seed-audit-logs.sh — see that script for why the
 * event corpus is rebuilt before every test instead of being inherited from
 * tests/e2e-prepare.sh.
 */
import { runPluginScript } from "./wp-cli";

const SEED_SCRIPT = "tests/e2e-seed-audit-logs.sh";

/**
 * Reset the audit datastores and report one warning and one notice event, so
 * the filter assertions run against a known two-event corpus.
 */
export function seedAuditQueue(): void {
  runPluginScript(SEED_SCRIPT, "seed-queue");
}
