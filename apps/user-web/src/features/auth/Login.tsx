import { App as AntdApp, Button, Input, Modal, Space } from "antd";
import { LockOutlined, PoweroffOutlined, UserOutlined } from "@ant-design/icons";
import { useState } from "react";
import loginLogo from "../../assets/login-logo.svg";
import { apiErrorMessage } from "../../utils/request";
import { getCaptcha, login, verifyCaptcha } from "../../api/auth";

function shuffledDigits() {
  const digits = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
  for (let index = digits.length - 1; index > 0; index -= 1) {
    const swapIndex = Math.floor(Math.random() * (index + 1));
    [digits[index], digits[swapIndex]] = [digits[swapIndex], digits[index]];
  }
  return digits;
}

export function Login({ onLogin }: { onLogin: (name: string) => void }) {
  const { message, modal } = AntdApp.useApp();
  const [name, setName] = useState("");
  const [password, setPassword] = useState("");
  const [captcha, setCaptcha] = useState("");
  const [captchaId, setCaptchaId] = useState("");
  const [captchaImage, setCaptchaImage] = useState("");
  const [showCaptcha, setShowCaptcha] = useState(false);
  const [busy, setBusy] = useState(false);
  const [captchaDigits, setCaptchaDigits] = useState<number[]>([]);

  async function requestCaptcha() {
    setBusy(true);
    try {
      const response = await getCaptcha();
      const payload = response.data.data;
      setCaptchaId(String(payload?.captcha_id || ""));
      setCaptchaImage(String(payload?.image || ""));
      setCaptcha("");
      setCaptchaDigits(shuffledDigits());
      setShowCaptcha(true);
    } catch (reason) {
      message.error(apiErrorMessage(reason, "验证码加载失败"));
    } finally { setBusy(false); }
  }

  async function authenticate() {
    setBusy(true);
    try {
      const response = await login({ username: name, password, captcha, captcha_id: captchaId });
      const token = response.data.data?.token;
      if (!token) {
        message.error("登录失败");
        return;
      }
      localStorage.setItem("user_token", token);
      onLogin(name);
    } catch (reason) {
      message.error(apiErrorMessage(reason, "登录失败"));
    } finally { setBusy(false); }
  }

  async function submitCaptcha() {
    setShowCaptcha(false);
    try {
      const response = await verifyCaptcha({ captcha_id: captchaId, answer: captcha });
      if (!response.data.data?.verified) {
        modal.error({ title: "验证码已失效", okText: "确定", centered: true });
        return;
      }
      await authenticate();
    } catch (reason) {
      modal.error({ title: apiErrorMessage(reason, "验证码已失效"), okText: "确定", centered: true });
    }
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!name) { message.error("请输入登录名"); return; }
    if (!password) { message.error("请输入登录密码"); return; }
    void requestCaptcha();
  }

  return (
    <div className="login">
      <form className="login-panel" onSubmit={submit}>
        <img className="login-logo" src={loginLogo} alt="快排" />
        <Space direction="vertical" size={20} className="login-fields">
          <Input prefix={<UserOutlined />} aria-label="登录名" value={name} onChange={(event) => setName(event.target.value)} />
          <Input type="password" prefix={<LockOutlined />} aria-label="登录密码" value={password} onChange={(event) => setPassword(event.target.value)} />
        </Space>
        <button className="login-submit" aria-label="登录" type="submit" disabled={busy}><PoweroffOutlined /></button>
      </form>
      <Modal title="请输入图片验证码" open={showCaptcha} onCancel={() => setShowCaptcha(false)} footer={<div className="captcha-footer"><Button htmlType="button" type="primary" loading={busy} onClick={submitCaptcha}>登 录</Button></div>} width={520}>
        <div className="captcha-equation"><img className="captcha-image" src={captchaImage} alt="算术验证码" /><b>=</b><strong>{captcha}</strong><Button htmlType="button" type="link" onClick={() => void requestCaptcha()}>换题</Button></div>
        <div className="digit-grid">{captchaDigits.map((digit) => <Button className="captcha-digit" htmlType="button" key={digit} type="dashed" onClick={() => { if (captcha.length < 2) setCaptcha(captcha + digit); }}>{digit}</Button>)}</div>
      </Modal>
    </div>
  );
}
