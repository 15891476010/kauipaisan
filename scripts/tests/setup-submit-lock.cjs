async (page) => {
  const state = { requests: [], pending: [], auto: false };
  page.__submitLockTest = state;
  await page.unroute("**/prod_api/**");
  // Every non-read API request is intercepted. No real bets/settings writes.
  await page.route("**/prod_api/**", async route => {
    const req = route.request();
    const path = req.url().split("?")[0];
    const ok = data => route.fulfill({ json: { code: 0, message: "模拟测试", data } });
    if (path.endsWith("/quick-entry/settings")) {
      if (req.method() !== "GET") {
        state.auto = Boolean(req.postDataJSON()?.autoBet);
        return ok({});
      }
      return ok({ tags: [], preferences: { autoBet: state.auto, recognize: true, lottery: "福彩3D" } });
    }
    if (path.endsWith("/quick-entry/preview")) {
      const text = req.postDataJSON().text;
      return ok({
        lines: [{ id: 1, raw_text: text, input_text: text, status: "success",
          number_text: "0 1 2 3 4 5 6 7 8 9", play_type: "独胆",
          category: "福", count: 10, code_count: 10, amount: "10000.00" }],
        count: 10, code_count: 10, amount: "10000.00", formatted_text: text,
      });
    }
    if (path.endsWith("/quick-entry/place")) {
      state.requests.push(req.postDataJSON());
      const succeed = await new Promise(resolve => state.pending.push(resolve));
      return route.fulfill({ json: succeed
        ? { code: 0, message: "模拟下注成功（未落库）", data: { record_id: 0, count: 10, amount: "10000" } }
        : { code: 422, message: "模拟失败（未落库）", data: null } });
    }
    if (req.method() !== "GET") return ok({});
    return route.continue();
  });
  await page.reload();
  return { mocked: true, realPlacementAllowed: false };
}
