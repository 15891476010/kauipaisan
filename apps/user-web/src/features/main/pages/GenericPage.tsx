import { Empty } from "antd";

export function GenericPage({ title }: { title: string }) {
  return (
    <div className="generic">
      <h2>{title}</h2>
      <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" />
    </div>
  );
}
