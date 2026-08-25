import { App as AntdApp, Switch } from "antd";
import { DoubleRightOutlined } from "@ant-design/icons";
import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { createAgentMember, createAgentOrganization, getAgentOrganizations, getLotteries, type AgentOrganizationList, type MemberLotteryPermission } from "../../api/user";
import { apiErrorMessage } from "../../utils/request";
import { InitialCredentials } from "../../components/InitialCredentials";
import { hasAgentPermission } from "../../routePermissions";

type FormState = { username: string; display_name: string; password: string; credit_limit: number; share_rate: number; max_share_rate: number; status: number };

export function SubordinateFormPage() {
  const { message, modal } = AntdApp.useApp();
  const navigate = useNavigate();
  const [form, setForm] = useState<FormState>({ username: "", display_name: "", password: "", credit_limit: 0, share_rate: 0, max_share_rate: 100, status: 1 });
  const [organizationData, setOrganizationData] = useState<AgentOrganizationList | null>(null);
  const [routePermissionCodes, setRoutePermissionCodes] = useState<string[]>([]);
  const [permissions, setPermissions] = useState<MemberLotteryPermission[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setLoadError("");
    void getAgentOrganizations()
      .then((organizationResponse) => {
        if (!active) return;
        const value = organizationResponse.data.data;
        setOrganizationData(value);
        setRoutePermissionCodes(value.catalog.permissions.map((item) => item.code));
        setForm((current) => ({ ...current, max_share_rate: Number(value.site_max_share_rate || 100) }));
        if (value.current.level !== "agent") return null;
        return getLotteries();
      })
      .then((lotteryResponse) => {
        if (!active || !lotteryResponse) return;
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
    if (form.password !== "" && form.password.length < 6) return message.warning("密码不能少于6位");
    if (invalidPassword) return message.warning("密码不能跟账号相同");
    const requiredPermission = organizationData?.current.level === "agent" ? "member.create" : "organization.create";
    if (!hasAgentPermission(requiredPermission)) return message.error("当前未分配新增下级权限");
    setSaving(true);
    try {
      if (organizationData?.current.level !== "agent") {
        const childLabel = organizationData?.catalog.levels.find((item) => item.value === organizationData.current.next_level)?.label || "下级";
        if (!form.display_name.trim()) return message.warning(`请输入${childLabel}名称`);
        if (form.share_rate > form.max_share_rate) return message.warning("实际占成不能超过最高占成");
        const response = await createAgentOrganization({ username, display_name: form.display_name.trim(), name: form.display_name.trim(), password: form.password, credit_limit: form.credit_limit, share_rate: form.share_rate, max_share_rate: form.max_share_rate, permissions: routePermissionCodes, status: form.status });
        modal.success({ title: `${childLabel}创建成功`, content: <InitialCredentials value={response.data.data} />, okText: "我已保存", centered: true, width: 480, onOk: () => navigate("/subordinates") });
      } else {
        const payload = { username, display_name: username, password: form.password, permissions: permissions.map(({ lottery_id, can_view, can_bet }) => ({ lottery_id, can_view, can_bet })) };
        const response = await createAgentMember(payload);
        modal.success({ title: "下级创建成功", content: <InitialCredentials value={response.data.data} />, okText: "我已保存", centered: true, width: 480, onOk: () => navigate("/subordinates") });
      }
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
          <div className="account-level"><label>新建账号等级</label><strong>{organizationData?.current.level === "agent" ? "会员" : organizationData?.catalog.levels.find((item) => item.value === organizationData?.current.next_level)?.label || "下级"}</strong></div>
          <div className="account-name"><label htmlFor="subordinate-username">账号名</label><input className="ant-input subordinate-input" id="subordinate-username" maxLength={40} autoComplete="off" placeholder="请输入账号名" value={form.username} onChange={(event) => setForm({ ...form, username: event.target.value })} /></div>
          {organizationData?.current.level !== "agent" && <div className="account-name"><label htmlFor="subordinate-display-name">名称</label><input className="ant-input subordinate-input" id="subordinate-display-name" maxLength={120} placeholder="请输入下级名称" value={form.display_name} onChange={(event) => setForm({ ...form, display_name: event.target.value })} /></div>}
          <div className="account-password"><label htmlFor="subordinate-password">密码</label><div><input className="ant-input subordinate-input" id="subordinate-password" type="password" maxLength={20} autoComplete="new-password" placeholder="可留空，系统将自动生成" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} /><span className={`password-warning${invalidPassword ? " invalid" : ""}`}>密码可留空自动生成；填写时必须为数字和字母组合，至少 6 位，且不能跟账号相同。</span></div></div>
          {organizationData?.current.level !== "agent" && <><div className="account-name"><label htmlFor="subordinate-credit">分数额度</label><input className="ant-input subordinate-input" id="subordinate-credit" type="number" min="0" value={form.credit_limit} onChange={(event) => setForm({ ...form, credit_limit: Number(event.target.value) })} /></div><div className="account-name"><label htmlFor="subordinate-share">实际占成</label><input className="ant-input subordinate-input" id="subordinate-share" type="number" min="0" max={form.max_share_rate} step="0.0001" value={form.share_rate} onChange={(event) => setForm({ ...form, share_rate: Number(event.target.value) })} /></div><div className="account-name"><label htmlFor="subordinate-max-share">最高占成</label><input className="ant-input subordinate-input" id="subordinate-max-share" type="number" min="0" max={organizationData?.site_max_share_rate || 100} step="0.0001" value={form.max_share_rate} onChange={(event) => setForm({ ...form, max_share_rate: Number(event.target.value) })} /></div></>}
        </div>
        <div className="subordinate-permissions">
          {organizationData?.current.level === "agent" ? (permissions.length === 0 ? <div className="permission-empty">当前站点暂未分配彩票</div> : permissions.map((permission) => <div className="permission-lottery" key={permission.lottery_id}><label>{permission.name}：</label><div className="permission-switches"><div><span>权限</span><Switch checked={permission.can_view} onChange={(value) => updatePermission(permission.lottery_id, "can_view", value)} /></div><div><span>下注</span><Switch checked={permission.can_bet} disabled={!permission.can_view} onChange={(value) => updatePermission(permission.lottery_id, "can_bet", value)} /></div></div></div>)) : <div className="permission-empty">路由权限由 SaaS 按层级统一配置，无需对单独管理员设置。</div>}
        </div>
        <div className="subordinate-form-actions"><button className="submit" type="button" disabled={saving} onClick={() => void submit()}>{saving ? "提交中" : "提 交"}</button><button className="back" type="button" onClick={() => navigate("/subordinates")}>返 回</button></div>
      </div>}
    </section>
  );
}
