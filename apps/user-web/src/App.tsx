import { useEffect, useState } from "react";
import { HashRouter, Route, Routes } from "react-router-dom";
import { Agreement, defaultAgreement, type AgreementData } from "./features/agreement/Agreement";
import { Login } from "./features/auth/Login";
import { Main } from "./features/main/Main";
import { getAgreement, logoutSession } from "./api/user";

function clearUserAuthQuery() {
  const url = new URL(window.location.href);
  url.searchParams.delete("auto_token");
  url.searchParams.delete("line_switch");
  window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash || "#/kb"}`);
}

export default function App() {
  const siteName = localStorage.getItem("site_name") || "站点会员中心";
  const [name, setName] = useState(() => localStorage.getItem("user_token") ? localStorage.getItem("user_name") || "" : "");
  const [agreementVisible, setAgreementVisible] = useState(() => {
    const token = localStorage.getItem("user_token");
    return Boolean(token && localStorage.getItem("user_name") && sessionStorage.getItem("agreement_accepted_token") !== token);
  });
  const [agreement, setAgreement] = useState<AgreementData>(defaultAgreement);

  useEffect(() => {
    const handleUnauthorized = () => {
      setAgreementVisible(false);
      setName("");
    };
    window.addEventListener("user:unauthorized", handleUnauthorized);
    return () => window.removeEventListener("user:unauthorized", handleUnauthorized);
  }, []);

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
    setAgreementVisible(!lineSwitch);
  }, []);

  const clearSession = () => {
    clearUserAuthQuery();
    localStorage.removeItem("user_name");
    localStorage.removeItem("user_token");
    sessionStorage.removeItem("agreement_accepted_token");
    setAgreementVisible(false);
    setName("");
  };
  const logout = () => { void logoutSession().catch(() => undefined).finally(clearSession); };

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
        ) : <Main name={name} logout={logout} />
      ) : (
        <Routes>
          <Route
            path="*"
            element={
              <Login
                siteName={siteName}
                onLogin={(n) => {
                  localStorage.setItem("user_name", n);
                  sessionStorage.removeItem("agreement_accepted_token");
                  setAgreement(defaultAgreement);
                  setName(n);
                  setAgreementVisible(true);
                }}
              />
            }
          />
        </Routes>
      )}
    </HashRouter>
  );
}
