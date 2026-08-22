import { Button } from "antd";
import DOMPurify from "dompurify";
import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";

export type AgreementData = { title: string; content: string };

export const defaultAgreement: AgreementData = {
  title: "责任声明",
  content: `致会员
1. 当您在下注之后，请等待下注后的成功状态信息。
2. 为了避免出现争议，**您必须在下注之后检查“下注状况”。**
3. 任何的投诉必须在开奖之前提出，**本公司不会受理任何开奖之后的投诉。**
4.所有投注项目，公布赔率时出现的任何打字错误或非故意人为失误，本公司保留改正错误和按正确赔率结算投注的权力。
5. 开奖后的投注，将被视为“无效”。所有赔率将不定时浮动，**派彩时的赔率将以下注明细里赔率为准。**
6.敬告有意与本公司博彩之客户，应注意您所在的国家或居住地可能规定网络博彩不合法，若此情况属实，本公司将不接受任何客户因违反当地法律所引起之任何责任。
7.倘若发生黑客入侵、系统故障或资料损坏等情况，我们将以线上交易后的备份资料为最后处理依据；为确保各方利益，请各会员交易后打印资料。
8. 交易之后务必进入下注明细检查，若发生任何异常，**请立即与代理商联系查证。**
9.如遇天灾、停电或其他不可抗力因素导致无法运作时，得中止所有未开奖前的投注。
10.如发生临时性、突发性等特殊情况，本公司有权作出相对应之决定。
11.本公司所有投注皆含本金，请认真了解规则说明。
## 12. 特别提醒

> ① 本公司如果输入开奖结果错误，有权利更正开奖结果，最终以官方最后公布结果为准。
>
> ② 为避免争议，请各会员到第二天早上才开始兑奖，当天晚上兑奖造成的损失由会员自负。`,
};

export const defaultAgentAgreement: AgreementData = {
  title: "代理服务协议",
  content: `1. 用户明确同意本系统的使用由用户个人承担风险。

2. 本系统不作任何类型的担保，不担保服务一定能满足用户的要求，也不担保服务不会受中断；对服务的及时性、安全性、出错发生都不作担保。用户理解并接受，任何通过本系统服务取得的信息资料的可靠性取决于用户自己，用户自己承担所有风险和责任。

3. 本声明的最终解释权归本系统所有。

## 4. 特别提醒

> ① 本公司如果输入开奖结果错误，有权利更正开奖结果，最终以官方最后公布结果为准。
>
> ② 为了避免出现争议，请各会员到第二天早上才开始兑奖。当天晚上兑奖造成的损失由会员自负。`,
};

export function Agreement({ agreement, onAccept, onReject }: { agreement: AgreementData; onAccept: () => void; onReject: () => void }) {
  const isRichText = /<\/?[a-z][^>]*>/i.test(agreement.content);
  const safeHtml = isRichText ? DOMPurify.sanitize(agreement.content, { FORBID_TAGS: ["script", "iframe", "style"], FORBID_ATTR: ["onerror", "onclick", "onload"] }) : "";
  return (
    <section className="agreement-page">
      <div className="agreement-card">
        <h1>{agreement.title}</h1>
        {isRichText ? <div className="agreement-rich-html" dangerouslySetInnerHTML={{ __html: safeHtml }} /> : <div className="agreement-markdown"><ReactMarkdown remarkPlugins={[remarkGfm]}>{agreement.content}</ReactMarkdown></div>}
        <div className="agreement-actions">
          <strong>了解以及同意以上列明的协议</strong>
          <Button htmlType="button" onClick={onReject}>不同意</Button>
          <Button htmlType="button" type="primary" onClick={onAccept}>同意</Button>
        </div>
      </div>
    </section>
  );
}
