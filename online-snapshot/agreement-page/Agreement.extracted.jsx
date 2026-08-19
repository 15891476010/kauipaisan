import React, { PureComponent } from "react";

// Readable extraction of the responsibility-statement component found in
// app.bundle.a44facbc.js. The original handlers are injected as props here so
// this component can be reused independently.
export default class Agreement extends PureComponent {
  render() {
    const { onReject, onAccept } = this.props;

    return (
      <section className="agreement-page">
        <div>
          <div className="announcement-header">责任声明</div>
          <p>致会员</p>
          <p>1.当您在下注之后，请等待下注后的成功状态信息。</p>
          <p>
            2.为了避免出现争议，
            <span>您必须在下注之后检查 “ 下注状况”。</span>
          </p>
          <p>
            3.任何的投诉必须在开奖之前提出，
            <span>本公司不会受理任何开奖之后的投诉。</span>
          </p>
          <p>
            4.所有投注项目，公布赔率时出现的任何打字错误或非故意人为失误，本公司保留改正错误和按正确赔率结算投注的权力。
          </p>
          <p>
            5.开奖后的投注，将被视为“ 无效 ”。所有赔率将不定时浮动，
            <span>派彩时的赔率将以下注明细里赔率为准。</span>
          </p>
          <p>
            6.敬告有意与本公司博彩之客户，应注意您所在的国家或居住地可能规定网络博彩不合法，若此情况属实，本公司将不接受任何客户因违反当地博彩或赌博法令所引起之任何责任。
          </p>
          <p>
            7.倘若发生遭黑客入侵破坏行为或不可抗拒之灾害导致系统故障或资料损坏，资料丢失等情况，我们将以本公司线上交易之后备资料为最后处理依据；为确保各方真实利益，请各会员交易后打印资料，本公司才接受投诉及处理。
          </p>
          <p>
            8.为避免纠纷，各会员在交易之后，务必进入下注明细检查，若发生任何异常，
            <span>请立即与代理商联系查证，</span>
            否则交易会员必须同意，一切交易历史将以本公司资料库中资料为准，不得异议。
          </p>
          <p>
            9.如本公司机房遇天灾，停电或不可抗力之因素,导致无法运作时,得中止所有未开奖前之投注,在本公司中止下注前,会员所有投注仍属有效,不得要求取消或延迟交收,以及不得有任何异议。
          </p>
          <p>
            10.如发生临时性、突发性等特殊情况,本公司有权作出相对应之决定,不得异议。
          </p>
          <p>11.本公司所有投注皆含本金,请认真了解规则说明。</p>

          <div className="special-warn">
            <p>12.特别提醒</p>
            <span>
              ① 本公司如果输入开奖结果错误，有权利更正开奖结果，最终以官方最后公布结果来结账，不得异议.
            </span>
            <span>
              ② 为了避免出现争议，请各会员到第二天早上才开始兑奖。不要当天晚上知道结果后，马上就兑奖给客人，出现当天晚上兑奖造成的损失，会员自负，不得异议。
            </span>
          </div>

          <div className="action-wrapper">
            <label>了解以及同意以上列明的协议</label>
            <button type="button" onClick={onReject}>不同意</button>
            <button type="button" onClick={onAccept}>同意</button>
          </div>
        </div>
      </section>
    );
  }
}

/*
Original handler mapping:

- 不同意: onClick = Nt
  Nt reads the apiMbKey session value, requests logout with
  { a: "mb.lg", m: "mo", ak }, clears the member session and reloads.

- 同意: onClick = () => st(false)
  st(false) dispatches SET_ANNOUNCEMENT_VISIBLE with false, hiding this page and
  revealing the authenticated main interface. It does not send a separate
  agreement API request in the downloaded client bundle.
*/
