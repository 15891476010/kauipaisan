import assert from "node:assert/strict";
import test from "node:test";
import { firstAllowedRoute, isRouteAllowed } from "../src/routePermissions.ts";

test("a route requires both its route switch and one visible page permission", () => {
  const allowed = { permissions: ["route.overview", "overview", "route.logs", "logs"], level: "director", isSubaccount: false };
  assert.equal(isRouteAllowed("/logs", allowed), true);
  assert.equal(isRouteAllowed("/logs", { ...allowed, permissions: ["logs"] }), false);
  assert.equal(isRouteAllowed("/logs", { ...allowed, permissions: ["route.logs"] }), false);
});

test("revoked routes redirect to the first fully authorized route", () => {
  const refreshed = { permissions: ["route.overview", "overview"], level: "director", isSubaccount: false };
  assert.equal(isRouteAllowed("/logs", refreshed), false);
  assert.equal(firstAllowedRoute(["overview", "logs"], refreshed), "overview");
});

test("subaccounts only see selected menus and cannot manage subaccounts", () => {
  const context = { permissions: ["route.overview", "overview", "bet_details", "route.ledger", "contribution", "route.reports", "reports", "monthly_reports", "route.results", "results", "route.settings", "settings", "settings.update"], level: "director", isSubaccount: true };
  for (const path of ["overview", "ledger", "reports", "results", "settings"]) assert.equal(isRouteAllowed(path, context), true);
  for (const path of ["logs", "rules", "subordinates", "intercept", "subaccounts"]) assert.equal(isRouteAllowed(path, context), false);
  assert.equal(isRouteAllowed("subaccounts", { ...context, permissions: ["*"] }), false);
  assert.equal(isRouteAllowed("logs", { ...context, permissions: ["route.logs", "logs"] }), true);
  assert.equal(isRouteAllowed("rules", { ...context, permissions: ["route.rules", "rules"] }), true);
  assert.equal(firstAllowedRoute(["overview", "reports"], { ...context, permissions: [] }), null);
});

test("nested routes inherit their top-level permission without hard-coded level blocks", () => {
  assert.equal(isRouteAllowed("/subordinates/12/edit", { permissions: ["route.subordinates", "subordinates"], level: "agent", isSubaccount: false }), true);
  assert.equal(isRouteAllowed("/organizations", { permissions: ["*"], level: "agent", isSubaccount: false }), true);
  assert.equal(isRouteAllowed("/organizations", { permissions: ["route.organizations", "organization.manage"], level: "director", isSubaccount: false }), true);
  assert.equal(isRouteAllowed("/unknown", { permissions: ["*"], level: "director", isSubaccount: false }), false);
});
