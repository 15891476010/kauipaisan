export type RouteAccessContext = {
  permissions: string[];
  level: string;
  isSubaccount: boolean;
};

export const routePermissions: Record<string, string[]> = {
  overview: ["overview", "order_details", "bet_details", "winning_details", "refunds"],
  ledger: ["contribution", "daily_ledger", "monthly_ledger", "daily_path", "monthly_path"],
  reports: ["reports", "monthly_reports"],
  results: ["results"],
  subordinates: ["subordinates"],
  intercept: ["interception_details", "interception_winning", "interception_plate"],
  logs: ["logs"],
  rules: ["rules"],
  settings: ["settings"],
  subaccounts: ["subaccounts"],
};

export const routePermissionCodes: Record<string, string> = {
  overview: "route.overview",
  ledger: "route.ledger",
  reports: "route.reports",
  results: "route.results",
  subordinates: "route.subordinates",
  intercept: "route.intercept",
  logs: "route.logs",
  rules: "route.rules",
  settings: "route.settings",
  subaccounts: "route.subaccounts",
};

export function storedAgentPermissions(): string[] {
  try {
    const value = JSON.parse(localStorage.getItem("agent_permissions") || "[]");
    return Array.isArray(value) ? value.map(String) : [];
  } catch {
    return [];
  }
}

export function hasAgentPermission(permission: string, permissions = storedAgentPermissions()): boolean {
  return permissions.includes("*") || permissions.includes(permission);
}

export function hasAnyAgentPermission(permissions: string[], candidates: string[]): boolean {
  return permissions.includes("*") || candidates.some((permission) => permissions.includes(permission));
}

export function routeKey(pathname: string): string {
  return pathname.replace(/^#?\/?/, "").split("/")[0] || "overview";
}

export function isRouteAllowed(pathname: string, context: RouteAccessContext): boolean {
  const key = routeKey(pathname);
  if (!(key in routePermissions)) return false;
  if (context.permissions.includes("*")) return true;
  const routePermission = routePermissionCodes[key];
  return Boolean(routePermission && context.permissions.includes(routePermission))
    && routePermissions[key].some((permission) => context.permissions.includes(permission));
}

export function firstAllowedRoute(paths: string[], context: RouteAccessContext): string | null {
  return paths.find((path) => isRouteAllowed(path, context)) ?? null;
}
