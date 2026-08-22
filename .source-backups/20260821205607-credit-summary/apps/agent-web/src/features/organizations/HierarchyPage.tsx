import { useEffect, useState } from "react";
import { App, Button, Checkbox, Empty, Form, Input, InputNumber, Modal, Select, Space, Table, Tag } from "antd";
import { DeleteOutlined, EditOutlined, PlusOutlined, ReloadOutlined } from "@ant-design/icons";
import { createAgentOrganization, deleteAgentOrganization, getAgentOrganizations, updateAgentOrganization, type AgentOrganizationList, type AgentOrganizationNode } from "../../api/user";
import { apiErrorMessage } from "../../utils/request";
import { InitialCredentials } from "../../components/InitialCredentials";

export function HierarchyPage() {
  const { message, modal } = App.useApp();
  const [data, setData] = useState<AgentOrganizationList | null>(null);
  const [loading, setLoading] = useState(true);
  const [nodeOpen, setNodeOpen] = useState(false);
  const [editing, setEditing] = useState<AgentOrganizationNode | null>(null);
  const [nodeForm] = Form.useForm();

  async function load() {
    setLoading(true);
    try { setData((await getAgentOrganizations()).data.data); }
    catch (error) { message.error(apiErrorMessage(error, "下级信息加载失败")); }
    finally { setLoading(false); }
  }
  useEffect(() => { void load(); }, []);

  const permissionOptions = (data?.catalog.permissions || []).map((item) => ({ label: item.label, value: item.code }));
  const levelLabel = data?.catalog.levels.find((item) => item.value === data.current.next_level)?.label || "下级";
  const availableCredit = Number(data?.current.credit.available_credit || 0);
  const maximumCredit = availableCredit + Number(editing?.credit_limit || 0);
  const siteMaxShareRate = Number(data?.site_max_share_rate || 100);

  function openCreate() {
    setEditing(null);
    nodeForm.setFieldsValue({ username: "", display_name: "", phone: "", password: "", credit_limit: 0, share_rate: 0, max_share_rate: siteMaxShareRate, permissions: permissionOptions.map((item) => item.value), status: 1 });
    setNodeOpen(true);
  }
  function openEdit(row: AgentOrganizationNode) {
    setEditing(row);
    nodeForm.setFieldsValue({ username: row.username || "", display_name: row.display_name || row.name, phone: row.phone || "", password: "", credit_limit: Number(row.credit_limit), share_rate: Number(row.share_rate || 0), max_share_rate: Number(row.max_share_rate || 100), permissions: row.permissions.includes("*") ? permissionOptions.map((item) => item.value) : row.permissions, status: row.status });
    setNodeOpen(true);
  }
  async function saveNode() {
    try {
      const values = await nodeForm.validateFields();
      if (Number(values.credit_limit || 0) > maximumCredit + 0.000001) return void message.warning(`分数不足，当前最多可分配 ${maximumCredit.toFixed(2)} 分`);
      if (Number(values.share_rate || 0) > Number(values.max_share_rate || 0)) return void message.warning("实际占成不能超过最高占成");
      if (Number(values.share_rate || 0) > siteMaxShareRate || Number(values.max_share_rate || 0) > siteMaxShareRate) return void message.warning(`本站点每一级占成不能超过 ${siteMaxShareRate}%`);
      const payload = { ...values, name: String(values.display_name || values.username || "").trim() };
      if (editing) {
        await updateAgentOrganization(editing.id, payload);
        message.success(`${levelLabel}已更新`);
      } else {
        const response = await createAgentOrganization(payload);
        modal.success({ title: `${levelLabel}创建成功`, content: <InitialCredentials value={response.data.data} />, okText: "我已保存", centered: true, width: 480 });
      }
      setNodeOpen(false);
      await load();
    } catch (error) { if (error instanceof Error) message.error(apiErrorMessage(error, "保存失败")); }
  }
  function removeNode(row: AgentOrganizationNode) {
    Modal.confirm({ title: `删除${row.level_label}`, content: `确定删除“${row.display_name || row.name}”吗？只能删除没有下级、会员和子账号的记录。`, okText: "删除", okType: "danger", cancelText: "取消", onOk: async () => { await deleteAgentOrganization(row.id); message.success(`${row.level_label}已删除`); await load(); } });
  }

  const columns = [
    { title: "登录账号", dataIndex: "username", width: 140, render: (value: string) => <b>{value || "-"}</b> },
    { title: "名称", dataIndex: "display_name", render: (value: string, row: AgentOrganizationNode) => value || row.name },
    { title: "层级", dataIndex: "level_label", width: 90, render: (value: string) => <Tag color="blue">{value}</Tag> },
    { title: "分数额度", dataIndex: "credit_limit", align: "right" as const, width: 125 },
    { title: "剩余分数", dataIndex: "balance", align: "right" as const, width: 125 },
    { title: "占成", dataIndex: "share_rate", align: "right" as const, width: 90, render: (value: string) => `${Number(value || 0)}%` },
    { title: "在线状态", dataIndex: "online", width: 90, render: (value: number) => <Tag color={value === 1 ? "green" : "default"}>{value === 1 ? "在线" : "离线"}</Tag> },
    { title: "最后登录", dataIndex: "last_login_at", width: 165, render: (value: string) => value || "-" },
    { title: "状态", dataIndex: "status", width: 80, render: (value: number) => <Tag color={value === 1 ? "green" : "default"}>{value === 1 ? "启用" : "停用"}</Tag> },
    { title: "操作", width: 145, render: (_: unknown, row: AgentOrganizationNode) => <Space><Button type="link" icon={<EditOutlined />} onClick={() => openEdit(row)}>编辑</Button><Button type="link" danger icon={<DeleteOutlined />} onClick={() => removeNode(row)}>删除</Button></Space> },
  ];

  return <section className="hierarchy-page">
    <header className="hierarchy-heading"><div><h2>下级管理</h2><p>{data ? `${data.current.level_label} · ${data.current.name}` : "正在读取当前层级"}，每个下级独立设置账号、额度、占成和权限。</p></div><Button icon={<ReloadOutlined />} onClick={() => void load()}>刷新</Button>{data?.current.next_level && <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>新增{levelLabel}</Button>}</header>
    <div className="hierarchy-stats"><div><span>总分数额度</span><b>{data?.current.credit.total_credit || "0.00"}</b></div><div><span>已分配分数</span><b className="allocated">{data?.current.credit.allocated_credit || "0.00"}</b></div><div><span>剩余可分配</span><b className="available">{data?.current.credit.available_credit || "0.00"}</b></div><div><span>直属下级</span><b>{data?.nodes.length || 0}</b></div><div><span>在线下级</span><b>{data?.nodes.filter((item) => item.online === 1).length || 0}</b></div></div>
    <div className="hierarchy-credit-notice"><span>本站点每级最高占成</span><b>{siteMaxShareRate}%</b><em>每一级给直属下级设置时都不能超过此比例</em></div>
    <div className="hierarchy-panel"><Table<AgentOrganizationNode> rowKey="id" loading={loading} columns={columns} dataSource={data?.nodes || []} scroll={{ x: 1280 }} pagination={false} locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={`暂无直属${levelLabel}`} /> }} /></div>
    <Modal title={editing ? `编辑${levelLabel}` : `新增${levelLabel}`} open={nodeOpen} onCancel={() => setNodeOpen(false)} onOk={() => void saveNode()} okText="保存" cancelText="取消" width={660}>
      <div className="hierarchy-credit-notice"><span>当前剩余可分配</span><b>{availableCredit.toFixed(2)}</b>{editing && <em>当前下级最多可设置为 {maximumCredit.toFixed(2)}</em>}</div>
      <Form form={nodeForm} layout="vertical"><div className="hierarchy-form-grid">
        <Form.Item name="username" label={`${levelLabel}账号`} rules={[{ required: true, message: `请输入${levelLabel}账号` }]}><Input maxLength={80} autoComplete="off" disabled={Boolean(editing)} /></Form.Item>
        <Form.Item name="display_name" label={`${levelLabel}名称`} rules={[{ required: true, message: `请输入${levelLabel}名称` }]}><Input maxLength={120} /></Form.Item>
        <Form.Item name="password" label={editing ? "新密码" : "登录密码"}><Input.Password placeholder={editing ? "留空不修改" : "可留空，系统自动生成"} autoComplete="new-password" /></Form.Item>
        <Form.Item name="phone" label="手机号"><Input maxLength={30} /></Form.Item>
        <Form.Item name="credit_limit" label="分数额度" rules={[{ required: true, message: "请输入分数额度" }]}><InputNumber min={0} max={maximumCredit} precision={2} style={{ width: "100%" }} /></Form.Item>
        <Form.Item name="max_share_rate" label="下级最高占成"><InputNumber min={0} max={siteMaxShareRate} precision={4} addonAfter="%" style={{ width: "100%" }} /></Form.Item>
        <Form.Item name="share_rate" label="下级实际占成"><InputNumber min={0} max={siteMaxShareRate} precision={4} addonAfter="%" style={{ width: "100%" }} /></Form.Item>
        <Form.Item name="status" label="状态"><Select options={[{ value: 1, label: "启用" }, { value: 0, label: "停用" }]} /></Form.Item>
      </div><Form.Item name="permissions" label="功能权限"><Checkbox.Group options={permissionOptions} className="hierarchy-permissions" /></Form.Item></Form>
    </Modal>
  </section>;
}
