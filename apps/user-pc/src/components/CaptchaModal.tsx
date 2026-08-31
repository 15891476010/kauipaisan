import "./CaptchaModal.css";

type CaptchaModalProps = {
  value: string;
  busy: boolean;
  onChange: (value: string) => void;
  onSubmit: () => void;
};

export function CaptchaModal({ value, busy, onChange, onSubmit }: CaptchaModalProps) {
  return (
    <div className="captcha-modal" role="dialog" aria-label="请输入图片验证码">
      <div className="captcha-card">
        <h2>请输入图片验证码</h2>
        <div className="captcha-equation">
          <span className="captcha-image">8 + 3</span><b>=</b>
          <strong>{value || ""}</strong>
          <button type="button" onClick={() => onChange("")}>换题</button>
        </div>
        <div className="digit-grid">
          {[0, 1, 2, 3, 4, 5, 6, 7, 8, 9].map((digit) => (
            <button key={digit} type="button" onClick={() => onChange(value + digit)}>{digit}</button>
          ))}
        </div>
        <button className="captcha-submit" type="button" disabled={busy} onClick={onSubmit}>登 录</button>
      </div>
    </div>
  );
}
