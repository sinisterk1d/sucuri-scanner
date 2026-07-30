/**
 * Post-hack actions · Update WordPress secret keys + Automatic Secret Keys
 * Updater schedule. Page: /wp-admin/admin.php?page=sucuriscan_post_hack_actions.
 *
 * AUTH-DESTRUCTIVE: SucuriScanEvent::setNewConfigKeys() rewrites the
 * AUTH_KEY/SALT constants in wp-config.php, invalidating EVERY active session —
 * including the shared admin storageState this project inherits. So the test:
 *   1. rotates the keys (still authenticated at this point),
 *   2. re-authenticates via the UI in the same context (login() does a fresh
 *      wp-login.php round-trip, replacing the now-stale cookies),
 *   3. exercises the auto-updater (Disabled -> Quarterly -> Disabled).
 *
 * Per-test cleanup restores wp-config.php, cron, and administrator sessions, so
 * filtered/repeated runs do not permanently rotate the environment.
 */
import { test, expect } from "../../support/fixtures";
import { login } from "../../support/auth";
import { adminUser } from "../../support/env";
import {
  readWpConfig,
  restoreCron,
  restoreSerializedUserMeta,
  restoreWpConfig,
  snapshotCron,
  snapshotSerializedUserMeta,
  wpEval,
  type CronSnapshot,
} from "../../support/wp-cli";

const POST_HACK_URL = "/wp-admin/admin.php?page=sucuriscan_post_hack_actions";

let originalWpConfig: string;
let updaterCron: CronSnapshot[];
let adminSessions: string | null;

test.beforeEach(() => {
  originalWpConfig = readWpConfig();
  updaterCron = snapshotCron("sucuriscan_autoseckeyupdater");
  adminSessions = snapshotSerializedUserMeta(adminUser.login, "session_tokens");
  wpEval("wp_clear_scheduled_hook('sucuriscan_autoseckeyupdater');");
});

test.afterEach(() => {
  // Undo everything the rotation touched: wp-config.php (the AUTH_KEY/SALT
  // constants), the auto-updater cron, and the admin's session tokens — so a
  // filtered or repeated run never permanently rotates the environment.
  restoreWpConfig(originalWpConfig);
  restoreCron("sucuriscan_autoseckeyupdater", updaterCron);
  restoreSerializedUserMeta(adminUser.login, "session_tokens", adminSessions);
});

test("can update the secret keys", async ({ page }) => {
  await page.goto(POST_HACK_URL);

  // Confirm-risk checkbox + generate. This rewrites wp-config.php and
  // invalidates the current session.
  await page.getByTestId("sucuriscan_security_keys_checkbox").check();
  await page.getByTestId("sucuriscan_security_keys_submit").click();
  await expect(page.locator(".sucuriscan-alert")).toContainText(
    "Secret keys updated successfully (summary of the operation bellow).",
  );

  // Re-authenticate: the rotation invalidated our cookies, so reloading the
  // post-hack page would bounce to wp-login. login() navigates to wp-login.php
  // and submits fresh credentials, replacing the stale session in this context.
  await login(page, adminUser);
  await page.goto(POST_HACK_URL);

  // Auto-updater defaults to Disabled. The badge text uses a literal em-dash
  // (U+2014, from &mdash; in security-keys.html.tpl:47).
  const autoupdater = page.getByTestId("sucuriscan_security_keys_autoupdater");
  await expect(autoupdater).toContainText(
    "Automatic Secret Keys Updater — Disabled",
  );

  // Enable on a Quarterly schedule.
  await page
    .getByTestId("sucuriscan_security_keys_autoupdater_select")
    .selectOption({ label: "Quarterly" });
  await page.getByTestId("sucuriscan_security_keys_autoupdater_submit").click();
  await expect(page.locator(".sucuriscan-alert")).toContainText(
    "Automatic Secret Keys Updater enabled.",
  );
  await expect(autoupdater).toContainText(
    "Automatic Secret Keys Updater — Enabled",
  );

  // Disable again — leaves the cron in its original (cleared) state.
  await page
    .getByTestId("sucuriscan_security_keys_autoupdater_select")
    .selectOption({ label: "Disabled" });
  await page.getByTestId("sucuriscan_security_keys_autoupdater_submit").click();
  await expect(page.locator(".sucuriscan-alert")).toContainText(
    "Automatic Secret Keys Updater disabled.",
  );
  await expect(autoupdater).toContainText(
    "Automatic Secret Keys Updater — Disabled",
  );
});

// SKIPPED. Re-downloads and reinstalls akismet from api.wordpress.org, so it is
// live-network dependent, slow, and mutates the plugins filesystem for the whole
// suite — unsafe and non-deterministic in CI. Enabling it needs a local plugin
// fixture to reinstall from instead of the live network.
test.skip("can reset installed plugins", async ({ page }) => {
  await page.goto(
    "/wp-admin/admin.php?page=sucuriscan_settings&sucuriscan_lastlogin=1#posthack",
  );

  await page.locator('input[value="akismet/akismet.php"]').check();
  await page.getByTestId("sucuriscan_reset_plugins_submit").click();

  const response = page.getByTestId("sucuriscan_reset_plugin_response");
  await expect(response).toContainText("Loading");
  // If this is ever enabled, gate on the reset_plugin AJAX via waitForResponse
  // with a generous timeout rather than on the text alone — a reinstall takes
  // far longer than the default expect timeout.
  await expect(response).toContainText("Installed");
});
