/**
 * WordPress-side state helpers for the Two-Factor spec.
 *
 * Distinct from `pages/two-factor.page.ts`, which drives the admin UI: this
 * module is the typed seam over tests/e2e-seed-two-factor.sh, i.e. the wp-env
 * side effects the spec needs before and after each test.
 */
import { runPluginScript } from "./wp-cli";

const SEED_SCRIPT = "tests/e2e-seed-two-factor.sh";

/**
 * Force 2FA back to a disabled, un-enrolled state directly through WP-CLI:
 * disable the mode, empty the enforced-user list, drop every user's TOTP secret
 * and last-success meta, delete the pending-login transients, and destroy the
 * named users' sessions. Deliberately bypasses the admin UI so it still works
 * when a previous test left the UI itself behind a 2FA challenge.
 */
export function resetTwoFactorState(logins: readonly string[]): void {
  runPluginScript(SEED_SCRIPT, "reset", JSON.stringify(logins));
}

/**
 * Ensure `bulkuser-001`..`bulkuser-<count>` exist, and return only the IDs this
 * call actually created. Users left over from an earlier run are reused and
 * excluded from the result, so `deleteUsers` never removes anything the current
 * test did not make.
 */
export function createBulkUsers(count: number): number[] {
  return JSON.parse(
    runPluginScript(SEED_SCRIPT, "seed-bulk-users", String(count)),
  ) as number[];
}

/** Delete the given user IDs. */
export function deleteUsers(userIds: readonly number[]): void {
  runPluginScript(SEED_SCRIPT, "delete-users", JSON.stringify(userIds));
}
