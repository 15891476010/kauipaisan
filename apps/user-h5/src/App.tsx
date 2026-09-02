import { useEffect, useState } from "react";
import { App as AntdApp } from "antd";
import { HashRouter, Route, Routes } from "react-router-dom";
import { Agreement, defaultAgreement, type AgreementData } from "./features/agreement/Agreement";
import { Login } from "./features/auth/Login";
import { Main } from "./features/main/Main";
import { getAgreement, getBranding, logoutSession } from "./api/user";

function clearUserAuthQuery() {
  const url = new URL(window.location.href);
  url.searchParams.delete("auto_token");
  url.searchParams.delete("line_switch");
  window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash || "#/kb"}`);
}

export default function App() {
  const { modal } = AntdApp.useApp();
  const [siteName, setSiteName] = useState(() => localStorage.getItem("site_name") || "站点会员中心");
  const [appLoading, setAppLoading] = useState(true);
  const [name, setName] = useState(() => localStorage.getItem("user_token") ? localStorage.getItem("user_name") || "" : "");
  const [mustChangePassword, setMustChangePassword] = useState(() => localStorage.getItem("user_must_change_password") === "1");
  const [agreementVisible, setAgreementVisible] = useState(() => {
    const token = localStorage.getItem("user_token");
    return Boolean(token && localStorage.getItem("user_name") && localStorage.getItem("user_must_change_password") !== "1" && sessionStorage.getItem("agreement_accepted_token") !== token);
  });
  const [agreement, setAgreement] = useState<AgreementData>(defaultAgreement);

  useEffect(() => {
    let active = true;
    const timeout = window.setTimeout(() => {
      if (active) setAppLoading(false);
    }, 1500);
    void getBranding()
      .then((response) => {
        const brandingName = String(response.data?.data?.site_name || "").trim();
        if (active && brandingName) {
          setSiteName(brandingName);
          localStorage.setItem("site_name", brandingName);
        }
      })
      .catch(() => undefined)
      .finally(() => {
        window.clearTimeout(timeout);
        if (active) setAppLoading(false);
      });
    return () => {
      active = false;
      window.clearTimeout(timeout);
    };
  }, []);

  const clearSession = () => {
    clearUserAuthQuery();
    localStorage.removeItem("user_name");
    localStorage.removeItem("user_token");
    localStorage.removeItem("user_must_change_password");
    sessionStorage.removeItem("agreement_accepted_token");
    setAgreementVisible(false);
    setMustChangePassword(false);
    setName("");
  };

  useEffect(() => {
    const handleUnauthorized = () => {
      modal.confirm({
        title: "登录已过期",
        content: "请重新登录",
        okText: "确认",
        cancelButtonProps: { style: { display: "none" } },
        maskClosable: false,
        closable: false,
        onOk: () => {
          clearSession();
          window.location.hash = "#/kb";
        },
      });
    };
    window.addEventListener("user:unauthorized", handleUnauthorized);
    return () => window.removeEventListener("user:unauthorized", handleUnauthorized);
  }, [modal]);

  useEffect(() => {
    if (!name || !agreementVisible) return;
    const token = localStorage.getItem("user_token");
    if (!token) return;
    void getAgreement()
      .then((response) => {
        const data = response.data?.data;
        if (data?.title && data?.content) setAgreement({ title: String(data.title), content: String(data.content) });
      })
      .catch(() => setAgreement(defaultAgreement));
  }, [name, agreementVisible]);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const autoToken = params.get("auto_token");
    const lineSwitch = params.get("line_switch") === "1";
    if (!autoToken && !lineSwitch) return;
    const token = autoToken || localStorage.getItem("user_token");
    clearUserAuthQuery();
    if (!token) return;
    if (autoToken) localStorage.setItem("user_token", autoToken);
    const userName = localStorage.getItem("user_name") || "站点管理员";
    localStorage.setItem("user_name", userName);
    if (lineSwitch) sessionStorage.setItem("agreement_accepted_token", token);
    else sessionStorage.removeItem("agreement_accepted_token");
    setName(userName);
    setAgreementVisible(!lineSwitch && localStorage.getItem("user_must_change_password") !== "1");
  }, []);

  const logout = () => { void logoutSession().catch(() => undefined).finally(clearSession); };

  if (appLoading) {
    return <div className="api-loading" role="status" aria-label="加载中" />;
  }

  return (
    <HashRouter>
      {name ? (
        agreementVisible ? (
          <Agreement
            agreement={agreement}
            onReject={logout}
            onAccept={() => {
              const token = localStorage.getItem("user_token");
              if (token) sessionStorage.setItem("agreement_accepted_token", token);
              setAgreementVisible(false);
            }}
          />
        ) : <Main name={name} logout={logout} forcePasswordChange={mustChangePassword} onPasswordChanged={() => { localStorage.setItem("user_must_change_password", "0"); setMustChangePassword(false); }} />
      ) : (
        <Routes>
          <Route
            path="*"
            element={
              <Login
                siteName={siteName}
                onLogin={(n) => {
                  localStorage.setItem("user_name", n);
                  const required = localStorage.getItem("user_must_change_password") === "1";
                  sessionStorage.removeItem("agreement_accepted_token");
                  setAgreement(defaultAgreement);
                  setName(n);
                  setMustChangePassword(required);
                  setAgreementVisible(!required);
                }}
              />
            }
          />
        </Routes>
      )}
    </HashRouter>
  );
}
