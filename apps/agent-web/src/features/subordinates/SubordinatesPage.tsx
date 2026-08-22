import { App as AntdApp, Empty, Pagination } from "antd";
import { DoubleRightOutlined, PlusOutlined, SearchOutlined } from "@ant-design/icons";
import { useCallback, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { getAgentMembers, type AgentMember, type AgentMemberList } from "../../api/user";

const emptyResult: AgentMemberList = { list: [], total: 0, page: 1, page_size: 40, total_credit: "0.00", available_credit: "0.00", allocated_credit: "0.00" };
export function SubordinatesPage({ agentName }: { agentName: string }) {
  const { modal } = AntdApp.useApp();
  const navigate = useNavigate();
  const [filters, setFilters] = useState({ username: "", code: "", status: "1" });
  const [applied, setApplied] = useState(filters);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<AgentMemberList>(emptyResult);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await getAgentMembers({ ...applied, page, page_size: 40 });
      setResult(response.data.data || emptyResult);
    } catch {
      setResult((current) => ({ ...current, list: [], total: 0 }));
    } finally { setLoading(false); }
  }, [applied, page]);

  useEffect(() => { void load(); }, [load]);

  function openCreate() {
    navigate("/subordinates/new");
  }

  function openEdit(member: AgentMember) {
    navigate(`/subordinates/${member.id}/edit`);
  }

  return (
    <section className="subordinate-page">
      <div className="subordinate-location">
        <div className="subordinate-path"><strong>位置</strong><DoubleRightOutlined /><span>下级管理</span><DoubleRightOutlined /><span>账户列表</span></div>
        <div className="subordinate-actions"><button className="active" type="button">账户列表</button><i /><button type="button" onClick={openCreate}>新增下级</button></div>
      </div>
      <div className="subordinate-content">
        <div className="credit-summary"><strong>{agentName}(代理)</strong><span>上级授予额度：</span><b>{result.total_credit}</b><span>当前可用余额：</span><b className="available">{result.available_credit}</b><span>直属会员额度：</span><b className="allocated">{result.allocated_credit}</b></div>
        {Number(result.total_credit || 0) <= 0 && <div className="credit-allocation-warning">上级尚未分配额度，当前不能给会员分配分数</div>}
        <form className="member-filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setApplied({ ...filters }); }}>
          <div className="member-filter-field"><label htmlFor="member-username">用户名：</label><input id="member-username" maxLength={20} value={filters.username} onChange={(event) => setFilters({ ...filters, username: event.target.value })} placeholder="搜索用户名" /></div>
          <div className="member-filter-field"><label htmlFor="member-code">代号：</label><input id="member-code" maxLength={20} value={filters.code} onChange={(event) => setFilters({ ...filters, code: event.target.value })} placeholder="搜索代号" /></div>
          <div className="member-filter-field member-status-field"><label htmlFor="member-status">状态：</label><select id="member-status" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}><option value="">全部</option><option value="1">启用</option><option value="0">停用</option></select></div>
          <button className="member-search" type="submit"><SearchOutlined />搜索</button>
          <button className="member-create" type="button" onClick={openCreate}><PlusOutlined />新增会员</button>
        </form>
        <div className="member-table-wrap">
          <table className="member-table">
            <thead><tr><th>编号</th><th>账号名</th><th>上级</th><th>类型</th><th>占成比例</th><th>信用额度</th><th>最近登录时间</th><th>状态</th><th>内容</th></tr></thead>
            <tbody>{result.list.map((member, index) => <tr key={member.id}><td>{(page - 1) * 40 + index + 1}</td><td><button className="member-link" type="button" onClick={() => openEdit(member)}>{member.username}(会员)</button></td><td><button className="member-link" type="button" onClick={() => modal.info({ title: "上级代理", content: agentName, okText: "关闭" })}>查看</button></td><td>{member.type}</td><td>代理: 0/0</td><td>{member.credit_balance}</td><td>{member.last_login_at || "-"}</td><td>{member.status === 1 ? "启用" : "停用"}</td><td><div className="member-actions"><button className="member-link" type="button" onClick={() => openEdit(member)}>修改</button><button className="member-link" type="button" onClick={() => modal.info({ title: `${member.username} 月报表`, content: "暂无月报数据", okText: "关闭" })}>查看月报表</button><button className="member-link" type="button" onClick={() => modal.info({ title: "会员资料", content: <div className="member-detail"><p>用户名：{member.username}</p><p>代号：{member.display_name}</p><p>信用额度：{member.credit_balance}</p><p>可用余额：{member.available_balance}</p></div>, okText: "关闭" })}>查看</button></div></td></tr>)}</tbody>
          </table>
          {!loading && result.list.length === 0 && <div className="member-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}
        </div>
        <div className="member-pagination"><span>总计：<b>{result.total}</b> 条数据</span><Pagination current={page} pageSize={40} total={result.total} showSizeChanger={false} onChange={setPage} /></div>
      </div>
    </section>
  );
}
