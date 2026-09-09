const acceptanceKey = "agent_agreement_accepted_token";

export function hasAcceptedAgentAgreement(token = localStorage.getItem("agent_token")): boolean {
  return Boolean(token && (
    localStorage.getItem(acceptanceKey) === token
    || sessionStorage.getItem(acceptanceKey) === token
  ));
}

export function acceptAgentAgreement(): void {
  const token = localStorage.getItem("agent_token");
  if (token) localStorage.setItem(acceptanceKey, token);
  sessionStorage.removeItem(acceptanceKey);
}

export function clearAgentAgreement(): void {
  localStorage.removeItem(acceptanceKey);
  sessionStorage.removeItem(acceptanceKey);
}

export function applyRefreshedAgentToken(previousToken: string, token: string): boolean {
  // A late refresh must not restore a logged-out session or replace a new login.
  if (!token || localStorage.getItem("agent_token") !== previousToken) return false;
  const accepted = hasAcceptedAgentAgreement(previousToken);
  localStorage.setItem("agent_token", token);
  if (accepted) acceptAgentAgreement();
  return true;
}
