export type RouteAccessContext = {
  permissions: string[];
  level: string;
  isSubaccount: boolean;
};

export const routePermissions: Record<string, string[]> = {
  overview: ["overview", "order_details", "bet_details", "winning_details"],
  ledger: ["contribution", "daily_ledger", "monthly_ledger", "daily_path", "monthly_path"],
  reports: ["reports", "monthly_reports"],
  results: ["results"],
  organizations: ["organization.manage"],
  subordinates: ["subordinates"],
  intercept: ["interception_details", "interception_winning", "interception_plate"],
  logs: ["logs"],
  rules: ["rules"],
  settings: ["settings"],
  subaccounts: ["subaccounts"],
};

export function routeKey(pathname: string): string {
  return pathname.replace(/^#?\/?/, "").split("/")[0] || "overview";
}

export function isRouteAllowed(pathname: string, context: RouteAccessContext): boolean {
  const key = routeKey(pathname);
  if (!(key in routePermissions)) return false;
  if (key === "organizations" && (context.level === "agent" || context.isSubaccount)) return false;
  if (key === "subordinates" && context.level !== "agent") return false;
  if (context.permissions.includes("*")) return true;
  return routePermissions[key].some((permission) => context.permissions.includes(permission));
}

export function firstAllowedRoute(paths: string[], context: RouteAccessContext): string | null {
  return paths.find((path) => isRouteAllowed(path, context)) ?? null;
}
