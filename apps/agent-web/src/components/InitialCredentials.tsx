import { App, Button } from "antd";
import { CopyOutlined } from "@ant-design/icons";

export type InitialCredential = { username: string; initial_password: string };

export function InitialCredentials({ value }: { value: InitialCredential }) {
  const { message } = App.useApp();
  const text = `账号：${value.username}\n密码：${value.initial_password}`;
  async function copy() {
    try {
      await navigator.clipboard.writeText(text);
      message.success("账号和密码已复制");
    } catch { message.error("复制失败，请手动复制"); }
  }
  return <div className="initial-credentials"><p>账号创建成功，请立即保存以下登录信息。初始密码关闭后将不再显示。</p><dl><div><dt>用户名</dt><dd>{value.username}</dd></div><div><dt>初始密码</dt><dd>{value.initial_password}</dd></div></dl><Button type="primary" icon={<CopyOutlined />} onClick={() => void copy()}>复制账号和密码</Button><small>该账号首次登录并同意责任声明后，必须修改密码才能进入系统。</small></div>;
}
