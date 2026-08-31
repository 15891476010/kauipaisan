import { useState } from "react";
import { changePassword } from "../../../api/user";

export function ChangePasswordPage({ forced = false, onPasswordChanged }: { forced?: boolean; onPasswordChanged?: () => void }) {
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
        localStorage.setItem("user_must_change_password", "0");
        onPasswordChanged?.();
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
        <div className="password-field">
          <label htmlFor="old-password">原密码：</label>
          <input
            id="old-password"
            type="password"
            maxLength={20}
            value={oldPassword}
            onChange={(e) => setOldPassword(e.target.value)}
            placeholder="请输入原密码"
          />
        </div>
        <div className="password-field">
          <label htmlFor="new-password">新密码：</label>
          <input
            id="new-password"
            type="password"
            maxLength={20}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="请输入密码"
          />
        </div>
        <div className="password-field">
          <label htmlFor="confirm-password">确认新密码：</label>
          <input
            id="confirm-password"
            type="password"
            maxLength={20}
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
            placeholder="请确认新密码"
          />
        </div>
        <p>1. 必须是至少3个大小写字母与至少3个数字的组合，不能包含任何字符</p>
        <p>2. 相连5位及以上的连续数字或字母会降低强度，如密码中包含：12345, 65432, Abcde</p>
      </div>
      <button type="button" className="password-save" onClick={submit}>
        提 交
      </button>
      {message && <p className="password-message">{message}</p>}
      {forced && <p className="password-message">首次登录必须先修改密码，修改完成后才能进入系统。</p>}
    </div>
  );
}
