<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;

class Newsletter extends BaseController
{
    public function index()
    {
        $list = \plugin\wisecms\model\Newsletter::order('id', 'desc')->paginate(20);
        if ($this->request->isAjax()) return $this->success('', $list->toArray());
        // 视图按数组键取数（$list.data / $list.total），故统一转数组
        $this->assign('list', $list->toArray());
        return $this->fetch('admin/newsletter/index');
    }

    public function delete()
    {
        \plugin\wisecms\model\Newsletter::destroy($this->request->post('id'));
        return $this->success('已删除');
    }

    /**
     * 批量删除订阅记录（UIUX-P1-18）
     *
     * 权限由 `/plugin/wisecms/newsletter/*` 通配符 + PluginAdminAuth 校验；
     * 订阅是个人信息，批量删除属敏感操作 → 前端走 L2 确认 + 影响面回显。
     */
    public function batchDelete()
    {
        $ids = $this->request->post('ids/a', []);
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return $this->error('请选择要删除的订阅记录');
        }

        $count = \plugin\wisecms\model\Newsletter::destroy($ids);

        return $this->success("已删除 {$count} 条订阅记录");
    }

    /**
     * 导出订阅列表 CSV（UIUX-P1-18）
     *
     * 权限由 `/plugin/wisecms/newsletter/*` 通配符 + PluginAdminAuth 校验；
     * 导出行为手动写入操作日志 —— 插件后台不经 OperationLog 中间件
     * （`app/plugin/middleware.php` 只有 LoadLangPack + PluginAdminAuth），
     * 故不能依赖 `#[OperationLog]` 标注自动记录，需手动写 `ws_operation_log`。
     */
    public function export()
    {
        $list = \plugin\wisecms\model\Newsletter::order('id', 'desc')->select()->toArray();
        $count = count($list);

        // 操作日志：手动写入（插件后台无 OperationLog 中间件，见文件头说明）
        $this->logExport($count);

        $statusMap = [0 => '待确认', 1 => '已确认', 2 => '已退订'];
        $headers   = ['ID', '邮箱', '订阅人', '标签', '状态', '订阅时间'];

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM（Excel 正确识别中文）
        fputcsv($fh, $headers);

        foreach ($list as $row) {
            $cells = [
                (string) ($row['id'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['tags'] ?? ''),
                $statusMap[(int) ($row['status'] ?? -1)] ?? (string) ($row['status'] ?? ''),
                (string) ($row['create_time'] ?? ''),
            ];
            // 防 CSV 公式注入：以 = + - @ 开头的字段加单引号前缀（与 pluginhub 导出同口径）
            foreach ($cells as &$cell) {
                if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true)) {
                    $cell = "'" . $cell;
                }
            }
            unset($cell);
            fputcsv($fh, $cells);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $filename = 'newsletter_' . date('YmdHis') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * 手动写操作日志（P1-18 导出审计）
     *
     * 插件后台的中间件链里没有 OperationLog 中间件（只有 VerifyCsrfToken），
     * `#[OperationLog]` 标注不会生效；此处直接写 `ws_operation_log` 表。
     * wise-operation-log 未安装 / 写失败时静默降级（导出本身不应被审计失败阻断）。
     */
    protected function logExport(int $count): void
    {
        if (!class_exists(\wise\operationlog\model\OperationLogModel::class)) {
            return;
        }

        try {
            \wise\operationlog\model\OperationLogModel::createFromConfig()->save([
                'channel'       => 'wisecms',
                'level'         => 'INFO',
                'operator_name' => (string) $this->currentAdminId(),
                'request_url'   => (string) $this->request->url(),
                'ip'            => (string) $this->request->ip(),
                'user_agent'    => (string) $this->request->header('user-agent', ''),
                'tags'          => 'wisecms,newsletter',
                'content'       => "导出订阅列表 {$count} 条",
            ]);
        } catch (\Throwable $e) {
            cms_log('warning', '写入订阅导出操作日志失败: ' . $e->getMessage());
        }
    }
}
