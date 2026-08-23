import { App, Button, Input } from "antd";
import { LockOutlined } from "@ant-design/icons";
import { useEffect, useState } from "react";
import { changeAgentPassword, getSecurityPolicy } from "../../api/auth";
import { apiErrorMessage } from "../../utils/request";

export function ForcedPasswordPage({ username, onSuccess }: { username: string; onSuccess: () => void }) {
  const { message } = App.useApp();
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [saving, setSaving] = useState(false);
  const [weakPasswords, setWeakPasswords] = useState<string[]>([]);
  useEffect(() => { getSecurityPolicy().then((response) => setWeakPasswords(response.data?.data?.weak_passwords || [])).catch(() => setWeakPasswords([])); }, []);
  async function submit() {
    if (password !== confirm) return message.warning("两次输入的新密码不一致");
    setSaving(true);
    try {
      await changeAgentPassword({ old_password: "", password, confirm_password: confirm });
      localStorage.setItem("agent_must_change_password", "0");
      message.success("密码修改成功");
      onSuccess();
    } catch (error) { message.error(apiErrorMessage(error, "密码修改失败")); }
    finally { setSaving(false); }
  }
  return <div className="forced-password-page"><section><header><LockOutlined /><div><h2>首次登录，请修改密码</h2><p>当前账号：{username}</p></div></header><div className="forced-password-fields"><label><span>新密码</span><Input.Password value={password} onChange={(event) => setPassword(event.target.value)} placeholder="请输入新密码" maxLength={30} /></label><label><span>确认新密码</span><Input.Password value={confirm} onChange={(event) => setConfirm(event.target.value)} placeholder="请再次输入新密码" maxLength={30} /></label></div><ul><li>新密码不能跟账号和初始密码相同</li><li>必须是数字和字母组合，至少 6 位</li>{weakPasswords.length > 0 && <li>禁止使用 {weakPasswords.join("、")} 等弱密码</li>}</ul><Button type="primary" loading={saving} onClick={() => void submit()}>保存并进入系统</Button></section></div>;
}
