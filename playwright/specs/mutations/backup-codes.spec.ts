/**
 * Two-Factor backup codes: the one-time reveal after enrollment, signing in with
 * a code, single-use enforcement, regeneration, and the self-only scope of the
 * regenerate control.
 *
 * Companion to two-factor.spec.ts and just as destructive — these tests enforce
 * 2FA on real accounts, so the same unconditional afterEach restore applies and
 * matters for the same reason: a test that dies mid-flow must not leave a user
 * enforced behind a challenge it can no longer answer.
 *
 * `extraUser` does all the enrolling. The shared default admin (id 1) is never
 * enrolled here — burning its single-use codes would be an awkward way to lock
 * the rest of the suite out of wp-admin.
 *
 * Why these run in a browser at all, given BackupCodesTest.php and
 * TwoFactorBackupRegenTest.php already cover the PHP: the plaintext codes exist
 * on the client exactly once, inside a modal that inc/js/backup-codes.js builds
 * at runtime and that the server can never re-send. Whether a real user can read
 * a code there and then sign in with it is only answerable here.
 */
import { test, expect } from "../../support/fixtures";
import type { Browser, Page } from "@playwright/test";
import {
  TwoFactorAdminPage,
  completeSetupWithBackupCodes,
  dismissBackupCodesModal,
  finishWithCode,
  loginExpect2FA,
  readBackupCodesFromModal,
} from "../../support/pages/two-factor.page";
import { withFreshUser } from "../../support/auth";
import {
  getUserId,
  restoreAllUserMeta,
  restorePluginData,
  restoreRawOptionsByPrefix,
  snapshotAllUserMeta,
  snapshotPluginData,
  snapshotRawOptionsByPrefix,
  type AllUserMetaSnapshotPath,
  type PluginDataSnapshot,
  type RawOptionSnapshot,
} from "../../support/wp-cli";
import { resetTwoFactorState } from "../../support/two-factor-state";
import { extraUser } from "../../support/env";

let pluginData: PluginDataSnapshot;
let userMeta: AllUserMetaSnapshotPath;
let loginTransients: Map<string, RawOptionSnapshot | null>;

const TRANSIENT_PREFIXES = [
  "_transient_sucuri_2fa_",
  "_transient_timeout_sucuri_2fa_",
  // The reveal is stashed in its own transient pair, separate from the login
  // challenge's. A leftover one would pop the modal on an unrelated later test.
  "_transient_sucuriscan_backup_codes_",
  "_transient_timeout_sucuriscan_backup_codes_",
] as const;

const USER_META_KEYS = [
  "sucuriscan_topt_secret_key",
  "sucuriscan_topt_last_success",
  "sucuriscan_topt_backup_codes",
  "session_tokens",
] as const;

/**
 * Only `extraUser`. Every reset destroys these users' sessions, and the default
 * admin's session is the storageState the `page` fixture drives the policy page
 * with — resetting it would log the test itself out mid-flow.
 */
const SESSION_RESET_LOGINS = [extraUser.login] as const;

const BACKUP_CODES_OVERLAY = ".sucuriscan-backup-codes-overlay";
const REGEN_MSG = "#sucuri-2fa-backup-regen-msg";
const REMAINING_COUNT = "#sucuri-2fa-backup-codes-count";

/**
 * Codes are shown as XXXX-XXXX: eight characters from an alphabet with the
 * ambiguous glyphs (0/O, 1/I/L) removed, split by a dash for legibility. The
 * dash is presentation only — generate_for_user() hashes the normalized value,
 * so the stored secret is the eight characters.
 */
const CODE_PATTERN = /^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$/;

/**
 * Enroll `extraUser` from a clean slate and return the revealed codes plus the
 * TOTP secret, so later logins in this test can answer the challenge normally.
 */
async function enrollExtraUser(
  browser: Browser,
  admin2fa: TwoFactorAdminPage,
): Promise<{ secret: string; codes: string[] }> {
  await admin2fa.setModeSelectedUsersFor([extraUser], "activate_selected");

  return withFreshUser(browser, async (p) => {
    await loginExpect2FA(p, extraUser, "setup");
    return completeSetupWithBackupCodes(p);
  });
}

/** Sign in as `extraUser` and answer the challenge with `code`. */
async function loginWithCredential(page: Page, code: string): Promise<void> {
  await loginExpect2FA(page, extraUser, "verify");
  await finishWithCode(page, code);
}

test.use({ preservePluginData: false });

test.describe("Two-Factor backup codes", () => {
  test.beforeEach(() => {
    pluginData = snapshotPluginData();
    userMeta = snapshotAllUserMeta(USER_META_KEYS);
    loginTransients = snapshotRawOptionsByPrefix(TRANSIENT_PREFIXES);
    resetTwoFactorState(SESSION_RESET_LOGINS);
  });

  // ALWAYS-RUNS safety net — see the file header.
  test.afterEach(() => {
    restorePluginData(pluginData);
    restoreAllUserMeta(userMeta, USER_META_KEYS);
    restoreRawOptionsByPrefix(TRANSIENT_PREFIXES, loginTransients);
  });

  test("reveals ten backup codes once after first-time setup", async ({
    page,
    browser,
  }) => {
    const admin2fa = new TwoFactorAdminPage(page);
    await admin2fa.setModeSelectedUsersFor([extraUser], "activate_selected");

    await withFreshUser(browser, async (p) => {
      await loginExpect2FA(p, extraUser, "setup");

      const { codes } = await completeSetupWithBackupCodes(p);

      expect(codes).toHaveLength(10);
      expect(new Set(codes).size).toBe(10);
      for (const code of codes) {
        expect(code).toMatch(CODE_PATTERN);
      }

      // consume_reveal() is a single read: the transient is deleted as it is
      // rendered, so a reload must not show the codes again. This is the whole
      // reason the modal makes the user tick "I have saved these codes".
      await p.goto("/wp-admin/");
      await expect(p.locator(BACKUP_CODES_OVERLAY)).toHaveCount(0);
    });

    await admin2fa.setModeAllUsers("reset_all");
  });

  test("signs in with a backup code and refuses the same code twice", async ({
    page,
    browser,
  }) => {
    const admin2fa = new TwoFactorAdminPage(page);
    const { codes } = await enrollExtraUser(browser, admin2fa);
    const [firstCode] = codes;

    // First use: the code stands in for the authenticator app.
    await withFreshUser(browser, async (p) => {
      await loginWithCredential(p, firstCode);
      await expect(p).toHaveURL(/\/wp-admin\//);

      await p.goto("/wp-admin/profile.php");
      await expect(p.locator(REMAINING_COUNT)).toHaveText("9");
    });

    // Second use of the SAME code, in a session that has not seen the challenge:
    // rejected, and the challenge is still on screen rather than a partial login.
    await withFreshUser(browser, async (p) => {
      await loginWithCredential(p, firstCode);

      await expect(p.locator("#login_error")).toContainText(
        "Invalid backup code.",
      );
      await expect(p).toHaveURL(/action=sucuri-2fa(?!-setup)/);
    });

    await admin2fa.setModeAllUsers("reset_all");
  });

  test("accepts a backup code retyped without its dash", async ({
    page,
    browser,
  }) => {
    const admin2fa = new TwoFactorAdminPage(page);
    const { codes } = await enrollExtraUser(browser, admin2fa);

    // The reveal shows XXXX-XXXX and the test above signs in with exactly that,
    // so the case still worth proving is the one a user creates by hand: reading
    // the code off the modal and typing the eight characters without the
    // separator. extract_submitted_login_credential() strips dashes and
    // whitespace before branching on length, so both spellings must land on the
    // backup-code path rather than being read as a malformed TOTP code.
    const undashed = codes[0].replace("-", "");
    expect(undashed).toHaveLength(8);

    await withFreshUser(browser, async (p) => {
      await loginWithCredential(p, undashed);
      await expect(p).toHaveURL(/\/wp-admin\//);
    });

    await admin2fa.setModeAllUsers("reset_all");
  });

  test("regenerates backup codes and invalidates the previous set", async ({
    page,
    browser,
  }) => {
    const admin2fa = new TwoFactorAdminPage(page);
    const { codes: oldCodes } = await enrollExtraUser(browser, admin2fa);

    const newCodes = await withFreshUser(browser, async (p) => {
      // Sign in with the LAST backup code rather than a TOTP one. Enrollment just
      // consumed a TOTP code, and the replay guard rejects any code from a
      // timestep at or before the last success — within the same 30s window that
      // is every code this test could compute. Spending oldCodes[9] here leaves
      // oldCodes[0] untouched for the revocation check further down.
      await loginWithCredential(p, oldCodes[oldCodes.length - 1]);
      await expect(p).toHaveURL(/\/wp-admin\//);

      await p.goto("/wp-admin/profile.php");

      // Register the confirm handler BEFORE the click — Playwright auto-dismisses
      // dialogs, which would make window.confirm() return false and the regen
      // never fire.
      p.on("dialog", async (dialog) => {
        expect(dialog.message()).toContain(
          "This will invalidate your existing backup codes",
        );
        await dialog.accept();
      });

      await Promise.all([
        p.waitForResponse(
          (r) =>
            r.url().includes("admin-ajax.php") &&
            (r.request().postData() ?? "").includes(
              "sucuri_profile_2fa_backup_regen",
            ),
        ),
        p.locator("#sucuri-2fa-backup-regen-btn").click(),
      ]);

      await expect(p.locator(REGEN_MSG)).toHaveText("");

      const codes = await readBackupCodesFromModal(p);
      await dismissBackupCodesModal(p);

      expect(codes).toHaveLength(10);
      // A regenerate that returned any of the old codes would silently leave a
      // revoked secret working.
      expect(codes.filter((code) => oldCodes.includes(code))).toEqual([]);
      // Back to a full set even though a code was spent signing in above.
      await expect(p.locator(REMAINING_COUNT)).toHaveText("10");

      return codes;
    });

    // The old set is gone: a code from it no longer opens the challenge.
    await withFreshUser(browser, async (p) => {
      await loginWithCredential(p, oldCodes[0]);
      await expect(p.locator("#login_error")).toContainText(
        "Invalid backup code.",
      );
    });

    // The new set works.
    await withFreshUser(browser, async (p) => {
      await loginWithCredential(p, newCodes[0]);
      await expect(p).toHaveURL(/\/wp-admin\//);
    });

    await admin2fa.setModeAllUsers("reset_all");
  });

  test("hides the regenerate control on another user's profile", async ({
    page,
    browser,
  }) => {
    const admin2fa = new TwoFactorAdminPage(page);
    await enrollExtraUser(browser, admin2fa);

    // Admin viewing someone else's profile: 2FA is enrolled and its status shows,
    // but regeneration is self-only, so the control must not be offered.
    // TwoFactorBackupRegenTest.php proves the server refuses the request; this is
    // what stops anyone from making it in the first place.
    await page.goto(`/wp-admin/user-edit.php?user_id=${getUserId(extraUser.login)}`);

    await expect(page.getByTestId("sucuriscan-2fa-status-text")).toContainText(
      "Two-Factor Authentication is enabled for this account.",
    );
    await expect(
      page.getByTestId("sucuriscan-2fa-backup-codes-row"),
    ).toHaveCount(0);

    await admin2fa.setModeAllUsers("reset_all");
  });
});
