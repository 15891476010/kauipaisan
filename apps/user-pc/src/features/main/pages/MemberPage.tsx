import { useEffect, useState } from "react";
import { Empty } from "antd";
import { getProfile, type Lottery, type UserProfile } from "../../../api/user";

export function MemberPage({
  name,
  selectedLottery,
}: {
  name: string;
  selectedLottery?: Lottery;
}) {
  const [profile, setProfile] = useState<UserProfile>();
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    if (!selectedLottery) {
      setProfile(undefined);
      setLoading(false);
      return;
    }
    setProfile(undefined);
    setLoading(true);
    getProfile({ lottery: selectedLottery.code || selectedLottery.name })
      .then((response) => setProfile(response.data?.data))
      .catch(() => setProfile(undefined))
      .finally(() => setLoading(false));
  }, [selectedLottery?.id]);
  const rows = profile?.odds || [];
  const displayNumber = (value: string | number | undefined) => {
    const number = Number(value);
    return Number.isFinite(number)
      ? number.toFixed(4).replace(/0+$/, "").replace(/\.$/, "")
      : String(value ?? "-");
  };
  const directNames = new Set(["三码定位", "双飞", "对子", "组六", "组三"]);
  const selectedLotteryCode = selectedLottery?.code || rows[0]?.lottery_code;
  const grouped = rows
    .filter(
      (row) => !selectedLotteryCode || row.lottery_code === selectedLotteryCode,
    )
    .reduce<Array<{ category: string; rows: typeof rows; direct: boolean }>>(
      (groups, row) => {
        const direct =
          Boolean(row.direct_category) || directNames.has(row.name);
        const category = String(row.category || row.name || "其他");
        if (direct) {
          groups.push({ category, rows: [row], direct: true });
          return groups;
        }
        const group = groups.find(
          (item) => !item.direct && item.category === category,
        );
        if (group) group.rows.push(row);
        else groups.push({ category, rows: [row], direct: false });
        return groups;
      },
      [],
    );
  const displayName = (name: string) =>
    (
      ({
        百位定位: "口XX",
        十位定位: "X口X",
        个位定位: "XX口",
        百十定位: "口口X",
        百个定位: "口X口",
        十个定位: "X口口",
      }) as Record<string, string>
    )[name] || name;
  const rowClass = (row: (typeof rows)[number]) =>
    row.name === "双飞" || row.name === "对子"
      ? "member-odds-row direct-cyan"
      : row.name === "组六" || row.name === "组三"
        ? "member-odds-row direct-yellow"
        : "member-odds-row";
  return (
    <div className="member-page">
      <div className="member-summary">
        <div>
          <span>账号</span>
          <b>{name}</b>
        </div>
        <div>
          <span>代号</span>
          <b>---</b>
        </div>
        <div>
          <span>信用额度</span>
          <b>{displayNumber(profile?.credit_balance)}</b>
        </div>
      </div>
      <div className="member-odds-panel">
        <div className="member-odds-head">
          <span>类别</span>
          <span>最小下注</span>
          <span>赔率上限</span>
          <span>单注上限</span>
          <span>单项上限</span>
          <span>离线赚水</span>
          <span>赔率</span>
        </div>
        <div className="member-odds-body">
          {grouped.length
            ? grouped.map((group, groupIndex) => (
                <div key={`${group.category}-${groupIndex}`}>
                  {!group.direct && (
                    <div className="member-odds-category">{group.category}</div>
                  )}
                  {group.rows.map((row) => (
                    <div className={rowClass(row)} key={row.id}>
                      <span>{displayName(row.name)}</span>
                      <span>{displayNumber(row.min_bet)}</span>
                      <span>{displayNumber(row.odds_limit)}</span>
                      <span>{displayNumber(row.single_bet_limit)}</span>
                      <span>{displayNumber(row.single_item_limit)}</span>
                      <span>{displayNumber(row.offline_rebate)}</span>
                      <span>{displayNumber(row.odds)}</span>
                    </div>
                  ))}
                </div>
              ))
            : !loading && (
                <div className="member-odds-empty">
                  <Empty
                    image={Empty.PRESENTED_IMAGE_SIMPLE}
                    description="暂无赔率配置"
                  />
                </div>
              )}
          {loading && (
            <div
              className="page-local-loading"
              role="status"
              aria-label="加载中"
            />
          )}
        </div>
      </div>
    </div>
  );
}
