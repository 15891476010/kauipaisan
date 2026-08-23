import assert from "node:assert/strict";
import test from "node:test";
import { firstAllowedRoute, isRouteAllowed } from "../src/routePermissions.ts";

test("direct URL is denied immediately when permission is absent or revoked", () => {
  const allowed = { permissions: ["overview", "logs"], level: "director", isSubaccount: false };
  assert.equal(isRouteAllowed("/logs", allowed), true);
  const refreshed = { ...allowed, permissions: ["overview"] };
  assert.equal(isRouteAllowed("/logs", refreshed), false);
  assert.equal(firstAllowedRoute(["overview", "logs"], refreshed), "overview");
});

test("nested routes inherit their top-level permission and level limits still apply", () => {
  assert.equal(isRouteAllowed("/subordinates/12/edit", { permissions: ["subordinates"], level: "agent", isSubaccount: false }), true);
  assert.equal(isRouteAllowed("/organizations", { permissions: ["*"], level: "agent", isSubaccount: false }), false);
  assert.equal(isRouteAllowed("/organizations", { permissions: ["organization.manage"], level: "director", isSubaccount: false }), true);
  assert.equal(isRouteAllowed("/unknown", { permissions: ["*"], level: "director", isSubaccount: false }), false);
});
