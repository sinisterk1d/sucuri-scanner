/**
 * Audit logs: sending logs to the Sucuri servers (AJAX stubbed with fixtures),
 * filtering the local audit log (plugins / logins / time / search), and the CSV
 * export.
 *
 * The send-logs test stubs the two admin-ajax actions so it never calls the
 * real Sucuri API; every other test runs against real audit data rebuilt before
 * each one by tests/e2e-seed-audit-logs.sh.
 *
 * The CSV tests are the only coverage anywhere for the export's response
 * headers: downloadAuditLogs() emits them with bare header() calls and ends in
 * exit(), which the PHPUnit suite cannot observe under processIsolation — see
 * the @codeCoverageIgnore note on src/auditlogs.lib.php. AuditLogsCsvTest.php
 * covers everything that method delegates (the CSV body, the nonce gate); what
 * reaches the browser is checked here.
 */
import path from "node:path";
import { test, expect } from "../../support/fixtures";
import type { Page } from "@playwright/test";
import { auditLogsDownloadUrl, seedAuditQueue } from "../../support/audit-logs";

const DATA_DIR = path.join(__dirname, "../../data");
const AUDIT_LOGS_FIXTURE = path.join(DATA_DIR, "audit_logs.json");
const SEND_LOGS_FIXTURE = path.join(DATA_DIR, "auditlogs_send_logs.json");

const REPORTING_URL =
  "/wp-admin/admin.php?page=sucuriscan_events_reporting#auditlogs";

const DOWNLOAD_ACTION = "sucuriscan_download_audit_logs";
const CSV_HEADER_ROW =
  '"Date","Time","Severity","Username","IP Address","Message","Details"';

/** The warning event seeded by tests/e2e-seed-audit-logs.sh; the notice is the other. */
const SEEDED_WARNING = "Plugin activated: Akismet Anti-spam";

test.beforeEach(() => {
  seedAuditQueue();
});

/** Click the filter button and wait for the audit-log list AJAX to come back. */
async function applyFilter(
  page: Page,
  expected: Record<string, string>,
): Promise<void> {
  await Promise.all([
    page.waitForResponse(
      (r) => {
        const url = new URL(r.url());
        return (
          url.pathname.endsWith("admin-ajax.php") &&
          (r.request().postData() ?? "").includes("get_audit_logs") &&
          Object.entries(expected).every(
            ([name, value]) => url.searchParams.get(name) === value,
          )
        );
      },
    ),
    page.getByTestId("sucuriscan_auditlogs_filter_button").click(),
  ]);
}

async function clearFilter(page: Page): Promise<void> {
  // Clearing re-runs get_audit_logs, which re-renders BOTH the entry list and the
  // #sucuriscan-filters <select> block. Await that response so the next
  // selectOption acts on the fresh select nodes (not the about-to-be-replaced
  // ones) and the following applyFilter can't latch onto this in-flight response.
  await Promise.all([
    page.waitForResponse(
      (r) =>
        r.url().includes("admin-ajax.php") &&
        (r.request().postData() ?? "").includes("get_audit_logs") &&
        !["time", "posts", "logins", "users", "plugins", "files", "search"].some(
          (name) => new URL(r.url()).searchParams.has(name),
        ),
    ),
    page.getByTestId("sucuriscan_auditlogs_clear_filter_button").click(),
  ]);

  // The clear handler itself only re-runs the query; it resets no control. What
  // empties the search box is the server returning a fresh filters snippet on
  // every get_audit_logs call (auditlogs.lib.php sets $response['filters']
  // unconditionally), which replaces #sucuriscan-filters wholesale. Assert it
  // here because that server-side re-render is the ONLY thing clearing the box —
  // a filters snippet that ever echoed the submitted term back would strand the
  // user on a filtered view with a Clear button that looks like it worked.
  await expect(page.getByTestId("sucuriscan_auditlogs_search")).toHaveValue("");
}

/** Assert every rendered entry title matches `pattern`. */
async function expectAllEntryTitles(
  page: Page,
  pattern: RegExp,
): Promise<void> {
  const titles = page.locator(
    ".sucuriscan-auditlog-entry .sucuriscan-auditlog-entry-title",
  );
  await expect(titles.first()).toBeVisible();
  const count = await titles.count();
  expect(count).toBeGreaterThan(0);
  for (let i = 0; i < count; i++) {
    await expect(titles.nth(i)).toContainText(pattern);
  }
}

test("sends audit logs to the Sucuri servers (AJAX stubbed)", async ({
  page,
}) => {
  await page.route("**/admin-ajax.php**", async (route) => {
    const body = route.request().postData() ?? "";
    if (body.includes("get_audit_logs")) {
      return route.fulfill({ path: AUDIT_LOGS_FIXTURE });
    }
    if (body.includes("auditlogs_send_logs")) {
      // Small delay so the "Loading..." state is observable, mirroring the
      // original assertion that the user sees progress before results.
      await new Promise((resolve) => setTimeout(resolve, 300));
      return route.fulfill({ path: SEND_LOGS_FIXTURE });
    }
    return route.fallback();
  });

  await page.goto(REPORTING_URL);

  // Use Promise.all so we reliably catch the auditlogs_send_logs round-trip
  // before asserting the result; the element does not transition through a
  // "Loading..." state during send (it's only set during the initial page-load
  // get_audit_logs call, which the stub resolves immediately).
  const sent = page.waitForResponse(
    (r) =>
      r.url().includes("admin-ajax.php") &&
      (r.request().postData() ?? "").includes("auditlogs_send_logs"),
  );
  await page.getByTestId("sucuriscan_dashboard_send_audit_logs_submit").click();
  await expect(
    page.locator(".sucuriscan-auditlogs-sendlogs-response"),
  ).toContainText("Loading...");
  await sent;

  await expect(
    page.locator(".sucuriscan-auditlog-entry-title").first(),
  ).toContainText("User authentication succeeded: admin");
});

test("filters audit logs by plugin, login, and time", async ({ page }) => {
  await page.goto(REPORTING_URL);

  await expect(page.locator(".sucuriscan-auditlog-response")).toBeVisible();
  await expect(
    page.locator(".sucuriscan-auditlog-entry").first(),
  ).toBeVisible();

  // Plugins filter -> every entry is a plugin activation.
  await page.locator("#plugins").selectOption({ label: "Activated" });
  await applyFilter(page, { plugins: "activated" });
  await expectAllEntryTitles(page, /Plugin activated/);
  await clearFilter(page);

  // Logins filter -> every entry is a successful authentication.
  await page.locator("#logins").selectOption({ label: "Succeeded" });
  await applyFilter(page, { logins: "succeeded" });
  await expectAllEntryTitles(page, /User authentication succeeded/);
  await clearFilter(page);

  // Combined plugins + logins -> each entry matches one of the two.
  await page.locator("#plugins").selectOption({ label: "Activated" });
  await page.locator("#logins").selectOption({ label: "Succeeded" });
  await applyFilter(page, { plugins: "activated", logins: "succeeded" });
  await expectAllEntryTitles(
    page,
    /Plugin activated|User authentication succeeded/,
  );
  await clearFilter(page);

  // Combined time + login -> only successful authentications.
  await page.locator("#time").selectOption({ label: "Last 7 Days" });
  await page.locator("#logins").selectOption({ label: "Succeeded" });
  await applyFilter(page, { time: "last 7 days", logins: "succeeded" });
  await expectAllEntryTitles(page, /User authentication succeeded/);
  await clearFilter(page);

  // NOTE: the 'Custom' date-range branch of the time filter is deliberately not
  // covered here — it needs a #startDate/#endDate pair seeded relative to the
  // audit entries, which the fixed fixture data above cannot provide.
});

test("searches audit trails with the Enter key", async ({ page }) => {
  await page.goto(REPORTING_URL);
  await expect(
    page.locator(".sucuriscan-auditlog-entry").first(),
  ).toBeVisible();

  const search = page.getByTestId("sucuriscan_auditlogs_search");
  await search.fill("Akismet");

  // Press Enter rather than clicking Filter: the keydown handler that maps one
  // to the other is the code under test. The server-side matching it triggers
  // is already covered by AuditlogsTest.php.
  await Promise.all([
    page.waitForResponse((r) => {
      const url = new URL(r.url());
      return (
        url.pathname.endsWith("admin-ajax.php") &&
        (r.request().postData() ?? "").includes("get_audit_logs") &&
        url.searchParams.get("search") === "Akismet"
      );
    }),
    search.press("Enter"),
  ]);

  await expectAllEntryTitles(page, new RegExp(SEEDED_WARNING));

  // Clearing restores the other seeded event, which the search had excluded.
  await clearFilter(page);
  await expectAllEntryTitles(
    page,
    /Plugin activated|User authentication succeeded/,
  );
});

test("exports every stored audit trail as CSV", async ({ page, request }) => {
  await page.goto(REPORTING_URL);

  const url = await auditLogsDownloadUrl(page);

  // A plain nonced link to admin-post.php — no JavaScript involved, which is
  // why this is fetched rather than clicked.
  expect(url.pathname).toBe("/wp-admin/admin-post.php");
  expect(url.searchParams.get("action")).toBe(DOWNLOAD_ACTION);
  expect(url.searchParams.get("sucuriscan_page_nonce")).toMatch(
    /^[a-z0-9]{10}$/,
  );

  // The `request` fixture inherits the project's admin storageState, so it
  // presents the same session the nonce above was minted for.
  const response = await request.get(url.toString());
  expect(response.status()).toBe(200);

  const headers = response.headers();
  expect(headers["content-type"]).toContain("text/csv");
  expect(headers["content-disposition"]).toContain("attachment;");
  expect(headers["x-content-type-options"]).toBe("nosniff");

  const body = await response.text();
  const lines = body.split("\r\n").filter((line) => line !== "");

  expect(lines[0]).toBe(CSV_HEADER_ROW);
  expect(lines.length).toBeGreaterThan(1);
  expect(body).toContain(SEEDED_WARNING);

  // The queue is a PHP datastore file: every one starts with <?php, a run of
  // `// key=value;` comments and exit(0) so a direct HTTP request returns
  // nothing. That guard block is not audit data and must never be exported.
  expect(body).not.toContain("<?php");
  expect(body).not.toContain("exit(0)");
});

test("refuses to export the audit trail with a forged nonce", async ({
  request,
}) => {
  // Ten lowercase alphanumerics: well-formed enough to satisfy the `_nonce`
  // pattern in SucuriScanRequest, so this reaches wp_verify_nonce and proves
  // the nonce is actually verified rather than merely shape-checked.
  const response = await request.get(
    `/wp-admin/admin-post.php?action=${DOWNLOAD_ACTION}` +
      "&sucuriscan_page_nonce=aaaaaaaaaa",
    { failOnStatusCode: false },
  );

  expect(response.status()).toBe(403);
  expect(response.headers()["content-type"] ?? "").not.toContain("text/csv");
});
