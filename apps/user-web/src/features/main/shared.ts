import type { Lottery } from "../../api/user";
import accountBookIcon from "../../assets/account-book.svg";
import alertIcon from "../../assets/alert.svg";
import fileDoneIcon from "../../assets/file-done.svg";
import importIcon from "../../assets/import.svg";
import keyIcon from "../../assets/key.svg";
import trophyIcon from "../../assets/trophy.svg";
import userIcon from "../../assets/user.svg";

export const nav = [
  { path: "kb", title: "快速录入", icon: importIcon },
  { path: "zh", title: "下注明细", icon: fileDoneIcon },
  { path: "zd", title: "历史账单", icon: accountBookIcon },
  { path: "hyxx", title: "会员资料", icon: userIcon },
  { path: "jg", title: "开奖号码", icon: trophyIcon },
  { path: "gz", title: "规则说明", icon: alertIcon },
  { path: "xgmm", title: "修改密码", icon: keyIcon },
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
  const openTime = lottery?.next_open_time ?? null;
  const cutoffEnabled =
    lottery?.cutoff_enabled === 1 && Boolean(lottery.cutoff_time);
  if (cutoffEnabled) {
    const date = new Date(now);
    const [hours, minutes] = String(lottery?.cutoff_time)
      .split(":")
      .map(Number);
    const cutoff = new Date(
      date.getFullYear(),
      date.getMonth(),
      date.getDate(),
      hours,
      minutes,
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
    return {
      status: locked ? "即将开盘" : "开盘中",
      countdown: `${hh} : ${mm} : ${ss}`,
      locked,
      mask: lottery?.mask_enabled !== 0,
    };
  }
  if (!openTime)
    return {
      status: "时间待定",
      countdown: "-- : -- : --",
      locked: false,
      mask: lottery?.mask_enabled !== 0,
    };
  const target = new Date(openTime.replace(" ", "T")).getTime();
  if (!Number.isFinite(target))
    return {
      status: "时间待定",
      countdown: "-- : -- : --",
      locked: false,
      mask: lottery?.mask_enabled !== 0,
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
  return {
    status,
    countdown: `${hours} : ${minutes} : ${remaining}`,
    locked: beforeOpeningDay || now >= target,
    mask: lottery?.mask_enabled !== 0,
  };
}
