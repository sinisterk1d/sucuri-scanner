/**
 * Support for the Audit Logs spec.
 *
 * Typed seam over tests/e2e-seed-audit-logs.sh — see that script for why the
 * event corpus is rebuilt before every test instead of being inherited from
 * tests/e2e-prepare.sh.
 */
import { type Page } from "@playwright/test";
import { BASE_URL } from "./env";
import { runPluginScript } from "./wp-cli";

const SEED_SCRIPT = "tests/e2e-seed-audit-logs.sh";

/**
 * Reset the audit datastores and report one warning and one notice event, so
 * the filter assertions run against a known two-event corpus.
 */
export function seedAuditQueue(): void {
  runPluginScript(SEED_SCRIPT, "seed-queue");
}

/**
 * Resolve the CSV export link rendered on the audit trail page.
 *
 * The href carries a nonce minted by wp_create_nonce() for the current session,
 * so it has to be read off the page rather than constructed — and whatever
 * fetches it must share that session's storageState for the nonce to verify.
 */
export async function auditLogsDownloadUrl(page: Page): Promise<URL> {
  const href = await page
    .getByTestId("sucuriscan_auditlogs_download_link")
    .getAttribute("href");

  if (!href) {
    throw new Error("The audit trail page rendered no CSV download link");
  }

  return new URL(href, BASE_URL);
}
