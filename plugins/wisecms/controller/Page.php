<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\service\model\MassAssignmentGuard;
use plugin\wisecms\model\Page as PageModel;
use plugin\wisecms\model\Category as CategoryModel;
use plugin\wisecms\model\UrlAlias;
use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\service\content\HtmlSanitizer;
use plugin\wisecms\service\site\SiteService;
use plugin\wisecms\service\url\UrlService;
class Page extends BaseController
{
    use PageFallbackTrait;

    /**
     * UIUX-P1-06：单页正文同样是富文本 → 必须登记为「允许 HTML 的字段」
     *
     * 框架 `app\Request::applySecurityFilter()` 对**未登记**字段一律 `strip_tags()`
     * （`app/Request.php:182`），否则编辑器/富文本正文会在入库前被剥成纯文本。
     */
    protected function initPlugin(): void
    {
        $this->request->registerHtmlField(['content']);
    }

    public function index()
    {
        $params = [
            'keyword' => $this->request->param('keyword', ''),
            // P1-B6：分页上限收敛
            'page'    => max(1, (int)$this->request->param('page', 1)),
            'limit'   => min(100, max(1, (int)$this->request->param('limit', 15))),
            // P1-29：回收站视图（trashed=1 只列软删单页）
            'trashed' => (int)$this->request->param('trashed', 0),
        ];

        // P2：单页列表按「后台当前站点」过滤（Page 表早已有 site_id，原实现未使用 → 跨站混合）
        $query = PageModel::where('site_id', (new SiteService())->resolveAdminSiteId())
            // UIUX-P0-15：列表要显示「所属栏目」与「摘要」，但 `content` 是 longtext ——
            // 原实现整行取出（每页 15 条正文）却既没摘要也用不上栏目名。改为：
            // 取轻量列 + 关联栏目，正文**只取前 300 字符**（`LEFT()`），再在 PHP 侧派生摘要。
            ->with(['category'])
            // P1-29：回收站视图需要 delete_time（显示删除时间）；普通视图该列恒为 NULL，不影响
            ->field('id,catid,title,alias,template,status,view_count,create_time,update_time,delete_time')
            ->fieldRaw('LEFT(content, 300) AS content');

        if ($params['trashed']) {
            // P1-29：回收站只列软删单页，按删除时间倒序
            $query->onlyTrashed()->order('delete_time', 'desc');
        } else {
            $query->order('id', 'desc');
        }

        if (!empty($params['keyword'])) {
            // P1-B6：转义 LIKE 通配符
            $keyword = addcslashes((string) $params['keyword'], '%_\\');
            $query->where('title', 'like', "%{$keyword}%");
        }

        $list = $query->paginate(['page' => $params['page'], 'list_rows' => $params['limit']]);

        // 视图与 JSON 都按数组键取数（$list.data / $list.total），故统一转数组后加工
        $result = $list->toArray();
        $result['data'] = array_map(function (array $row): array {
            $row['summary'] = $this->makeSummary((string) ($row['content'] ?? ''));
            unset($row['content']);   // 列表不需要正文，避免响应体被 longtext 撑大

            return $row;
        }, $result['data']);

        if ($this->request->isAjax()) {
            return $this->success('', $result);
        }

        $this->assign('list', $result);
        $this->assign('params', $params);
        return $this->fetch('admin/page/index');
    }

    /**
     * 从正文派生列表摘要（UIUX-P0-15）
     *
     * 列表只需要一两行**纯文本**，而 `content` 是允许 HTML 的 longtext。
     * 配合上面的 `LEFT(content, 300)`：去标签 → 压空白 → 解实体（否则 `&nbsp;` 会二次转义显示成乱码）→ 截断。
     */
    private function makeSummary(string $content): string
    {
        $text = strip_tags($content);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return mb_substr($text, 0, 80);
    }

    public function create()
    {
        if ($this->request->isPost()) {
            // P1-1：黑名单剔除 id / create_time / update_time / view_count 等非表单字段
            // （Page 模型 autoWriteTimestamp=true，时间列由模型自动写入）
            // P1-B3：site_id 也被剔除，由服务端按当前站点归属写入，避免跨站伪造
            $data = MassAssignmentGuard::filter($this->request->post(), 'wisecms_page');
            // P2：后台写入按「后台当前站点」（会话上下文），而非域名站点 ——
            // 否则在站点 A 的域名上访问后台时，切到站点 B 新建的单页会被写进站点 A
            $data['site_id'] = (new SiteService())->resolveAdminSiteId();
            if (empty($data['title'])) {
                return $this->error('标题不能为空');
            }
            // P0-4：正文服务端净化（前台 view/index/page.html 以 {$page.content|raw} 直出）
            if (isset($data['content']) && is_string($data['content'])) {
                $data['content'] = HtmlSanitizer::clean($data['content']);
            }

            // P1-C：别名站点内唯一（(site_id, alias) 有唯一键，先做友好查重）
            try {
                $data = $this->normalizeAlias($data, (int) $data['site_id']);
                $page = PageModel::create($data);
            } catch (WisecmsException $e) {
                return $this->error($e->getMessage());
            }

            // 同步 URL 别名索引
            (new UrlService())->syncPage($page);

            return $this->success('单页创建成功');
        }
        $this->assign('catidOptions', $this->catidOptions());
        return $this->fetch('admin/page/create');
    }

    public function edit()
    {
        $id = $this->request->param('id');
        $page = PageModel::find($id);
        if (!$page) return $this->pageFallback('单页不存在', plugin_url('wisecms/page/index'));

        if ($this->request->isPost()) {
            // P1-1：同上；update_time 由模型 autoWriteTimestamp 维护
            $data = MassAssignmentGuard::filter($this->request->post(), 'wisecms_page');
            // P0-4：正文服务端净化（同 create）
            if (isset($data['content']) && is_string($data['content'])) {
                $data['content'] = HtmlSanitizer::clean($data['content']);
            }

            // P1-C：别名站点内唯一（同 create）
            try {
                $data = $this->normalizeAlias($data, (int) $page->site_id, (int) $page->id);
                $page->save($data);
            } catch (WisecmsException $e) {
                return $this->error($e->getMessage());
            }

            // 同步 URL 别名索引（alias 变更时旧 URL 转历史，301 到新 URL）
            (new UrlService())->syncPage($page);

            return $this->success('单页更新成功');
        }

        $this->assign('page', $page);
        $this->assign('catidOptions', $this->catidOptions());
        return $this->fetch('admin/page/edit');
    }

    /**
     * 可关联的「单页型」栏目下拉项
     *
     * 前台栏目页会把 type=1（单页）的栏目 301 到 `page/detail?catid=<栏目ID>`，
     * 因此单页需能反向绑定栏目；此前后台无该入口，导致该链路无法配置。
     */
    private function catidOptions(): array
    {
        return CategoryModel::where('type', 1)
            ->where('site_id', (new SiteService())->resolveAdminSiteId())
            ->order('sort', 'asc')
            ->field('id,name')
            ->select()
            ->toArray();
    }

    /**
     * 单页别名归一 + 站点内唯一性校验（P1-C）
     *
     * `(site_id, alias)` 上有唯一索引：空别名写为 NULL（唯一索引允许多个 NULL），
     * 非空别名先做友好查重；查重**含回收站单页**（软删行同样占用唯一键）。
     */
    private function normalizeAlias(array $data, int $siteId, int $excludeId = 0): array
    {
        if (!array_key_exists('alias', $data)) {
            return $data;
        }

        $alias = trim((string) $data['alias']);
        if ($alias === '') {
            $data['alias'] = null;

            return $data;
        }

        $query = PageModel::withTrashed()->where('site_id', $siteId)->where('alias', $alias);
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }

        if ($query->find()) {
            throw new WisecmsException('该别名已被其他单页使用（含回收站单页），请更换', ErrorCode::DUPLICATE);
        }

        $data['alias'] = $alias;

        return $data;
    }

    public function delete()
    {
        $id = $this->request->post('id');
        $page = PageModel::find($id);
        if (!$page) return $this->pageFallback('单页不存在', plugin_url('wisecms/page/index'));
        $page->delete();

        (new UrlService())->removeAlias(UrlAlias::TYPE_PAGE, (int) $id);

        return $this->success('单页删除成功');
    }

    /**
     * 恢复回收站单页（UIUX-P1-29）
     *
     * 背景：`Page` 是软删表，但后台此前没有任何恢复入口 —— 删除即「消失」，
     * 且 `(site_id, alias)` 唯一键**包含软删行**（`normalizeAlias` 查重走 `withTrashed`），
     * 删除后别名仍被占用、用户既改不回也建不了同名别名。
     *
     * 正常流程下软删行始终占用唯一键，恢复不会撞键；这里仍做一次防御性校验，
     * 兜底历史脏数据（唯一键加约束前就存在的重名），把 1062 SQL 报错换成友好提示。
     */
    public function restore()
    {
        $id     = (int)$this->request->post('id');
        $siteId = (new SiteService())->resolveAdminSiteId();

        $page = PageModel::onlyTrashed()
            ->where('id', $id)
            ->where('site_id', $siteId)
            ->find();
        if (!$page) {
            return $this->error('回收站中未找到该单页');
        }

        // 防御性校验：别名是否已被「未删」的其它单页占用（含软删行的是自己，不冲突）
        $alias = $page->alias;
        if ($alias !== null && $alias !== '') {
            $conflict = PageModel::where('site_id', $siteId)
                ->where('alias', $alias)
                ->where('id', '<>', $id)
                ->find();
            if ($conflict) {
                return $this->error('该单页的别名「' . $alias . '」已被其它单页占用，请先修改占用者的别名或彻底删除回收站旧记录');
            }
        }

        $page->restore();

        // 恢复 URL 别名索引
        (new UrlService())->syncPage($page);

        return $this->success('单页已恢复');
    }
}
