<?php
declare(strict_types=1);

/**
 * 控制器内回收站（CrudAction）—— 冒烟自测
 *
 * 运行：cd src && php tests/manual/crud-recycle-smoke.php
 *
 * 覆盖：
 *   1. `supportsRecycle()` 对「软删模型 / 非软删模型」的判定
 *   2. `_recycleList()` 的响应结构（与 `_getList()` 同构，前端表格直接可用）
 *   3. `_restore()` 端到端（原实现依赖的 restoreByIds 已废弃）
 *   4. `_forceDelete(true)` 的确认令牌门槛 + 只允许回收站记录
 *
 * 说明：全部数据在事务内创建并回滚，不污染数据库。
 */

require __DIR__ . '/../../vendor/autoload.php';

use app\admin\model\Admin;
use app\admin\model\Menu;
use app\common\controller\traits\CrudTrait;
use think\App;
use think\facade\Db;
use think\Model;

$app = new App();
$app->initialize();

$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $name . (!$ok && $detail !== '' ? " —— {$detail}" : '') . "\n";
}

abstract class RecycleTestController extends \app\common\controller\BaseController
{
    use CrudTrait;

    public function exposeSupports(): bool
    {
        return $this->supportsRecycle();
    }

    public function exposeList(): array
    {
        return $this->_recycleList()->getData();
    }

    public function exposeRestore(): array
    {
        return $this->_restore()->getData();
    }

    public function exposeForceDelete(bool $fromRecycle): array
    {
        return $this->_forceDelete($fromRecycle)->getData();
    }
}

/** 重设请求参数并清掉 Request::param 缓存（CLI 下多次调用同一请求对象） */
function setParams(App $app, array $params): void
{
    $request = $app->request;
    $request->withGet($params);

    foreach (['param' => [], 'mergeParam' => false] as $name => $value) {
        $prop = new ReflectionProperty($request, $name);
        $prop->setAccessible(true);
        $prop->setValue($request, $value);
    }
}

class MenuRecycleTestController extends RecycleTestController
{
    protected function getModel(): Model
    {
        return new Menu();
    }
}

class AdminRecycleTestController extends RecycleTestController
{
    protected function getModel(): Model
    {
        return new Admin();
    }
}

/** @var MenuRecycleTestController $menuCtl */
$menuCtl = new MenuRecycleTestController($app);
/** @var AdminRecycleTestController $adminCtl */
$adminCtl = new AdminRecycleTestController($app);

echo "== 1. supportsRecycle 能力探测 ==\n";
check('Menu 控制器判定支持回收站', $menuCtl->exposeSupports());
check('Admin 控制器判定不支持回收站', !$adminCtl->exposeSupports());

echo "\n== 2. 不支持回收站的动作返回明确错误 ==\n";
$adminList = $adminCtl->exposeList();
check('Admin._recycleList 返回业务错误', (int) ($adminList['code'] ?? 0) !== 0, json_encode($adminList, JSON_UNESCAPED_UNICODE));

echo "\n== 3. 端点行为（事务回滚，不留数据）==\n";
Db::startTrans();

try {
    $suffix = (string) time();
    $menu   = Menu::create([
        'name'      => 'trait-selftest-' . $suffix,
        'path'      => '/selftest-trait/' . $suffix,
        'type'      => 'menu',
        'parent_id' => 0,
        'sort'      => 9999,
        'plugin'    => 'system',
        'status'    => 1,
    ]);
    $id = (int) $menu->getAttr('id');
    $menu->delete();

    setParams($app, ['page' => 1, 'limit' => 5]);

    $list = $menuCtl->exposeList();
    check('_recycleList code=0', (int) ($list['code'] ?? -1) === 0, json_encode($list, JSON_UNESCAPED_UNICODE));
    check(
        '_recycleList 返回 total/list 结构（前端表格直接可用）',
        isset($list['data']['total'], $list['data']['list'])
    );
    check('_recycleList 命中刚软删的记录', (int) ($list['data']['total'] ?? 0) >= 1);

    setParams($app, ['ids' => [$id]]);
    $restore = $menuCtl->exposeRestore();
    check('_restore code=0 且 restored=1', (int) ($restore['code'] ?? -1) === 0 && (int) ($restore['data']['restored'] ?? 0) === 1, json_encode($restore, JSON_UNESCAPED_UNICODE));
    check('_restore 后记录回到在线列表', Menu::where('id', $id)->count() === 1);

    Menu::where('id', $id)->find()->delete();
    setParams($app, ['ids' => [$id]]);
    $denied = $menuCtl->exposeForceDelete(true);
    check('_forceDelete(true) 无令牌时拒绝', (int) ($denied['code'] ?? 0) !== 0, json_encode($denied, JSON_UNESCAPED_UNICODE));
    check('无令牌被拒后记录仍在回收站', Menu::onlyTrashed()->where('id', $id)->count() === 1);

    $token = (new \app\common\service\ConfirmTokenService())->issue(
        'crud.force_delete',
        ['controller' => MenuRecycleTestController::class]
    );
    setParams($app, ['ids' => [$id], 'confirm_token' => $token]);
    $deleted = $menuCtl->exposeForceDelete(true);
    check('_forceDelete(true) 合法令牌可执行', (int) ($deleted['code'] ?? -1) === 0 && (int) ($deleted['data']['deleted'] ?? 0) === 1, json_encode($deleted, JSON_UNESCAPED_UNICODE));
    check('已物理删除（withTrashed 也查不到）', Menu::withTrashed()->where('id', $id)->count() === 0);
} catch (\Throwable $e) {
    check('端点调用无异常', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    Db::rollback();
}

echo $failed === 0 ? "\n全部通过\n" : "\n失败 {$failed} 项\n";
exit($failed === 0 ? 0 : 1);
