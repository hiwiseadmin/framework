<?php
namespace plugin\wisecms\service\content;

use plugin\wisecms\model\Content;
use plugin\wisecms\model\Category;
use plugin\wisecms\model\SearchLog;
use plugin\wisecms\service\site\SiteService;
use think\facade\Db;

class SearchService
{
    protected SearchEngineInterface $engine;

    public function __construct(?SearchEngineInterface $engine = null)
    {
        $this->engine = $engine ?? new MysqlSearchEngine();
    }

    /**
     * 设置搜索引擎
     */
    public function setEngine(SearchEngineInterface $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * 搜索内容
     */
    public function search(string $query, array $filters = []): array
    {
        $page = $filters['page'] ?? 1;
        $limit = $filters['limit'] ?? 15;

        $result = $this->engine->search($query, [
            'site_id' => $filters['site_id'] ?? 0,
            'catid' => $filters['catid'] ?? 0,
            'model_id' => $filters['model_id'] ?? 0,
            'date_start' => $filters['date_start'] ?? '',
            'date_end' => $filters['date_end'] ?? '',
            // 排序（引擎层做白名单校验）
            'order' => $filters['order'] ?? 'create_time',
            'page' => $page,
            'limit' => $limit,
        ]);

        // 高亮关键词
        if (!empty($query) && !empty($result['list'])) {
            foreach ($result['list'] as &$item) {
                $item['title'] = $this->highlight($item['title'] ?? '', $query);
                $item['description'] = $this->highlight($item['description'] ?? '', $query);
            }
        }

        // 记录搜索日志（P2-04 修正）
        //
        // ⚠️ 原实现**在搜索之前**记录、且把 `result_count` 写死 0 —— 而该列的本意正是
        //    「本次搜出几条」（用于排查「搜了却搜不到」），恒为 0 等于该列毫无意义。
        //    现改为搜索后记录一次，带上真实条数：**仍是 1 次写入**（原实现也是 1 次）。
        if (trim($query) !== '') {
            $this->logSearch($query, $filters, (int) ($result['total'] ?? 0));
        }

        return $result;
    }

    /**
     * 获取热搜词
     *
     * @param int $limit  条数
     * @param int $siteId >0 时按站点统计（P1-D：搜索日志已按站点隔离）
     */
    public function getHotKeywords(int $limit = 10, int $siteId = 0): array
    {
        $query = SearchLog::field('keyword, COUNT(*) as count');
        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        return $query->group('keyword')
            ->order('count', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 后台搜索日志分页列表（UIUX-P2-04）
     *
     * 与 {@see getHotKeywords()} 的分工：那里是**聚合**（热搜词），本方法是**明细**
     * —— 排查「谁在搜什么」「搜了却搜不到」（`result_count`）。
     *
     * @param array $params page / limit / site_id / keyword
     * @return array 标准 paginate 契约（total / per_page / current_page / last_page / data）
     */
    public function getLogPage(array $params = []): array
    {
        $page  = max(1, (int) ($params['page'] ?? 1));
        // limit 上限收敛（P1-19b 口径）
        $limit = min(100, max(1, (int) ($params['limit'] ?? 15)));

        $query = SearchLog::order('id', 'desc');

        // 站点隔离：后台只应看到「当前站点」的搜索词（P1-D 已给表补 site_id）
        $siteId = (int) ($params['site_id'] ?? 0);
        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('keyword', '%' . $keyword . '%');
        }

        return $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
    }

    /**
     * 重建搜索索引
     */
    public function rebuildIndex(): bool
    {
        return $this->engine->rebuild();
    }

    /**
     * 高亮关键词
     */
    protected function highlight(string $text, string $query): string
    {
        // 先对原文做 HTML 转义，再包裹高亮标记。
        // 模板默认过滤器为 htmlentities（view.php 未覆盖 default_filter），
        // 若在此处直接注入 <em> 会被实体化后原样显示，导致高亮失效。
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $words = preg_split('/[\s,，]+/u', $query);
        foreach ($words as $word) {
            $word = trim($word);
            if (mb_strlen($word) < 2) continue;
            $safeWord = htmlspecialchars($word, ENT_QUOTES, 'UTF-8');
            $text = preg_replace(
                '/(' . preg_quote($safeWord, '/') . ')/iu',
                '<em class="highlight">$1</em>',
                $text
            );
        }
        return $text;
    }

    /**
     * 记录搜索
     *
     * @param string $query       关键词（会按 keyword 列长度 200 截断，防止超长入库报错）
     * @param array  $filters     取 site_id
     * @param int    $resultCount 本次搜索命中条数（P2-04：由调用方在搜索后回传）
     */
    protected function logSearch(string $query, array $filters, int $resultCount = 0): void
    {
        // P1-D：搜索日志按站点隔离（调用方未传时回落到当前站点）
        $siteId = (int) ($filters['site_id'] ?? 0);
        if ($siteId <= 0) {
            $siteId = (new SiteService())->resolveCurrentSiteId();
        }

        SearchLog::create([
            'keyword' => mb_substr(trim($query), 0, 200),
            'site_id' => $siteId,
            'result_count' => max(0, $resultCount),
            'ip' => request()->ip() ?? '',
            // create_time / update_time 由模型 autoWriteTimestamp 维护
        ]);
    }
}

// ---- 搜索引擎接口 ----
interface SearchEngineInterface
{
    public function search(string $query, array $filters): array;
    public function rebuild(): bool;
    public function health(): bool;
}

// ---- MySQL 内置实现 ----
class MysqlSearchEngine implements SearchEngineInterface
{
    public function search(string $query, array $filters): array
    {
        $sql = Db::name('wisecms_content')->whereNull('delete_time')->where('status', 2);

        if (!empty($query)) {
            // 转义 LIKE 通配符防止性能退化
            $escapedQuery = addcslashes($query, '%_\\');
            $sql->where(function($q) use ($escapedQuery) {
                $q->where('title', 'like', "%{$escapedQuery}%")
                  ->whereOr('keywords', 'like', "%{$escapedQuery}%")
                  ->whereOr('description', 'like', "%{$escapedQuery}%");
            });
        }
        if (!empty($filters['site_id'])) $sql->where('site_id', $filters['site_id']);
        if (!empty($filters['catid'])) $sql->where('catid', $filters['catid']);
        if (!empty($filters['model_id'])) $sql->where('model_id', $filters['model_id']);

        // create_time 为 DATETIME：纯日期入参补齐到当天首/末刻，
        // 否则 `<= '2026-01-31'` 会漏掉当天内容
        if (!empty($filters['date_start'])) {
            $startAt = cms_datetime($filters['date_start']) ?? '';
            if ($startAt !== '') {
                $sql->where('create_time', '>=', strlen((string) $filters['date_start']) <= 10 ? substr($startAt, 0, 10) . ' 00:00:00' : $startAt);
            }
        }
        if (!empty($filters['date_end'])) {
            $endAt = cms_datetime($filters['date_end']) ?? '';
            if ($endAt !== '') {
                $sql->where('create_time', '<=', strlen((string) $filters['date_end']) <= 10 ? substr($endAt, 0, 10) . ' 23:59:59' : $endAt);
            }
        }

        // ① 先统计总数（避免 page() 的 limit 影响 count 结果）
        //
        // 🚨 顺序**必须**在「加相关度排序」之前：
        //    相关度排序带命名绑定（`:queryMatch`），而这里 `clone $sql` 出去的 count 查询
        //    并不包含该占位符 → 绑定参数与占位符数量不匹配
        //    （实测 `SQLSTATE[HY093]: Invalid parameter number`）。
        $total = (clone $sql)->count();

        // ② 再定排序
        if (!empty($query)) {
            // 相关度排序：标题命中优先
            //
            // ⚠️ 这里**刻意不用参数绑定**，原因有两个（均为 P2-04 实测）：
            //    1) 原写法把「键」与「值」作为**两个标量参数**传给绑定方法，而本版
            //       `Query::bind()` 的签名是 `bind(array $value)`（只收一个数组）；
            //    2) 改成数组后，`orderRaw` 里的命名占位符并没有进入最终 SQL，
            //       绑定的参数反而多出来 → `SQLSTATE[HY093]: Invalid parameter number`。
            //    也就是说：**前台搜索任何非空关键词此前都会 500**（无实测入口，长期未暴露）。
            //    故改为自行拼装**安全**的 MySQL 字符串字面量：转义反斜杠 / 单引号 / LIKE 通配符。
            $needle = str_replace(['\\', "'", '%', '_'], ['\\\\', "\\'", '\\%', '\\_'], $query);
            $sql->orderRaw("CASE WHEN title LIKE '%{$needle}%' THEN 1 ELSE 2 END, create_time DESC");
        } else {
            // 排序白名单（非法列名一律回落 create_time）
            $order = (string) ($filters['order'] ?? 'create_time');
            if (!in_array($order, ['create_time', 'update_time', 'publish_time', 'view_count', 'id'], true)) {
                $order = 'create_time';
            }
            $sql->order($order, 'desc');
        }

        // ③ 取分页数据
        $list = $sql->page($filters['page'] ?? 1, $filters['limit'] ?? 15)->select()->toArray();

        return ['list' => $list, 'total' => $total, 'page' => $filters['page'] ?? 1, 'limit' => $filters['limit'] ?? 15];
    }

    public function rebuild(): bool
    {
        // MySQL 全文索引自动维护
        return true;
    }

    public function health(): bool
    {
        try {
            Db::query('SELECT 1 FROM ' . Db::getConfig('prefix') . 'wisecms_content LIMIT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
