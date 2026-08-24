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

test("nested routes inherit their top-level permission and level limits still apply", () => {
  assert.equal(isRouteAllowed("/subordinates/12/edit", { permissions: ["route.subordinates", "subordinates"], level: "agent", isSubaccount: false }), true);
  assert.equal(isRouteAllowed("/organizations", { permissions: ["*"], level: "agent", isSubaccount: false }), false);
  assert.equal(isRouteAllowed("/organizations", { permissions: ["route.organizations", "organization.manage"], level: "director", isSubaccount: false }), true);
  assert.equal(isRouteAllowed("/unknown", { permissions: ["*"], level: "director", isSubaccount: false }), false);
});
