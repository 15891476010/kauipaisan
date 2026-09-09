import assert from "node:assert/strict";
import { beforeEach, test } from "node:test";
import { acceptAgentAgreement, applyRefreshedAgentToken, clearAgentAgreement, hasAcceptedAgentAgreement } from "../src/utils/agreementSession.ts";

function storage() {
  const values = new Map<string, string>();
  return {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => { values.set(key, value); },
    removeItem: (key: string) => { values.delete(key); },
  };
}

beforeEach(() => {
  Object.defineProperty(globalThis, "localStorage", { configurable: true, value: storage() });
  Object.defineProperty(globalThis, "sessionStorage", { configurable: true, value: storage() });
  localStorage.setItem("agent_token", "login-a");
});

test("acceptance survives page reload and a new tab for the same login", () => {
  assert.equal(hasAcceptedAgentAgreement(), false);
  acceptAgentAgreement();
  assert.equal(hasAcceptedAgentAgreement(), true);
  Object.defineProperty(globalThis, "sessionStorage", { configurable: true, value: storage() });
  assert.equal(hasAcceptedAgentAgreement(), true);
});

test("automatic token renewal preserves acceptance across repeated renewals", () => {
  acceptAgentAgreement();
  assert.equal(applyRefreshedAgentToken("login-a", "renewal-a"), true);
  assert.equal(hasAcceptedAgentAgreement(), true);
  assert.equal(applyRefreshedAgentToken("renewal-a", "renewal-b"), true);
  assert.equal(hasAcceptedAgentAgreement(), true);
});

test("renewal does not accept an agreement that was never accepted", () => {
  assert.equal(applyRefreshedAgentToken("login-a", "renewal-a"), true);
  assert.equal(hasAcceptedAgentAgreement(), false);
});

test("existing per-tab acceptance survives renewal and migrates to shared storage", () => {
  sessionStorage.setItem("agent_agreement_accepted_token", "login-a");
  assert.equal(hasAcceptedAgentAgreement(), true);
  applyRefreshedAgentToken("login-a", "renewal-a");
  assert.equal(localStorage.getItem("agent_agreement_accepted_token"), "renewal-a");
  assert.equal(sessionStorage.getItem("agent_agreement_accepted_token"), null);
});

test("logout or expiration clears acceptance and the next login requires agreement", () => {
  acceptAgentAgreement();
  localStorage.removeItem("agent_token");
  clearAgentAgreement();
  assert.equal(hasAcceptedAgentAgreement(), false);
  localStorage.setItem("agent_token", "login-b");
  assert.equal(hasAcceptedAgentAgreement(), false);
});

test("another login cannot inherit acceptance, even before explicit cleanup", () => {
  acceptAgentAgreement();
  localStorage.setItem("agent_token", "login-b");
  assert.equal(hasAcceptedAgentAgreement(), false);
  assert.equal(applyRefreshedAgentToken("login-a", "late-renewal"), false);
  assert.equal(localStorage.getItem("agent_token"), "login-b");
});

test("a delayed renewal cannot restore a logged-out session", () => {
  acceptAgentAgreement();
  localStorage.removeItem("agent_token");
  clearAgentAgreement();
  assert.equal(applyRefreshedAgentToken("login-a", "late-renewal"), false);
  assert.equal(localStorage.getItem("agent_token"), null);
  assert.equal(hasAcceptedAgentAgreement(), false);
});
