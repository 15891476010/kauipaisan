// Request/action codes found in app.bundle.a44facbc.js.
// The downloaded client sends most calls to POST /mb/ using a form field `k`
// containing a Base64-encoded JSON payload. GET calls use query field `t`.
export const API_ACTIONS = {
  tags: {
    list: { a: "mb.bt", m: "gbt" },
    create: { a: "mb.bt", m: "cbt", params: ["tn"] },
    remove: { a: "mb.bt", m: "dbt", params: ["ti"] },
  },
  lottery: {
    list: { a: "mb.lt", m: "gll" },
    routes: { a: "mb.lt", m: "gsl" },
  },
  auth: {
    validateSession: { a: "mb.lg", m: "cak", params: ["ak"] },
    login: { a: "mb.lg", m: "ml", params: ["an", "pw", "dt", "vc"] },
    logout: { a: "mb.lg", m: "mo", params: ["ak"] },
    changePassword: { a: "mb.lg", m: "mp", params: ["ak", "op", "np"] },
  },
  account: {
    balance: { a: "mb.ac", m: "gb", params: ["ak"] },
    rates: { a: "mb.rt", m: "gar" },
  },
  announcements: {
    get: { a: "mb.an", m: "gaa", params: ["ak", "tp"] },
  },
  draws: {
    recentNumbers: { a: "mb.dr", m: "gardn" },
    byLottery: { a: "mb.dr", m: "gldrl", params: ["ak", "lt"] },
    all: { a: "mb.dr", m: "gadil" },
    result: { a: "mb.dr", m: "grdn", params: ["ak", "lt", "il"] },
  },
  bets: {
    differenceList: { a: "mb.tz", m: "gbdl" },
    detailById: { a: "mb.tz", m: "gblbd", params: ["ak", "di"] },
    currentDetails: { a: "mb.tz", m: "gbicd", params: ["ak", "pi"] },
    list: { a: "mb.tz", m: "gbl" },
    temporaryText: { a: "mb.tz", m: "gtblbd", params: ["ak", "di"] },
    listText: { a: "mb.tz", m: "gblt", params: ["ak", "di"] },
    billHistory: { a: "mb.tz", m: "gbhl", params: ["ak", "ltl", "df", "dt"] },
    currentText: { a: "mb.tz", m: "gtlcd" },
    withdraw: { a: "mb.bt", m: "wbtd", params: ["ak", "di"] },
    originalText: { a: "mb.bt", m: "gtbdi", params: ["ak", "di"] },
    textDetails: { a: "mb.bt", m: "gbtdl" },
    exportAmounts: { a: "mb.bt", m: "eal" },
  },
  exports: {
    pdf: { a: "mb.pdf", m: "ep", params: ["ak", "di"] },
  },
};

// Financially consequential endpoint. It was identified from the bundle but
// was not called during UI inspection.
export const PLACE_BET_ENDPOINT = {
  url: "/api/pb",
  method: "POST",
  params: ["ak", "dlt", "bt", "ldnl"],
};
