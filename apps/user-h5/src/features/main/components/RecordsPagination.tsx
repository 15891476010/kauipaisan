import { Pagination } from "antd";
import paginationZhCN from "@rc-component/pagination/locale/zh_CN";

export function RecordsPagination({
  page,
  pageSize = 20,
  total,
  loading,
  onPage,
}: {
  page: number;
  pageSize?: number;
  total: number;
  loading: boolean;
  onPage: (page: number) => void;
}) {
  return (
    <Pagination
      className="records-page-pagination ant-pagination-customized"
      current={page}
      pageSize={pageSize}
      total={total}
      showSizeChanger={false}
      showQuickJumper
      locale={paginationZhCN}
      disabled={loading}
      onChange={(nextPage) => onPage(nextPage)}
    />
  );
}
