/**
 * Support for the Cache-Control header spec.
 *
 * Every wp-env side effect the spec needs lives in tests/e2e-seed-cache-control.sh
 * (which is `export-ignore`d, so it never reaches a wordpress.org release). This
 * module is the typed seam over that script — see its header for why each
 * command exists, in particular why scheduled posts have to be parked before the
 * emitted max-age becomes deterministic.
 */
import { runPluginScript } from "./wp-cli";

const SEED_SCRIPT = "tests/e2e-seed-cache-control.sh";

/** IDs of the published content the Cache-Control assertions read headers from. */
export interface CacheControlContent {
  postId: number;
  pageId: number;
  categoryId: number;
}

/**
 * Create the post/page/category triple, sweeping any leftovers from an
 * interrupted run first. The slug is process-scoped so a stale term from another
 * run cannot collide with this one.
 */
export function seedCacheControlContent(): CacheControlContent {
  const slug = `sucuri-e2e-cache-${process.pid}`;
  return JSON.parse(
    runPluginScript(SEED_SCRIPT, "seed", slug),
  ) as CacheControlContent;
}

/** Un-park any still-quarantined posts, then delete the fixture content. */
export function teardownCacheControlContent(): void {
  runPluginScript(SEED_SCRIPT, "teardown");
}

/** Park every scheduled post as a draft so it cannot clamp the served max-age. */
export function quarantineFuturePosts(): void {
  runPluginScript(SEED_SCRIPT, "quarantine");
}

/** Put the parked posts back to "future". */
export function restoreFuturePosts(): void {
  runPluginScript(SEED_SCRIPT, "restore");
}
