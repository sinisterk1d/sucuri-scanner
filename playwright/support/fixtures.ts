/**
 * Shared test fixtures. Re-export `test`/`expect` from here so specs get the
 * extra fixtures without pulling in heavy page-object machinery they don't need.
 *
 * `loggedOutRequest` is an unauthenticated APIRequestContext used by the header
 * specs to assert response headers as an anonymous visitor (the Playwright
 * equivalent of `cy.clearCookies()` + `cy.request('/')`).
 */
import { test as base, type APIRequestContext } from "@playwright/test";
import { BASE_URL } from "./env";

interface Fixtures {
  loggedOutRequest: APIRequestContext;
}

export const test = base.extend<Fixtures>({
  // Use a real Chromium browser context (no storageState) so the request goes
  // through the browser's HTTP stack — the direct equivalent of Cypress's
  // cy.clearCookies() + cy.request('/').  A bare playwright.request.newContext()
  // is a Node.js HTTP client that causes WordPress to misidentify the request
  // (is_user_logged_in() returns true), producing no-cache headers even for
  // anonymous front-end visits.
  loggedOutRequest: async ({ browser }, use) => {
    const context = await browser.newContext({ baseURL: BASE_URL });
    await use(context.request);
    await context.close();
  },
});

export { expect } from "@playwright/test";
