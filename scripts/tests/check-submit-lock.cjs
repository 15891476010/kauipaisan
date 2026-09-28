async (page) => {
  const state = page.__submitLockTest;
  if (!state) throw new Error("Mocks must be installed first");
  const input = page.getByRole("textbox", { name: "请复制文本" });
  const place = () => page.getByRole("button", { name: "下 注", exact: true }).filter({ visible: true }).first();
  const source = "福胆0 1 2 3 4 5 6 7 8 9各100倍";
  const results = [];
  const check = (value, msg) => { if (!value) throw new Error(msg); };
  const waitCount = async n => {
    for (let i = 0; i < 100 && state.requests.length < n; i++) await page.waitForTimeout(30);
    check(state.requests.length === n, "Expected requests: " + n + ", got " + state.requests.length);
  };
  const ready = async value => {
    await input.fill(value);
    await page.getByRole("button", { name: /^(识别文本|识 别)$/ }).filter({ visible: true }).first().click();
    for (let i = 0; i < 100 && !await place().isEnabled(); i++) await page.waitForTimeout(30);
    check(await place().isEnabled(), "Bet button failed to become ready");
  };
  const confirm = () => page.getByRole("dialog").getByRole("button", { name: "确认下注" });
  const finish = async success => {
    check(state.pending.length === 1, "Exactly one in-flight request expected");
    state.pending.shift()(success);
    await page.waitForFunction(() => document.querySelector(".entry")?.getAttribute("aria-busy") === "false");
    await page.waitForTimeout(350);
  };
  try {
    await ready(source);
    await place().evaluate(el => { for(let i=0;i<20;i++) el.click(); });
    check(await page.getByRole("dialog").count() === 1, "Multiple confirmation dialogs");
    await page.getByRole("button", { name: /^取\s*消$/ }).click();
    check(await place().isEnabled(), "Cancel must unlock");
    results.push("20 rapid clicks open one dialog; cancel unlocks");

    await place().click();
    await confirm().evaluate(el => { for(let i=0;i<20;i++) el.click(); });
    await waitCount(1);
    await page.waitForTimeout(750);
    check(state.requests.length === 1, "Duplicate slow request");
    check(await page.locator(".entry").getAttribute("inert") !== null, "Editor should be locked");
    check(await page.getByRole("button", { name: /^取\s*消$/ }).isDisabled(), "Cancel while submitting should be disabled");
    await finish(true);
    check(await input.inputValue() === "", "Success must clear accepted ticket");
    results.push("20 confirmation clicks while slow request produce one API call");

    await ready(source);
    await place().click();
    await confirm().click();
    await waitCount(2);
    check(state.requests[0].text === state.requests[1].text, "Repeat-content test mismatch");
    await finish(false);
    check(await input.inputValue() === source, "Failure must preserve ticket");
    await page.getByRole("dialog").getByRole("button", { name: /^确\s*认$/ }).click();
    check(await place().isEnabled(), "Failure must unlock");
    results.push("Identical content can submit again immediately; failure preserves input and unlocks");

    await place().click();
    await confirm().click();
    await waitCount(3);
    await finish(true);
    results.push("Retry after failure succeeds");

    await ready(source + "\n\n\n" + source);
    await place().click();
    await confirm().click();
    await waitCount(4);
    state.pending.shift()(true);
    await waitCount(5);
    check(await page.locator(".entry").getAttribute("aria-busy") === "true", "Batch must stay locked between tickets");
    await finish(true);
    results.push("Two identical split tickets run sequentially under one flow lock");

    await page.getByRole("switch", { name: /粘贴后自动下注/ }).click();
    const paste = () => page.locator('textarea[placeholder="请复制文本"]').evaluate((el, text) => {
      for (let i=0;i<20;i++) {
        const data = new DataTransfer();
        data.setData("text/plain", text);
        el.dispatchEvent(new ClipboardEvent("paste", { clipboardData: data, bubbles: true, cancelable: true }));
      }
    }, source);
    await paste();
    await waitCount(6);
    await paste();
    await page.waitForTimeout(750);
    check(state.requests.length === 6, "Auto-paste produced concurrent bets");
    await finish(true);
    results.push("Rapid auto-paste sends one request, repeated paste while pending ignored");
    return { page: page.url(), requests: state.requests.length, realBets: 0, results };
  } finally {
    for (const resolve of state.pending.splice(0)) resolve(false);
  }
}
