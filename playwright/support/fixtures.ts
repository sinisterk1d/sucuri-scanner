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
  loggedOutRequest: async ({ playwright }, use) => {
    const context = await playwright.request.newContext({ baseURL: BASE_URL });
    await use(context);
    await context.dispose();
  },
});

export { expect } from "@playwright/test";
