import { useState } from "react";
import { changePassword } from "../../../api/user";

export function ChangePasswordPage() {
  const [oldPassword, setOldPassword] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [message, setMessage] = useState("");
  const submit = () => {
    setMessage("");
    changePassword({
      old_password: oldPassword,
      password,
      confirm_password: confirm,
    })
      .then(() => {
        setMessage("密码修改成功");
        setOldPassword("");
        setPassword("");
        setConfirm("");
      })
      .catch((error) =>
        setMessage(error instanceof Error ? error.message : "密码修改失败"),
      );
  };
  return (
    <div className="password-page">
      <div className="password-fields">
        <label>
          <span>原密码</span>
          <input
            type="password"
            maxLength={20}
            value={oldPassword}
            onChange={(e) => setOldPassword(e.target.value)}
            placeholder="请输入原密码"
          />
        </label>
        <label className="password-new">
          <span>新密码</span>
          <input
            type="password"
            maxLength={20}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="请输入密码"
          />
          <small>
            1. 新密码不能跟账号和原密码相同
            <br />
            2. 必须是数字和字母组合，至少6位以上
          </small>
        </label>
        <label>
          <span>确认新密码</span>
          <input
            type="password"
            maxLength={20}
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
            placeholder="请确认新密码"
          />
        </label>
      </div>
      <div className="password-forbidden">
        <span>系统禁止不可用密码：</span>
        <span>a12345,ab1234,abc123,a1b2c3,aaa111,123qwe</span>
      </div>
      <button type="button" className="password-save" onClick={submit}>
        保 存
      </button>
      {message && <p className="password-message">{message}</p>}
    </div>
  );
}
