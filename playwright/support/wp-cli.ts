/**
 * Thin wrappers around `npx wp-env run tests-cli …` for the e2e suite.
 *
 * These replace the Cypress `cy.task('exec', …)` helper and the inline
 * `php -r` snippets that the WAF specs used. Everything shells out to the
 * wp-env `tests-cli` container, so these helpers require a running wp-env
 * (Docker) — they are used from spec setup/teardown, never from page actions.
 */
import { execSync } from "node:child_process";
import { PLUGIN_SLUG, SETTINGS_FILE_PATH } from "./env";

const MAX_BUFFER = 8 * 1024 * 1024;

/** Run an arbitrary command inside the wp-env `tests-cli` container and return trimmed stdout. */
export function wpEnvRun(command: string): string {
  try {
    return execSync(`npx wp-env run tests-cli ${command}`, {
      encoding: "utf8",
      maxBuffer: MAX_BUFFER,
      stdio: ["ignore", "pipe", "pipe"],
    }).trim();
  } catch (error) {
    const err = error as {
      stderr?: Buffer | string;
      stdout?: Buffer | string;
      message?: string;
    };
    const detail = (err.stderr || err.stdout || err.message || "")
      .toString()
      .trim();
    throw new Error(`wp-env command failed: ${command}\n${detail}`);
  }
}

/** Run a WP-CLI subcommand (e.g. `option get foo --format=json`). */
export function wp(subcommand: string): string {
  return wpEnvRun(`wp ${subcommand}`);
}

/** Run a bash script that lives inside the plugin directory (relative to the plugin root). */
export function runPluginScript(relativePath: string): string {
  return wpEnvRun(`bash wp-content/plugins/${PLUGIN_SLUG}/${relativePath}`);
}

/** Read a wp_option. Returns the parsed JSON when possible, else the trimmed raw string, else null. */
export function getOption<T = unknown>(name: string): T | string | null {
  const raw = wp(`option get ${name} --format=json`);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as T;
  } catch {
    return raw.trim();
  }
}

/**
 * Like getOption, but returns null when the option does not exist instead of
 * throwing (WP-CLI exits non-zero for a missing option). Use when absence is a
 * valid, expected outcome (e.g. asserting a plaintext fallback was never written).
 */
export function tryGetOption<T = unknown>(name: string): T | string | null {
  const raw = wpEnvRun(
    `wp option get ${name} --format=json 2>/dev/null || true`,
  );
  if (!raw) return null;
  try {
    return JSON.parse(raw) as T;
  } catch {
    return raw.trim();
  }
}

/** Update a wp_option to a scalar value. */
export function updateOption(name: string, value: string): void {
  // Quote the value so spaces/special chars survive the shell.
  wp(`option update ${name} ${JSON.stringify(value)}`);
}

/** Delete a wp_option, tolerating "option does not exist". */
export function deleteOption(name: string): void {
  wpEnvRun(`wp option delete ${name} || true`);
}

/** Evaluate a short PHP one-liner via `wp eval` (avoid nested quotes — prefer a script file for complex PHP). */
export function wpEval(php: string): string {
  // Single-quote the PHP so the shell never expands $variables inside it.
  // Literal single quotes in the PHP are escaped with the '"'"' pattern.
  const escaped = php.replace(/'/g, "'\\''");
  return wpEnvRun(`wp eval '${escaped}'`);
}

/** Read the full contents of wp-config.php (checks ABSPATH and its parent). */
export function readWpConfig(): string {
  return wpEnvRun(
    `php -r '$p=ABSPATH."wp-config.php";if(!file_exists($p))$p=ABSPATH."../wp-config.php";echo file_get_contents($p);'`,
  );
}

/**
 * Read the plugin settings file (JSON written after a `<?php exit(0); ?>` guard line).
 * Returns {} when the file is missing or unparseable.
 */
export function readSettingsFileJson(): Record<string, unknown> {
  const php =
    `$path=${JSON.stringify(SETTINGS_FILE_PATH)};` +
    "$content=@file_get_contents($path);" +
    'if($content===false){echo "";exit(0);} ' +
    '$lines=explode("\\n",$content,2);' +
    'if(count($lines)<2){echo "";exit(0);} ' +
    "echo trim($lines[1]);";
  const output = wpEnvRun(`php -r ${JSON.stringify(php)}`);
  if (!output) return {};
  try {
    return JSON.parse(output) as Record<string, unknown>;
  } catch {
    return {};
  }
}

/** Ensure a WordPress user exists with the given role/password (idempotent). */
export function ensureUser(
  login: string,
  email: string,
  role: string,
  password: string,
): void {
  wpEnvRun(
    `wp user get ${login} --field=ID >/dev/null 2>&1 || ` +
      `wp user create ${login} ${email} --role=${role} --user_pass=${JSON.stringify(password)}`,
  );
}

const userIdCache = new Map<string, number>();

/**
 * Resolve a WordPress user's numeric ID by login (cached). Used to target the
 * stable `twofactor-user-checkbox-<id>` test id instead of fragile row-text
 * matching (logins like `sucuri` are substrings of `sucuri-admin`).
 */
export function getUserId(login: string): number {
  const cached = userIdCache.get(login);
  if (cached !== undefined) return cached;
  const id = Number(wp(`user get ${login} --field=ID`));
  if (!Number.isInteger(id) || id <= 0) {
    throw new Error(`Could not resolve user ID for "${login}"`);
  }
  userIdCache.set(login, id);
  return id;
}
