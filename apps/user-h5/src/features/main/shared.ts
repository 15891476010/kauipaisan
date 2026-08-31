import type { Lottery } from "../../api/user";
import accountBookIcon from "../../assets/account-book.svg";
import alertIcon from "../../assets/alert.svg";
import fileDoneIcon from "../../assets/file-done.svg";
import importIcon from "../../assets/import.svg";
import keyIcon from "../../assets/key.svg";
import trophyIcon from "../../assets/trophy.svg";
import userIcon from "../../assets/user.svg";

export const nav = [
  { path: "kb", title: "快录", icon: importIcon },
  { path: "zh", title: "记录", icon: fileDoneIcon },
  { path: "jg", title: "奖号", icon: trophyIcon },
  { path: "xgmm", title: "密码", icon: keyIcon },
  { path: "zd", title: "账单", icon: accountBookIcon },
  { path: "hyxx", title: "会员资料", icon: userIcon },
  { path: "gz", title: "规则说明", icon: alertIcon },
] as const;

export type Announcement = { title: string; content: string };
export type Balances = {
  balance: string;
  total_balance: string;
  credit_balance: string;
  used_balance: string;
  available_balance: string;
};

export function displayAmount(value: unknown) {
  const text = String(value ?? "0");
  if (!text.includes(".")) return text;
  return text.replace(/0+$/, "").replace(/\.$/, "") || "0";
}

export function lotteryTiming(lottery: Lottery | undefined, now: number) {
  const permissionCanBet = lottery?.can_bet !== false;
  const serverCanBet = lottery?.timing_can_bet;
  const serverMask = lottery?.timing_mask;
  const baseMask = serverMask === undefined ? lottery?.mask_enabled !== 0 : serverMask;
  const rules = lottery?.timing_rules || [];
  const clock = new Date(now);
  const currentMinutes = clock.getHours() * 60 + clock.getMinutes();
  const parseMinutes = (value: unknown, fallback: number) => {
    const match = String(value ?? "").match(/^(\d{1,2}):(\d{2})$/);
    if (!match) return fallback;
    const hours = Number(match[1]);
    const mins = Number(match[2]);
    return hours >= 0 && hours <= 23 && mins >= 0 && mins <= 59 ? hours * 60 + mins : fallback;
  };
  const matchingRule = rules.find((rule) => {
    const start = parseMinutes(rule.start_time, 0);
    const end = parseMinutes(rule.end_time, 1439);
    return start === end ? true : start < end ? currentMinutes >= start && currentMinutes < end : currentMinutes >= start || currentMinutes < end;
  });
  if (matchingRule) {
    const canBet = permissionCanBet && matchingRule.allow_bet === 1;
    const end = parseMinutes(matchingRule.end_time, 1439);
    let seconds = (end - currentMinutes) * 60 - clock.getSeconds();
    if (seconds < 0) seconds += 24 * 60 * 60;
    const hh = String(Math.floor(seconds / 3600)).padStart(2, "0");
    const mm = String(Math.floor((seconds % 3600) / 60)).padStart(2, "0");
    const ss = String(seconds % 60).padStart(2, "0");
    return { status: matchingRule.display_text?.trim() || (canBet ? "开盘中" : "即将开盘"), countdown: `${hh} : ${mm} : ${ss}`, locked: !canBet, canBet, mask: matchingRule.mask_enabled === 1, showNextIssue: matchingRule.show_next_issue === 1, headerShowNextIssue: (matchingRule.header_show_next_issue ?? matchingRule.show_next_issue) === 1 };
  }
  const openTime = lottery?.next_open_time ?? null;
  const cutoffEnabled =
    lottery?.cutoff_enabled === 1 && Boolean(lottery.cutoff_time);
  if (cutoffEnabled) {
    const date = new Date(now);
    const [hours, cutoffMinute] = String(lottery?.cutoff_time)
      .split(":")
      .map(Number);
    const cutoff = new Date(
      date.getFullYear(),
      date.getMonth(),
      date.getDate(),
      hours,
      cutoffMinute,
      0,
      0,
    ).getTime();
    const midnight = new Date(
      date.getFullYear(),
      date.getMonth(),
      date.getDate(),
    ).getTime();
    const nextMidnight = midnight + 24 * 60 * 60 * 1000;
    const locked = now >= cutoff;
    const countdownTarget = locked ? nextMidnight : cutoff;
    const seconds = Math.max(0, Math.floor((countdownTarget - now) / 1000));
    const hh = String(Math.floor(seconds / 3600)).padStart(2, "0");
    const mm = String(Math.floor((seconds % 3600) / 60)).padStart(2, "0");
    const ss = String(seconds % 60).padStart(2, "0");
    const canBet = permissionCanBet && (serverCanBet === undefined ? !locked : serverCanBet);
    return {
      status: lottery?.timing_text?.trim() || (locked ? "即将开盘" : "开盘中"),
      countdown: `${hh} : ${mm} : ${ss}`,
      locked: !canBet,
      canBet,
      mask: baseMask && !canBet,
      showNextIssue: lottery?.show_next_issue !== false,
      headerShowNextIssue: lottery?.header_show_next_issue !== false,
    };
  }
  if (!openTime)
    return {
      status: lottery?.timing_text?.trim() || "时间待定",
      countdown: "-- : -- : --",
      locked: !permissionCanBet || serverCanBet === false,
      canBet: permissionCanBet && serverCanBet !== false,
      mask: baseMask && (!permissionCanBet || serverCanBet === false),
      showNextIssue: lottery?.show_next_issue !== false,
      headerShowNextIssue: lottery?.header_show_next_issue !== false,
    };
  const target = new Date(openTime.replace(" ", "T")).getTime();
  if (!Number.isFinite(target))
    return {
      status: lottery?.timing_text?.trim() || "时间待定",
      countdown: "-- : -- : --",
      locked: !permissionCanBet || serverCanBet === false,
      canBet: permissionCanBet && serverCanBet !== false,
      mask: baseMask && (!permissionCanBet || serverCanBet === false),
      showNextIssue: lottery?.show_next_issue !== false,
      headerShowNextIssue: lottery?.header_show_next_issue !== false,
    };
  const openingDay = new Date(target);
  openingDay.setHours(0, 0, 0, 0);
  const beforeOpeningDay = now < openingDay.getTime();
  const status =
    now >= target ? "已封盘" : beforeOpeningDay ? "即将开盘" : "开盘中";
  const countdownTarget = beforeOpeningDay ? openingDay.getTime() : target;
  const seconds = Math.max(0, Math.floor((countdownTarget - now) / 1000));
  const hours = String(Math.floor(seconds / 3600)).padStart(2, "0");
  const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, "0");
  const remaining = String(seconds % 60).padStart(2, "0");
  const canBet = permissionCanBet && (serverCanBet === undefined ? now < target && !beforeOpeningDay : serverCanBet);
  return {
    status: lottery?.timing_text?.trim() || status,
    countdown: `${hours} : ${minutes} : ${remaining}`,
    locked: !canBet,
    canBet,
    mask: baseMask && !canBet,
    showNextIssue: lottery?.show_next_issue !== false,
    headerShowNextIssue: lottery?.header_show_next_issue !== false,
  };
}
