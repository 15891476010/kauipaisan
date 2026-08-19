import { App as AntdApp, Switch } from "antd";
import { DoubleRightOutlined } from "@ant-design/icons";
import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { createAgentMember, getLotteries, type MemberLotteryPermission } from "../../api/user";
import { apiErrorMessage } from "../../utils/request";

type FormState = { username: string; password: string };

export function SubordinateFormPage() {
  const { message } = AntdApp.useApp();
  const navigate = useNavigate();
  const [form, setForm] = useState<FormState>({ username: "", password: "" });
  const [permissions, setPermissions] = useState<MemberLotteryPermission[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setLoadError("");
    void getLotteries()
      .then((lotteryResponse) => {
        if (!active) return;
        const lotteries = lotteryResponse.data.data?.list || [];
        setPermissions(lotteries.map((lottery) => ({ lottery_id: lottery.id, name: lottery.name, code: lottery.code, can_view: true, can_bet: true, offline_rebate: "0.0000" })));
      })
      .catch((reason) => { if (!active) return; const text=apiErrorMessage(reason,"页面加载失败"); setLoadError(text); message.error(text); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [message]);

  const invalidPassword = useMemo(() => form.password !== "" && form.password === form.username, [form]);

  function updatePermission(lotteryId: number, key: "can_view" | "can_bet", value: boolean) {
    setPermissions((current) => current.map((item) => {
      if (item.lottery_id !== lotteryId) return item;
      if (key === "can_view" && !value) return { ...item, can_view: false, can_bet: false };
      if (key === "can_bet" && value) return { ...item, can_view: true, can_bet: true };
      return { ...item, [key]: value };
    }));
  }

  async function submit() {
    const username = form.username.trim();
    if (!username) return message.warning("请输入账号名");
    if (form.password.length < 6) return message.warning("密码不能少于6位");
    if (invalidPassword) return message.warning("密码不能跟账号相同");
    setSaving(true);
    try {
      const payload = { username, display_name: username, password: form.password, permissions: permissions.map(({ lottery_id, can_view, can_bet }) => ({ lottery_id, can_view, can_bet })) };
      await createAgentMember(payload);
      message.success("会员创建成功");
      navigate("/subordinates");
    } catch (reason) {
      message.error(apiErrorMessage(reason, "创建失败"));
    } finally { setSaving(false); }
  }

  return (
    <section className="subordinate-page subordinate-form-page">
      <div className="subordinate-location">
        <div className="subordinate-path"><strong>位置</strong><DoubleRightOutlined /><span>下级管理</span><DoubleRightOutlined /><span>新增下级</span></div>
        <div className="subordinate-actions"><button type="button" onClick={() => navigate("/subordinates")}>账户列表</button><i /><button className="active" type="button">新增下级</button></div>
      </div>
      {loading ? <div className="subordinate-form-loading">正在加载...</div> : loadError ? <div className="subordinate-form-loading"><div className="subordinate-load-error"><span>{loadError}</span><button type="button" onClick={() => navigate("/subordinates")}>返 回</button></div></div> : <div className="subordinate-form-shell">
        <div className="subordinate-account-form">
          <div className="account-level"><label>新建账号等级</label><strong>会员</strong></div>
          <div className="account-name"><label htmlFor="subordinate-username">账号名</label><input className="ant-input subordinate-input" id="subordinate-username" maxLength={20} autoComplete="off" placeholder="请输入账号名" value={form.username} onChange={(event) => setForm({ ...form, username: event.target.value })} /></div>
          <div className="account-password"><label htmlFor="subordinate-password">密码</label><div><input className="ant-input subordinate-input" id="subordinate-password" type="password" maxLength={20} autoComplete="new-password" placeholder="请输入密码" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} /><span className={`password-warning${invalidPassword ? " invalid" : ""}`}>密码不能跟账号相同，尽量不要使用连续的数字和字母，尽量使用数字、大写字母、小写字母的组合。</span></div></div>
        </div>
        <div className="subordinate-permissions">
          {permissions.length === 0 ? <div className="permission-empty">当前站点暂未分配彩票</div> : permissions.map((permission) => <div className="permission-lottery" key={permission.lottery_id}><label>{permission.name}：</label><div className="permission-switches"><div><span>权限</span><Switch checked={permission.can_view} onChange={(value) => updatePermission(permission.lottery_id, "can_view", value)} /></div><div><span>下注</span><Switch checked={permission.can_bet} disabled={!permission.can_view} onChange={(value) => updatePermission(permission.lottery_id, "can_bet", value)} /></div></div></div>)}
        </div>
        <div className="subordinate-form-actions"><button className="submit" type="button" disabled={saving} onClick={() => void submit()}>{saving ? "提交中" : "提 交"}</button><button className="back" type="button" onClick={() => navigate("/subordinates")}>返 回</button></div>
      </div>}
    </section>
  );
}
