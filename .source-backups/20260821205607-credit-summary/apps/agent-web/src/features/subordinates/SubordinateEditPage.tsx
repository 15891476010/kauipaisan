import { App as AntdApp, Switch } from "antd";
import { DoubleRightOutlined } from "@ant-design/icons";
import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { getAgentMember, updateAgentMember, type AgentMember, type MemberLotteryOdds, type MemberLotteryPermission } from "../../api/user";
import { apiErrorMessage } from "../../utils/request";

type EditState = {
  display_name: string;
  remark: string;
  password: string;
  account_state: "enabled" | "disabled" | "bet_paused";
  credit_balance: string;
  interception_rate: string;
};

const emptyForm: EditState = { display_name: "", remark: "", password: "", account_state: "enabled", credit_balance: "0.00", interception_rate: "0.0000" };
const numericFields = ["min_bet", "odds_limit", "single_bet_limit", "single_item_limit", "odds", "offline_rebate"] as const;
function displayNumber(value: string | number) {
  const number = Number(value);
  return Number.isFinite(number) ? number.toFixed(4).replace(/0+$/, '').replace(/\.$/, '') : String(value);
}
function directRowClass(row: MemberLotteryOdds) {
  if (!row.direct_category) return "odds-detail-row";
  if (["双飞", "对子"].includes(row.name)) return "odds-detail-row direct-play direct-cyan";
  if (["组六", "组三"].includes(row.name)) return "odds-detail-row direct-play direct-yellow";
  return "odds-detail-row direct-play";
}
const oddsNameLabels: Record<string, string> = {
  "百位定位": "口XX", "十位定位": "X口X", "个位定位": "XX口",
  "百十定位": "口口X", "百个定位": "口X口", "十个定位": "X口口",
};

export function SubordinateEditPage({ agentName }: { agentName: string }) {
  const { message } = AntdApp.useApp();
  const navigate = useNavigate();
  const memberId = Number(useParams().id || 0);
  const [member, setMember] = useState<AgentMember | null>(null);
  const [form, setForm] = useState<EditState>(emptyForm);
  const [permissions, setPermissions] = useState<MemberLotteryPermission[]>([]);
  const [odds, setOdds] = useState<MemberLotteryOdds[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState("");
  const [saving, setSaving] = useState(false);
  const [syncOfflineRebate, setSyncOfflineRebate] = useState(false);
  const [categorySyncOfflineRebate, setCategorySyncOfflineRebate] = useState<Record<string, boolean>>({});

  useEffect(() => {
    let active = true;
    if (!Number.isInteger(memberId) || memberId < 1) { setLoadError("会员编号无效"); setLoading(false); return; }
    void getAgentMember(memberId).then((response) => {
      const data = response.data.data;
      if (!active || !data) return;
      setMember(data);
      setForm({
        display_name: data.display_name || "",
        remark: data.remark || "",
        password: "",
        account_state: data.account_state || (data.status === 1 ? "enabled" : "disabled"),
        credit_balance: data.credit_balance || "0.00",
        interception_rate: data.interception_rate || "0.0000",
      });
      setPermissions(data.permissions || []);
      setOdds(data.odds || []);
    }).catch((reason) => {
      if (!active) return;
      setLoadError(apiErrorMessage(reason, "账号资料加载失败"));
    }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [memberId]);

  const groupedOdds = useMemo(() => {
    const firstLotteryId = odds[0]?.lottery_id;
    const categories = new Map<string, MemberLotteryOdds[]>();
    const ordered: Array<{ category: string; rows: MemberLotteryOdds[]; direct: boolean }> = [];
    odds.filter((row) => row.lottery_id === firstLotteryId).forEach((row) => {
      if (row.direct_category) ordered.push({ category: row.category, rows: [row], direct: true });
      else if (categories.has(row.category)) categories.get(row.category)!.push(row);
      else {
        const group = { category: row.category, rows: [row], direct: false };
        categories.set(row.category, group.rows);
        ordered.push(group);
      }
    });
    return ordered;
  }, [odds]);

  function updatePermission(lotteryId: number, key: "can_view" | "can_bet", value: boolean) {
    setPermissions((rows) => rows.map((row) => {
      if (row.lottery_id !== lotteryId) return row;
      if (key === "can_view" && !value) return { ...row, can_view: false, can_bet: false };
      if (key === "can_bet" && value) return { ...row, can_view: true, can_bet: true };
      return { ...row, [key]: value };
    }));
  }

  function updateOfflineRebate(row: MemberLotteryOdds, value: string) {
    setOdds((rows) => rows.map((item) => {
      const shouldSync = syncOfflineRebate
        || (categorySyncOfflineRebate[row.category] && item.category === row.category)
        || (item.category === row.category && item.name === row.name);
      return shouldSync ? { ...item, offline_rebate: value } : item;
    }));
  }

  async function submit() {
    if (!form.display_name.trim()) return message.warning("请输入代号");
    if (form.password && form.password.length < 6) return message.warning("新密码不能少于6位");
    if (form.password && form.password === member?.username) return message.warning("密码不能跟账号相同");
    if (!Number.isFinite(Number(form.credit_balance)) || Number(form.credit_balance) < 0) return message.warning("信用额度必须为非负数字");
    if (!Number.isFinite(Number(form.interception_rate)) || Number(form.interception_rate) < 0 || Number(form.interception_rate) > 100) return message.warning("拦货占成必须在0到100之间");
    if (odds.some((row) => numericFields.some((field) => !Number.isFinite(Number(row[field])) || Number(row[field]) < 0))) return message.warning("赔率和限额必须为非负数字");
    setSaving(true);
    try {
      await updateAgentMember(memberId, {
        ...form,
        permissions: permissions.map(({ lottery_id, can_view, can_bet, offline_rebate }) => ({ lottery_id, can_view, can_bet, offline_rebate })),
        odds: odds.map((row) => ({ lottery_odds_id: row.id, ...Object.fromEntries(numericFields.map((field) => [field, row[field]])) })),
      });
      message.success("账号资料已保存");
      navigate("/subordinates");
    } catch (reason) {
      message.error(apiErrorMessage(reason, "账号资料保存失败"));
    } finally { setSaving(false); }
  }

  const summary = member?.summary;
  const used = Number(member?.used_balance || 0);
  const available = Math.max(0, Number(form.credit_balance || 0) - used).toFixed(2);

  return <section className="subordinate-page subordinate-edit-page">
    <div className="subordinate-location">
      <div className="subordinate-path"><strong>位置</strong><DoubleRightOutlined /><span>下级管理</span><DoubleRightOutlined /><span>修改账号</span></div>
      <div className="subordinate-actions"><button type="button" onClick={() => navigate("/subordinates")}>账户列表</button><i /><button className="active" type="button">修改账号</button></div>
    </div>
    {loading ? <div className="subordinate-edit-state">正在加载账号资料...</div> : loadError || !member ? <div className="subordinate-edit-state error"><span>{loadError || "会员不存在"}</span><button type="button" onClick={() => navigate("/subordinates")}>返 回</button></div> : <div className="subordinate-edit-shell">
      <div className="edit-credit-summary"><strong>{agentName}(代理)</strong><span>总信用额度：</span><b>{displayNumber(summary?.total_credit || 0)}</b><i>（0）</i><span>可分配信用额度：</span><b>{displayNumber(summary?.available_credit || 0)}</b><i>（0）</i><span>已分配信用额度：</span><b>{displayNumber(summary?.allocated_credit || 0)}</b><i>（0）</i></div>

      <section className="edit-account-panel">
        <div className="edit-field readonly"><label>账号名</label><strong>{member.username} - 会员</strong></div>
        <div className="edit-field"><label htmlFor="edit-display-name">代号：</label><input id="edit-display-name" maxLength={40} placeholder="请输入代号" value={form.display_name} onChange={(event) => setForm({ ...form, display_name: event.target.value })} /></div>
        <div className="edit-field"><label htmlFor="edit-remark">备注：</label><input id="edit-remark" maxLength={255} placeholder="请输入备注" value={form.remark} onChange={(event) => setForm({ ...form, remark: event.target.value })} /></div>
        <div className="edit-state-group"><b>账号状态：</b>{[["enabled", "启用"], ["disabled", "停用"], ["bet_paused", "暂停下注"]].map(([value, label]) => <label key={value}><input type="radio" name="account-state" value={value} checked={form.account_state === value} onChange={() => setForm({ ...form, account_state: value as EditState["account_state"] })} />{label}</label>)}</div>
      </section>

      <section className="edit-settings-panel">
        <div className="edit-password"><label htmlFor="edit-password">密码</label><input id="edit-password" type="password" autoComplete="new-password" maxLength={40} placeholder="请输入密码" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} /><small>密码不能跟账号相同，尽量不要使用连续的数字和字母，尽量使用数字、大写字母、小写字母的组合。</small></div>
        <div className="edit-permission-list">{permissions.map((permission) => <div className="edit-permission" key={permission.lottery_id}><b>{permission.name}：</b><label>权限 <Switch size="small" checked={permission.can_view} onChange={(value) => updatePermission(permission.lottery_id, "can_view", value)} /></label></div>)}</div>
      </section>

      <div className="edit-finance-row">
        <div className="finance-section credit-section"><fieldset><legend>额度</legend><div className="credit-line"><div className="finance-input"><input aria-label="额度" id="edit-credit" type="number" min="0" step="0.01" value={form.credit_balance} onChange={(event) => setForm({ ...form, credit_balance: event.target.value })} /></div><span className="credit-current">0</span><p>总信用额度：<b>{displayNumber(form.credit_balance || 0)}</b><i>（0）</i></p><p>可使用信用额度：<b>{displayNumber(available)}</b><i>（0）</i></p><p>已使用信用额度：<b>{displayNumber(member.used_balance)}</b><i>（0）</i></p><button type="button" onClick={() => setForm({ ...form, credit_balance: "0.00" })}>归零</button></div></fieldset></div>
        <div className="finance-section interception-section"><fieldset><legend>拦货占成上限</legend><label className="interception-field"><span>代理实际占成：</span><select value={form.interception_rate} onChange={(event) => setForm({ ...form, interception_rate: event.target.value })}>{Array.from({ length: 101 }, (_, index) => String(index)).map((value) => <option key={value} value={value}>{value}</option>)}</select></label><small><b>注：</b>（设置占成，需要在“设置”中添加拦货金额才生效）。<em>提示：</em>如果庄家先吃满，则不以所设成数来分配，以实际分配到拦货中金额为准。</small></fieldset></div>
        <div className="edit-average-rebate"><h5>平均离线赚水：</h5>{permissions.map((permission) => <span key={permission.lottery_id}>{permission.name === "排列三" ? "体" : "福"}<b>{Number(permission.offline_rebate || 0).toFixed(0)}</b></span>)}<button className="inline-submit submit" type="button" disabled={saving} onClick={() => void submit()}>{saving ? "提交中" : "提 交"}</button><button className="inline-submit" type="button" onClick={() => navigate("/subordinates")}>返 回</button></div>
      </div>

      <section className="member-odds-panel">{groupedOdds.length === 0 ? <div className="member-odds-empty">当前彩种暂无赔率配置</div> : <div className="member-odds-scroll"><table><colgroup><col className="odds-category-col" /><col /><col /><col /><col /><col className="odds-value-col" /><col className="odds-rebate-col" /></colgroup><thead><tr><th>类别</th><th>最小下注</th><th>赔率上限</th><th>单注上限</th><th>单项上限</th><th>赔率</th><th><label>离线赚水(同 <input aria-label="全部离线赚水同步" type="checkbox" checked={syncOfflineRebate} onChange={(event) => setSyncOfflineRebate(event.target.checked)} />)</label></th></tr></thead><tbody>{groupedOdds.map(({ category, rows, direct }) => [!direct && <tr className="odds-category-row" key={`category-${category}`}><td>{category}</td>{Array.from({ length: 5 }, (_, index) => <td key={index} />)}<td><label className="category-sync">同 <input aria-label={`${category}离线赚水同步`} type="checkbox" checked={Boolean(categorySyncOfflineRebate[category])} onChange={(event) => setCategorySyncOfflineRebate((current) => ({ ...current, [category]: event.target.checked }))} /></label></td></tr>, ...rows.map((row) => <tr className={directRowClass(row)} key={row.id}><td>{oddsNameLabels[row.name] || row.name}</td>{numericFields.slice(0, 5).map((field) => <td className="odds-readonly" key={field}>{displayNumber(row[field])}</td>)}<td><span className="rebate-equation">0-</span><select aria-label={`${row.name}-offline_rebate`} value={row.offline_rebate} onChange={(event) => updateOfflineRebate(row, event.target.value)}>{Array.from({ length: 101 }, (_, index) => (index / 1000).toFixed(3)).map((value) => <option key={value} value={value}>{displayNumber(value)}</option>)}</select><span className="rebate-equation">=0</span></td></tr>)])}</tbody></table></div>}</section>
    </div>}
  </section>;
}
