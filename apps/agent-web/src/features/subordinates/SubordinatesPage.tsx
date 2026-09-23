import { App as AntdApp, Empty, Pagination, Tag } from "antd";
import { DoubleRightOutlined, PlusOutlined, SearchOutlined } from "@ant-design/icons";
import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { deleteAgentOrganization, getAgentMembers, getAgentOrganizations, type AgentMember, type AgentOrganizationList, type AgentOrganizationMember, type AgentOrganizationNode } from "../../api/user";
import { hasAgentPermission } from "../../routePermissions";

const emptyResult: AgentOrganizationList = {
  current: { id: 0, parent_id: 0, name: "", level: "agent", level_label: "代理", next_level: null, permissions: [], credit: { granted_credit: "0.00", current_available_balance: "0.00", direct_child_credit: "0.00", direct_member_credit: "0.00", unassigned_member_credit: "0.00", unassigned_member_net_score: "0.00", unassigned_member_settlement_change: "0.00", credit_unallocated: false, credit_notice: "", available_credit: "0.00", total_credit: "0.00", allocated_credit: "0.00" } },
  root_organization_id: 0, breadcrumbs: [], site_max_share_rate: "100", nodes: [], members: [], accounts: [], catalog: { levels: [], permissions: [] },
};
const memberPageSize = 40;
type MemberRow = { id: number; username: string; display_name: string; credit_balance: string; available_balance: string; status: number; online?: number; last_login_at?: string | null; last_login_ip?: string | null; last_login_location?: string | null; agent_name?: string | null };

export function SubordinatesPage({ agentName }: { agentName: string }) {
  const { modal } = AntdApp.useApp();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const contextId = Number(searchParams.get("organization_id")) || undefined;
  const view = searchParams.get("view") === "members" ? "members" : "accounts";
  const [data, setData] = useState<AgentOrganizationList>(emptyResult);
  const [filters, setFilters] = useState({ username: "", code: "", status: "" });
  const [applied, setApplied] = useState(filters);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [memberRows, setMemberRows] = useState<AgentMember[]>([]);
  const [memberTotal, setMemberTotal] = useState(0);
  const [memberPage, setMemberPage] = useState(1);
  const [membersLoading, setMembersLoading] = useState(false);
  const [presenceRefresh, setPresenceRefresh] = useState(0);

  const load = useCallback(async (organizationId?: number, silent = false) => {
    if (!silent) setLoading(true);
    try {
      const response = await getAgentOrganizations(organizationId ? { organization_id: organizationId } : undefined);
      setData(response.data.data || emptyResult);
      setPage(1);
    } catch {
      setData((current) => ({ ...current, nodes: [], members: [] }));
    } finally { if (!silent) setLoading(false); }
  }, []);

  useEffect(() => { void load(contextId); }, [load, contextId]);

  useEffect(() => {
    const refresh = () => {
      if (document.visibilityState !== "visible") return;
      void load(contextId, true);
      setPresenceRefresh((value) => value + 1);
    };
    const timer = window.setInterval(refresh, 30_000);
    window.addEventListener("focus", refresh);
    return () => { window.clearInterval(timer); window.removeEventListener("focus", refresh); };
  }, [load, contextId]);

  useEffect(() => {
    if (view !== "members") return;
    let active = true;
    setMembersLoading(true);
    void getAgentMembers({
      username: applied.username || undefined,
      code: applied.code || undefined,
      status: applied.status || undefined,
      organization_id: contextId,
      page: memberPage,
      page_size: memberPageSize,
    }).then((response) => {
      if (!active) return;
      const result = response.data.data;
      setMemberRows(result?.list || []);
      setMemberTotal(Number(result?.total || 0));
    }).catch(() => {
      if (!active) return;
      setMemberRows([]);
      setMemberTotal(0);
    }).finally(() => { if (active) setMembersLoading(false); });
    return () => { active = false; };
  }, [view, applied, contextId, memberPage, presenceRefresh]);

  const isMemberMode = data.current.level === "agent";
  const canManageCurrent = data.current.can_manage !== false;
  const currentPermissions = Array.isArray(data.current.permissions) ? data.current.permissions : null;
  const currentHasPermission = (code: string) => currentPermissions ? currentPermissions.includes("*") || currentPermissions.includes(code) : hasAgentPermission(code);
  const canCreate = canManageCurrent && (isMemberMode ? currentHasPermission("member.create") : Boolean(data.current.next_level) && currentHasPermission("organization.create"));
  const canUpdate = canManageCurrent && (isMemberMode ? currentHasPermission("member.update") : currentHasPermission("organization.update"));
  const canMemberUpdate = canManageCurrent && currentHasPermission("member.update");
  const canDelete = canManageCurrent && !isMemberMode && currentHasPermission("organization.delete");
  const visibleNodes = useMemo(() => data.nodes.filter((row) => {
    const username = String(row.username || "").toLowerCase();
    const name = String(row.display_name || row.name || "").toLowerCase();
    const matchesText = (!applied.username || username.includes(applied.username.toLowerCase())) && (!applied.code || name.includes(applied.code.toLowerCase()));
    return matchesText && (!applied.status || String(row.status) === applied.status);
  }), [data.nodes, applied]);
  const visibleMembers = useMemo(() => (data.members || []).filter((row) => {
    const username = row.username.toLowerCase();
    const name = (row.display_name || "").toLowerCase();
    const matchesText = (!applied.username || username.includes(applied.username.toLowerCase())) && (!applied.code || name.includes(applied.code.toLowerCase()));
    return matchesText && (!applied.status || String(row.status) === applied.status);
  }), [data.members, applied]);
  const pageMembers = visibleMembers.slice((page - 1) * 40, page * 40);
  const levelLabel = data.current.level_label || "下级";
  const childLabel = data.catalog.levels.find((item) => item.value === data.current.next_level)?.label || "下级";

  function switchView(next: "accounts" | "members") {
    if (next === view) return;
    const query = next === "members" ? `?view=members${contextId ? `&organization_id=${contextId}` : ""}` : contextId ? `?organization_id=${contextId}` : "";
    navigate(`/subordinates${query}`);
  }
  function openCreate() { navigate(`/subordinates/new?organization_id=${data.current.id}`); }
  function openEdit(row: AgentOrganizationNode | AgentOrganizationMember) { navigate(`/subordinates/${row.id}/edit?kind=${isMemberMode ? "member" : "organization"}&organization_id=${data.current.id}`); }
  function openMemberEdit(row: MemberRow) { navigate(`/subordinates/${row.id}/edit?kind=member&view=members${contextId ? `&organization_id=${contextId}` : ""}`); }
  function openBranch(id: number) { navigate(`/subordinates?organization_id=${id}${view === "members" ? "&view=members" : ""}`); }
  function applyFilters() { setPage(1); setMemberPage(1); setApplied({ ...filters }); }
  function removeNode(row: AgentOrganizationNode) {
    modal.confirm({
      title: `删除${row.level_label}`,
      content: `确定删除“${row.display_name || row.name}”吗？只能删除没有下级、会员和子账号的记录；该下级剩余分数将退回当前账号。`,
      okText: "删除",
      okButtonProps: { danger: true },
      cancelText: "取消",
      onOk: async () => { await deleteAgentOrganization(row.id); await load(contextId); },
    });
  }

  const memberTable = (rows: MemberRow[], offset: number, update: boolean, onEdit: (row: MemberRow) => void, parent: (row: MemberRow) => string) => <table className="member-table"><thead><tr><th>编号</th><th>账号名</th><th>上级</th><th>类型</th><th>占成比例</th><th>信用额度</th><th>在线状态</th><th>最近登录时间</th><th>登录 IP</th><th>最后登录地点</th><th>状态</th><th>内容</th></tr></thead><tbody>{rows.map((member, index) => <tr key={member.id}><td>{offset + index + 1}</td><td>{update ? <button className="member-link" type="button" onClick={() => onEdit(member)}>{member.username}(会员)</button> : <span>{member.username}(会员)</span>}</td><td><button className="member-link" type="button" onClick={() => modal.info({ title: "上级代理", content: parent(member), okText: "关闭" })}>查看</button></td><td>会员</td><td>代理: 0/0</td><td>{member.credit_balance}</td><td><Tag color={member.online === 1 ? "green" : "default"}>{member.online === 1 ? "在线" : "离线"}</Tag></td><td>{member.last_login_at || "-"}</td><td>{member.last_login_ip || "-"}</td><td>{member.last_login_location || "位置暂不可用"}</td><td>{member.status === 1 ? "启用" : "停用"}</td><td><div className="member-actions">{update && <button className="member-link" type="button" onClick={() => onEdit(member)}>修改</button>}<button className="member-link" type="button" onClick={() => modal.info({ title: `${member.username} 资料`, content: <div className="member-detail"><p>用户名：{member.username}</p><p>代号：{member.display_name}</p><p>上级代理:{parent(member)}</p><p>信用额度：{member.credit_balance}</p><p>可用余额：{member.available_balance}</p><p>在线状态：{member.online === 1 ? "在线" : "离线"}</p><p>登录 IP：{member.last_login_ip || "-"}</p><p>登录位置：{member.last_login_location || "位置暂不可用"}</p></div>, okText: "关闭" })}>查看</button></div></td></tr>)}</tbody></table>;

  return <section className="subordinate-page">
    <div className="subordinate-location">
      <div className="subordinate-path"><strong>位置</strong><DoubleRightOutlined /><span>下级管理</span><DoubleRightOutlined /><span>{view === "members" ? "下级会员" : `${levelLabel}的直属下级`}</span></div>
      <div className="subordinate-actions"><button className={view === "accounts" ? "active" : ""} type="button" onClick={() => switchView("accounts")}>账户列表</button><button className={view === "members" ? "active" : ""} type="button" onClick={() => switchView("members")}>会员列表</button>{view === "accounts" && canCreate && <><i /><button className="subordinate-create" type="button" onClick={openCreate}><PlusOutlined />新增下级</button></>}</div>
    </div>
    <div className="subordinate-content">
      <div className="credit-summary"><strong>{data.current.name || agentName}({levelLabel})</strong><span>上级授予额度：</span><b>{data.current.credit.granted_credit || "0.00"}</b><span>当前可用余额：</span><b className="available">{data.current.credit.current_available_balance || "0.00"}</b><span>直属下级额度：</span><b className="allocated">{data.current.credit.direct_child_credit || "0.00"}</b></div>
      {data.current.credit.credit_unallocated && <div className="credit-allocation-warning">{data.current.credit.credit_notice || "上级尚未分配额度，当前不能给下级分配分数"}</div>}
      {data.breadcrumbs.length > 1 && <div className="subordinate-breadcrumbs">{data.breadcrumbs.map((crumb, index) => <span key={crumb.id}>{index > 0 && <em>/</em>}<button type="button" className={crumb.id === data.current.id ? "current" : ""} onClick={() => openBranch(crumb.id)}>{crumb.level_label} · {crumb.name}</button></span>)}</div>}
      <form className="member-filters" onSubmit={(event) => { event.preventDefault(); applyFilters(); }}>
        <div className="member-filter-field"><label htmlFor="subordinate-username">用户名：</label><input id="subordinate-username" maxLength={40} value={filters.username} onChange={(event) => setFilters({ ...filters, username: event.target.value })} placeholder="搜索用户名" /></div>
        <div className="member-filter-field"><label htmlFor="subordinate-code">名称：</label><input id="subordinate-code" maxLength={40} value={filters.code} onChange={(event) => setFilters({ ...filters, code: event.target.value })} placeholder="搜索名称" /></div>
        <div className="member-filter-field member-status-field"><label htmlFor="subordinate-status">状态：</label><select id="subordinate-status" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}><option value="">全部</option><option value="1">启用</option><option value="0">停用</option></select></div>
        <button className="member-search" type="submit"><SearchOutlined />搜索</button>{view === "accounts" && canCreate && <button className="member-create" type="button" onClick={openCreate}><PlusOutlined />新增下级</button>}
      </form>
      <div className="member-table-wrap">
        {view === "members" ? memberTable(memberRows, (memberPage - 1) * memberPageSize, canMemberUpdate, openMemberEdit, (row) => row.agent_name || "未归属") : isMemberMode ? memberTable(pageMembers, (page - 1) * 40, canUpdate, (member) => openEdit(member as AgentOrganizationMember), () => data.current.name) : <table className="member-table"><thead><tr><th>编号</th><th>登录账号</th><th>下级名称</th><th>层级</th><th>分数额度</th><th>剩余分数</th><th>占成比例</th><th>在线状态</th><th>最近登录时间</th><th>登录 IP</th><th>最后登录地点</th><th>状态</th><th>内容</th></tr></thead><tbody>{visibleNodes.map((row, index) => <tr key={row.id}><td>{index + 1}</td><td>{row.username || "-"}</td><td><button className="member-link" type="button" onClick={() => openBranch(row.id)}>{row.display_name || row.name}<small className="subordinate-child-count">{row.child_count || 0} 个下级</small></button></td><td><Tag color="blue">{row.level_label}</Tag></td><td>{row.credit_limit}</td><td>{row.balance || "0.00"}</td><td>{Number(row.share_rate || 0).toFixed(4)}%</td><td><Tag color={row.online === 1 ? "green" : "default"}>{row.online === 1 ? "在线" : "离线"}</Tag></td><td>{row.last_login_at || "-"}</td><td>{row.last_login_ip || "-"}</td><td>{row.last_login_location || "位置暂不可用"}</td><td>{row.status === 1 ? "启用" : "停用"}</td><td><div className="member-actions">{canUpdate && <button className="member-link" type="button" onClick={() => openEdit(row)}>修改</button>}{canDelete && <button className="member-link member-link-danger" type="button" onClick={() => removeNode(row)}>删除</button>}{!canUpdate && !canDelete && <span>-</span>}</div></td></tr>)}</tbody></table>}
        {!loading && !membersLoading && (view === "members" ? memberRows.length === 0 : isMemberMode ? pageMembers.length === 0 : visibleNodes.length === 0) && <div className="member-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={view === "members" ? "暂无下级会员" : `暂无直属${isMemberMode ? "会员" : childLabel}`} /></div>}
      </div>
      {(view === "members" ? memberTotal > 0 : isMemberMode) && <div className="member-pagination"><span>总计：<b>{view === "members" ? memberTotal : visibleMembers.length}</b> 条数据</span><Pagination current={view === "members" ? memberPage : page} pageSize={memberPageSize} total={view === "members" ? memberTotal : visibleMembers.length} showSizeChanger={false} onChange={view === "members" ? setMemberPage : setPage} /></div>}
    </div>
  </section>;
}
