import React, { PureComponent } from "react";

// Readable extraction of the behavior contained in app.bundle.a44facbc.js.
// UI-library components have been replaced with ordinary HTML for reference.
export default class Captcha extends PureComponent {
  verifyCodeList = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];

  state = {
    isLoging: false,
    currentCode: "",
    codeCurrentTime: 0,
    showVerifyCode: false,
    currentTime: Date.now(),
  };

  shuffleCodes = () => {
    this.verifyCodeList = [...this.verifyCodeList].sort(() => Math.random() - 0.5);
  };

  refreshCode = () => {
    this.shuffleCodes();
    this.setState({
      currentCode: "",
      codeCurrentTime: 0,
      currentTime: Date.now(),
    });
  };

  onQuestionCodeLoad = () => {
    this.setState({ codeCurrentTime: this.state.currentTime });
  };

  appendCode = (digit) => {
    if (this.state.currentCode.length < 2) {
      this.setState({ currentCode: this.state.currentCode + digit });
    }
  };

  resetVerifyCode = () => this.setState({ currentCode: "" });

  render() {
    const { currentCode, codeCurrentTime, currentTime } = this.state;

    return (
      <div className={`captcha-panel ${codeCurrentTime > 0 ? "show-img" : "hide-img"}`}>
        {codeCurrentTime === 0 && <span className="loading-icon">...</span>}

        <div className="result-wrapper">
          <span className="question-code-wrapper">
            <img
              src={`../vc/qc.php?time=${currentTime}`}
              onLoad={this.onQuestionCodeLoad}
              alt="captcha question"
            />
          </span>
          <label>=</label>
          <span className="result-verify-code-wrapper" onClick={this.resetVerifyCode}>
            {currentCode}
          </span>
          <span className="change-code-button" onClick={this.refreshCode}>
            换题
          </span>
        </div>

        <div className="code-wrapper">
          <div className="verify-code-wrapper">
            {this.verifyCodeList.map((digit) => (
              <span
                className={`verify-code num-${digit}`}
                key={`code-${digit}`}
                onClick={() => this.appendCode(digit)}
              >
                {digit}
              </span>
            ))}
          </div>
        </div>
      </div>
    );
  }
}

/*
Original surrounding login flow:

1. Username/password only accept A-Z, a-z and 0-9, with a maximum length of 20.
2. Clicking the power icon validates that both fields are present, shuffles 0-9,
   refreshes the image timestamp and opens an Ant Design modal titled
   "请输入图片验证码".
3. The user clicks up to two shuffled digits. Clicking the answer area clears it.
4. "换题" reshuffles the keypad and requests a new ../vc/qc.php?time=<timestamp> image.
5. The modal footer button submits username, password, device type and currentCode.
*/
