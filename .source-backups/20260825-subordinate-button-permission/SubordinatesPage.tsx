import { App as AntdApp, Empty, Pagination, Tag } from "antd";
import { DoubleRightOutlined, PlusOutlined, SearchOutlined } from "@ant-design/icons";
import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { getAgentOrganizations, type AgentOrganizationList, type AgentOrganizationMember, type AgentOrganizationNode } from "../../api/user";
import { hasAgentPermission } from "../../routePermissions";

const emptyResult: AgentOrganizationList = {
  current: { id: 0, parent_id: 0, name: "", level: "agent", level_label: "代理", next_level: null, credit: { granted_credit: "0.00", current_available_balance: "0.00", direct_child_credit: "0.00", direct_member_credit: "0.00", unassigned_member_credit: "0.00", unassigned_member_net_score: "0.00", unassigned_member_settlement_change: "0.00", credit_unallocated: false, credit_notice: "", available_credit: "0.00", total_credit: "0.00", allocated_credit: "0.00" } },
  root_organization_id: 0, breadcrumbs: [], site_max_share_rate: "100", nodes: [], members: [], accounts: [], catalog: { levels: [], permissions: [] },
};

export function SubordinatesPage({ agentName }: { agentName: string }) {
  const { modal } = AntdApp.useApp();
  const navigate = useNavigate();
  const [data, setData] = useState<AgentOrganizationList>(emptyResult);
  const [filters, setFilters] = useState({ username: "", code: "", status: "1" });
  const [applied, setApplied] = useState(filters);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async (organizationId?: number) => {
    setLoading(true);
    try {
      const response = await getAgentOrganizations(organizationId ? { organization_id: organizationId } : undefined);
      setData(response.data.data || emptyResult);
      setPage(1);
    } catch {
      setData((current) => ({ ...current, nodes: [], members: [] }));
    } finally { setLoading(false); }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const isMemberMode = data.current.level === "agent";
  const canManageCurrent = data.current.can_manage !== false;
  const canCreate = canManageCurrent && (isMemberMode ? hasAgentPermission("member.create") : Boolean(data.current.next_level) && hasAgentPermission("organization.create"));
  const canUpdate = canManageCurrent && (isMemberMode ? hasAgentPermission("member.update") : hasAgentPermission("organization.update"));
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

  function openCreate() { navigate("/subordinates/new"); }
  function openEdit(row: AgentOrganizationNode | AgentOrganizationMember) { navigate(`/subordinates/${row.id}/edit?kind=${isMemberMode ? "member" : "organization"}`); }

  return <section className="subordinate-page">
    <div className="subordinate-location">
      <div className="subordinate-path"><strong>位置</strong><DoubleRightOutlined /><span>下级管理</span><DoubleRightOutlined /><span>{levelLabel}的直属下级</span></div>
      <div className="subordinate-actions"><button className="active" type="button">账户列表</button>{canCreate && <><i /><button type="button" onClick={openCreate}><PlusOutlined />新增下级</button></>}</div>
    </div>
    <div className="subordinate-content">
      <div className="credit-summary"><strong>{data.current.name || agentName}({levelLabel})</strong><span>上级授予额度：</span><b>{data.current.credit.granted_credit || "0.00"}</b><span>当前可用余额：</span><b className="available">{data.current.credit.current_available_balance || "0.00"}</b><span>直属下级额度：</span><b className="allocated">{data.current.credit.direct_child_credit || "0.00"}</b></div>
      {data.current.credit.credit_unallocated && <div className="credit-allocation-warning">{data.current.credit.credit_notice || "上级尚未分配额度，当前不能给下级分配分数"}</div>}
      {data.breadcrumbs.length > 1 && <div className="subordinate-breadcrumbs">{data.breadcrumbs.map((crumb, index) => <span key={crumb.id}>{index > 0 && <em>/</em>}<button type="button" className={crumb.id === data.current.id ? "current" : ""} onClick={() => void load(crumb.id)}>{crumb.level_label} · {crumb.name}</button></span>)}</div>}
      <form className="member-filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setApplied({ ...filters }); }}>
        <div className="member-filter-field"><label htmlFor="subordinate-username">用户名：</label><input id="subordinate-username" maxLength={40} value={filters.username} onChange={(event) => setFilters({ ...filters, username: event.target.value })} placeholder="搜索用户名" /></div>
        <div className="member-filter-field"><label htmlFor="subordinate-code">名称：</label><input id="subordinate-code" maxLength={40} value={filters.code} onChange={(event) => setFilters({ ...filters, code: event.target.value })} placeholder="搜索名称" /></div>
        <div className="member-filter-field member-status-field"><label htmlFor="subordinate-status">状态：</label><select id="subordinate-status" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}><option value="">全部</option><option value="1">启用</option><option value="0">停用</option></select></div>
        <button className="member-search" type="submit"><SearchOutlined />搜索</button>{canCreate && <button className="member-create" type="button" onClick={openCreate}><PlusOutlined />新增下级</button>}
      </form>
      <div className="member-table-wrap">
        {isMemberMode ? <table className="member-table"><thead><tr><th>编号</th><th>账号名</th><th>上级</th><th>类型</th><th>占成比例</th><th>信用额度</th><th>最近登录时间</th><th>最后登录地点</th><th>状态</th><th>内容</th></tr></thead><tbody>{pageMembers.map((member, index) => <tr key={member.id}><td>{(page - 1) * 40 + index + 1}</td><td>{canUpdate ? <button className="member-link" type="button" onClick={() => openEdit(member)}>{member.username}(会员)</button> : <span>{member.username}(会员)</span>}</td><td><button className="member-link" type="button" onClick={() => modal.info({ title: "上级代理", content: data.current.name, okText: "关闭" })}>查看</button></td><td>会员</td><td>代理: 0/0</td><td>{member.credit_balance}</td><td>{member.last_login_at || "-"}</td><td>{member.last_login_location || "-"}</td><td>{member.status === 1 ? "启用" : "停用"}</td><td><div className="member-actions">{canUpdate && <button className="member-link" type="button" onClick={() => openEdit(member)}>修改</button>}<button className="member-link" type="button" onClick={() => modal.info({ title: `${member.username} 资料`, content: <div className="member-detail"><p>用户名：{member.username}</p><p>代号：{member.display_name}</p><p>信用额度：{member.credit_balance}</p><p>可用余额：{member.available_balance}</p></div>, okText: "关闭" })}>查看</button></div></td></tr>)}</tbody></table> : <table className="member-table"><thead><tr><th>编号</th><th>登录账号</th><th>下级名称</th><th>层级</th><th>分数额度</th><th>剩余分数</th><th>占成比例</th><th>最近登录时间</th><th>最后登录地点</th><th>状态</th><th>内容</th></tr></thead><tbody>{visibleNodes.map((row, index) => <tr key={row.id}><td>{index + 1}</td><td>{row.username || "-"}</td><td><button className="member-link" type="button" onClick={() => void load(row.id)}>{row.display_name || row.name}<small className="subordinate-child-count">{row.child_count || 0} 个下级</small></button></td><td><Tag color="blue">{row.level_label}</Tag></td><td>{row.credit_limit}</td><td>{row.balance || "0.00"}</td><td>{Number(row.share_rate || 0).toFixed(4)}%</td><td>{row.last_login_at || "-"}</td><td>{row.last_login_location || "-"}</td><td>{row.status === 1 ? "启用" : "停用"}</td><td>{canUpdate ? <button className="member-link" type="button" onClick={() => openEdit(row)}>修改</button> : <span>-</span>}</td></tr>)}</tbody></table>}
        {!loading && (isMemberMode ? pageMembers.length === 0 : visibleNodes.length === 0) && <div className="member-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={`暂无直属${isMemberMode ? "会员" : childLabel}`} /></div>}
      </div>
      {isMemberMode && <div className="member-pagination"><span>总计：<b>{visibleMembers.length}</b> 条数据</span><Pagination current={page} pageSize={40} total={visibleMembers.length} showSizeChanger={false} onChange={setPage} /></div>}
    </div>
  </section>;
}
