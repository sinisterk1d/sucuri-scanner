/**
 * Redaction of credential-shaped values in wp-env failure messages.
 *
 * wpEnvRun puts the failed command and its stderr into the thrown Error so a
 * broken seed script says why it broke. Both can carry secrets — a WAF key
 * arrives as a script argument, and `wp eval` output can echo plug-salts or
 * TOTP secrets — and CI retains that output. If this redactor regresses, the
 * suite starts printing credentials, so these cases are the guard on it.
 */
import { test, expect } from "@playwright/test";
import { redactSecrets } from "../../support/wp-cli";

test("masks a firewall API key passed as a script argument", () => {
  const command =
    "bash tests/e2e-seed-waf-key.sh save " +
    "cccccccccccccccccccccccccccccccc/dddddddddddddddddddddddddddddddd";

  const redacted = redactSecrets(command);

  expect(redacted).toBe("bash tests/e2e-seed-waf-key.sh save <waf-key>");
  // The key must not survive in halves either.
  expect(redacted).not.toContain("cccccccccccccccccccccccccccccccc");
});

test("masks SUCURI_PLUG_KEY and SUCURI_PLUG_SALT values", () => {
  const salt = "b".repeat(64);

  const redacted = redactSecrets(`define('SUCURI_PLUG_SALT', '${salt}');`);

  expect(redacted).toBe("define('SUCURI_PLUG_SALT', '<hex-secret>');");
});

test("masks a base32 TOTP secret", () => {
  const redacted = redactSecrets("otpauth://totp/x?secret=JBSWY3DPEHPK3PXPJBSW");

  expect(redacted).not.toContain("JBSWY3DPEHPK3PXPJBSW");
  expect(redacted).toContain("<base32-secret>");
});

test("masks a password passed to wp user update", () => {
  const redacted = redactSecrets(
    "wp user update admin --user_pass=hunter2 --role=administrator",
  );

  expect(redacted).toBe(
    "wp user update admin --user_pass=<redacted> --role=administrator",
  );
});

test("leaves an ordinary failure message readable", () => {
  const message =
    "Error: scanner fixture: seed needs a positive file count\n" +
    "bash tests/e2e-seed-scanner.sh seed";

  expect(redactSecrets(message)).toBe(message);
});

test("masks every secret when several appear together", () => {
  const redacted = redactSecrets(
    [
      "a".repeat(32) + "/" + "b".repeat(32),
      "c".repeat(64),
      "--user_pass=letmein",
    ].join(" "),
  );

  expect(redacted).toBe("<waf-key> <hex-secret> --user_pass=<redacted>");
});
