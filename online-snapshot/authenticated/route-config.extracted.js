// Extracted from app.bundle.a44facbc.js near byte 6,000.
export const authenticatedRoutes = {
  zh: { title: "下注明细", subpages: null, hidden: true },
  zd: { title: "历史账单", subpages: null },
  hyxx: { title: "会员资料", subpages: null },
  jg: { title: "开奖号码", subpages: null },
  gz: { title: "规则说明", subpages: null },
  xgmm: { title: "修改密码", subpages: null },
  kb: { title: "文本录入", subpages: null },
  zjdd: { title: "当期文本", subpages: null },
};

export const routePaths = Object.fromEntries(
  Object.entries(authenticatedRoutes).map(([key, value]) => [key, {
    ...value,
    hashPath: `#/${key}`,
  }]),
);
