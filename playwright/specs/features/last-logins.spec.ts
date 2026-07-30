/**
 * Last Logins page (admin.php?page=sucuriscan_lastlogins): all-users / admins /
 * logged-in / failed tabs and the delete-logins-file actions.
 *
 * SKIPPED — environment-fragile by nature: it deletes the on-disk lastlogins and
 * failedlogins datastore files, and it depends on a real failed-login attempt
 * having been recorded, which races the audit pipeline (the submit below returns
 * before the plugin has necessarily written the failed-login entry).
 *
 * Kept fully written out rather than deleted so the scenario stays visible. To
 * enable it, replace the live bad-credentials login with a deterministic seed of
 * sucuri-failedlogins.php via wp-cli, then drop the `.skip`.
 */
import { test, expect } from "../../support/fixtures";
import { login, submitLogin } from "../../support/auth";
import { adminUser } from "../../support/env";

test.skip("can see last logins tab and delete last logins file", async ({
  page,
  browser,
}) => {
  await page.goto("/wp-admin/admin.php?page=sucuriscan_lastlogins#allusers");

  await expect(page.getByTestId("sucuriscan_last_logins_table")).toContainText(
    "admin (admin)",
  );

  await page.getByTestId("sucuriscan_last_logins_delete_logins_button").click();
  await expect(page.locator(".sucuriscan-alert")).toContainText(
    "sucuri-lastlogins.php was deleted.",
  );
  await expect(page.getByTestId("sucuriscan_last_logins_table")).toContainText(
    "no data available",
  );

  await page.getByTestId("sucuriscan_lastlogins_nav_admins").click();
  await expect(
    page.getByTestId("sucuriscan_successful_logins_table"),
  ).toContainText("admin");

  await page.getByTestId("sucuriscan_lastlogins_nav_loggedin").click();
  await expect(
    page.getByTestId("sucuriscan_successful_loggedin_table"),
  ).toContainText("admin");

  // Generate a failed-login record in a throwaway context, then return as admin.
  const guest = await browser.newContext({
    storageState: { cookies: [], origins: [] },
  });
  await submitLogin(await guest.newPage(), {
    login: "admin_x",
    pass: "NOT_A_WP_PASS",
  });
  await guest.close();
  await login(page, adminUser);

  await page.goto("/wp-admin/admin.php?page=sucuriscan_lastlogins#failed");
  await expect(page.getByTestId("sucuriscan_failedlogins_table")).toContainText(
    "admin_x",
  );

  await page
    .getByTestId("sucuriscan_failedlogins_delete_logins_button")
    .click();
  await expect(page.locator(".sucuriscan-alert")).toContainText(
    "sucuri-failedlogins.php was deleted.",
  );
  await expect(page.getByTestId("sucuriscan_failedlogins_table")).toContainText(
    "no data available",
  );
});
