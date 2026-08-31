import { useEffect, useState } from "react";
import { App as AntdApp, DatePicker, Empty } from "antd";
import zhCN from "antd/es/date-picker/locale/zh_CN";
import dayjs from "dayjs";
import { SearchOutlined } from "@ant-design/icons";
import { apiErrorMessage } from "../../../utils/request";
import { getStopDrops, type StopDrop } from "../../../api/user";

export function StopDropPage() {
  const { message } = AntdApp.useApp();
  const categories = [
    "所有",
    "一码定位",
    "口XX",
    "X口X",
    "XX口",
    "二码定位",
    "口口X",
    "口X口",
    "X口口",
    "直选",
    "独胆",
    "双飞",
    "组选",
    "组三多码",
    "组三二码",
    "组三三码",
    "组三四码",
    "组三五码",
    "组三六码",
    "组三七码",
    "组三八码",
    "组三九码",
    "组三全包",
    "组六多码",
    "组六四码",
    "组六五码",
    "组六六码",
    "组六七码",
    "组六八码",
    "组六九码",
    "组六全包",
    "复式多码",
    "复式三码",
    "复式四码",
    "复式五码",
    "复式六码",
    "复式七码",
    "复式八码",
    "复式九码",
    "组三胆拖",
    "1码拖2",
    "1码拖3",
    "1码拖4",
    "1码拖5",
    "1码拖6",
    "1码拖7",
    "1码拖8",
    "1码拖9",
    "组六胆拖",
    "1码拖2",
    "1码拖3",
    "1码拖4",
    "1码拖5",
    "1码拖6",
    "1码拖7",
    "1码拖8",
    "1码拖9",
    "跨度",
    "跨度0",
    "跨度1",
    "跨度2",
    "跨度3",
    "跨度4",
    "跨度5",
    "跨度6",
    "跨度7",
    "跨度8",
    "跨度9",
    "和值",
    "和值0-27",
    "和值1-26",
    "和值2-25",
    "和值3-24",
    "和值4-23",
    "和值5-22",
    "和值6-21",
    "和值7-20",
    "和值8-19",
    "和值9-18",
    "和值10-17",
    "和值11-16",
    "和值12-15",
    "和值13-14",
    "大小单双",
    "豹子全包",
    "组三沾边赖",
    "三赖一码",
    "三赖二码",
    "三赖三码",
    "三赖四码",
    "三赖五码",
    "三赖六码",
    "三赖七码",
    "组六沾边赖",
    "六赖一码",
    "六赖二码",
    "六赖三码",
    "六赖四码",
    "六赖五码",
    "六赖六码",
    "六赖七码",
    "对子全包",
    "组六2胆拖",
    "组六2胆拖二码",
    "组六2胆拖三码",
    "组六2胆拖四码",
    "组六2胆拖五码",
    "组六2胆拖六码",
    "组六2胆拖七码",
    "组六2胆拖八码",
    "单选全胆拖",
    "单选全胆拖二码",
    "单选全胆拖三码",
    "单选全胆拖四码",
    "单选全胆拖五码",
    "单选全胆拖六码",
    "单选全胆拖七码",
    "单选全胆拖八码",
  ];
  const displayNumber = (row: StopDrop) => {
    if (!row.play_type.includes("双飞") && !String(row.source_text || "").includes("对子")) return row.number_text;
    return row.number_text.replace(/^0(?=\d{2}(?:飞)?$)/, "").replace(/飞$/, "");
  };
  const [number, setNumber] = useState("");
  const [type, setType] = useState("all");
  const [lottery, setLottery] = useState("all");
  const [category, setCategory] = useState("所有");
  const [date, setDate] = useState(() => dayjs().format("YYYY-MM-DD"));
  const [sort, setSort] = useState("desc");
  const [rows, setRows] = useState<StopDrop[]>([]);
  const [loading, setLoading] = useState(false);
  const load = (sortOverride = sort) => {
    setLoading(true);
    getStopDrops({
      number,
      type,
      lottery,
      category,
      from: date,
      to: date,
      sort: sortOverride,
      page: 1,
      page_size: 50,
    })
      .then((response) => {
        setRows(response.data?.data?.list || []);
      })
      .catch((error) => {
        setRows([]);
        message.error(apiErrorMessage(error, "停押降水加载失败"));
      })
      .finally(() => setLoading(false));
  };
  useEffect(() => {
    load();
  }, []);
  return (
    <div className="stop-drop-panel">
      <div className="stop-filter">
        <label className="stop-search-field">
          <span>查号码</span>
          <input
            value={number}
            onChange={(e) => setNumber(e.target.value)}
            placeholder="查号码"
          />
        </label>
        <label className="stop-select-field">
          <span>类型</span>
          <select value={type} onChange={(e) => setType(e.target.value)}>
            <option value="all">所有</option>
            <option value="stop">仅停押</option>
            <option value="drop">仅降水</option>
          </select>
        </label>
        <label className="stop-select-field">
          <span>彩种</span>
          <select value={lottery} onChange={(e) => setLottery(e.target.value)}>
            <option value="all">所有</option>
            <option value="体">体</option>
            <option value="福">福</option>
          </select>
        </label>
        <label className="stop-category-field">
          <span>分类</span>
          <select
            value={category}
            onChange={(e) => setCategory(e.target.value)}
          >
            {categories.map((item) => (
              <option key={item}>{item}</option>
            ))}
          </select>
        </label>
        <label className="stop-date-field">
          <span>投注日期</span>
          <DatePicker
            locale={zhCN}
            allowClear={false}
            value={dayjs(date)}
            format="YYYY-MM-DD"
            onChange={(value) => value && setDate(value.format("YYYY-MM-DD"))}
          />
        </label>
        <button
          type="button"
          className="stop-search-button"
          onClick={() => load()}
          disabled={loading}
        >
          <SearchOutlined /> 搜索
        </button>
      </div>
      <div className="stop-sort">
        <strong>停押和降水</strong>
        <span>按下注时间排序:</span>
        <label>
          <input
            type="radio"
            name="stop-sort"
            checked={sort === "desc"}
            onChange={() => {
              setSort("desc");
              load("desc");
            }}
          />{" "}
          倒序
        </label>
        <label>
          <input
            type="radio"
            name="stop-sort"
            checked={sort === "asc"}
            onChange={() => {
              setSort("asc");
              load("asc");
            }}
          />{" "}
          正序
        </label>
      </div>
      <div className="stop-table">
        <div className="stop-head">
          <span>编号</span>
          <span>期号</span>
          <span>下单时间</span>
          <span>号码</span>
          <span>应打/实打/停押</span>
          <span>原水/实水/降水</span>
          <span>查看文本</span>
        </div>
        {rows.length
          ? rows.map((row) => (
              <div className="stop-row" key={row.id}>
                <span>{row.id}</span>
                <span>{row.issue_no}</span>
                <span>{row.placed_at}</span>
                <span>{displayNumber(row)}</span>
                <span>
                  {row.original_amount}/{row.actual_amount}/{row.stop_amount}
                </span>
                <span>
                  {row.original_odds || "-"}/{row.actual_odds || "-"}/
                  {row.drop_odds || "-"}
                </span>
                <span>{row.source_text || "-"}</span>
              </div>
            ))
          : !loading && (
              <div className="records-empty">
                <Empty
                  image={Empty.PRESENTED_IMAGE_SIMPLE}
                  description="暂无数据"
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
  );
}
