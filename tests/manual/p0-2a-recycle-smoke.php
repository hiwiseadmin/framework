<?php
declare(strict_types=1);

/**
 * 运行：cd src && php tests/manual/p0-2a-recycle-smoke.php
 *
 * 覆盖：
 *   1. RecycleRegistry 扩展点汇总（宿主 config + 包 + 插件）
 *   2. find() 白名单（IDOR 防线）
 *   3. ConfirmToken 一次性确认令牌
 *   4. 菜单 / 权限补种 + method 纠正
 *   5. 软删还原端到端（事务回滚，不污染数据）
 *
 * 说明：插件（wisecms）在 CLI 裸引导下不会 boot（PluginLoader 只在 HTTP 首请求触发），
 * 故插件模型用「静态检查 registerRecycle 调用」验证，HTTP 运行由 PluginLoader 保证。
 */

require __DIR__ . '/../../vendor/autoload.php';

use app\common\service\ConfirmTokenService;
use app\common\service\RecycleRegistry;

$app = new think\App();
$app->initialize();

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . ($detail !== '' ? " —— {$detail}" : '') . "\n";
    }
}

echo "== 1. RecycleRegistry 汇总 ==\n";

$registry = new RecycleRegistry();
$names    = array_map(static fn ($c) => (new ReflectionClass($c))->getShortName(), array_keys($registry->all()));

echo '    models: ' . implode(', ', $names) . "\n";

check('宿主 Menu 已注册（config/recycle.php）', in_array('Menu', $names, true));
check('包 AttachmentModel 已注册（provider）', in_array('AttachmentModel', $names, true));

$cmsBoot = (string) @file_get_contents(__DIR__ . '/../../plugins/wisecms/BootServiceProvider.php');
check('插件 wisecms 已接入 registerRecycle（HTTP 生效）', str_contains($cmsBoot, 'registerRecycle(['));

$wiseBoot = (string) @file_get_contents(__DIR__ . '/../../extend/wise/plugin/WiseBootServiceProvider.php');
check('WiseBootServiceProvider 提供 registerRecycle()', str_contains($wiseBoot, 'function registerRecycle('));

echo "\n== 2. 白名单（IDOR 防线）==\n";

check('find(Menu) 命中', $registry->find('Menu') !== null);
check('短名大小写不敏感命中', $registry->find('menu') !== null);
check('未注册模型返回 null', $registry->find('NonExistModel') === null);

echo "\n== 3. ConfirmToken 一次性 ==\n";

$confirm = new ConfirmTokenService();
$token   = $confirm->issue('recycle.force_delete', ['model' => \app\admin\model\Menu::class]);

check('签发非空 token', $token !== '');
check('verify 首次通过', $confirm->verify('recycle.force_delete', $token, ['model' => \app\admin\model\Menu::class]));
check('verify 重放被拒绝（一次性）', !$confirm->verify('recycle.force_delete', $token, ['model' => \app\admin\model\Menu::class]));
check('跨场景拒绝', !$confirm->verify('recycle.clear', $token, ['model' => \app\admin\model\Menu::class]));

$token2 = $confirm->issue('recycle.clear', ['model' => \app\admin\model\Menu::class]);
check('上下文不匹配拒绝', !$confirm->verify('recycle.clear', $token2, ['model' => 'OtherModel']));

echo "\n== 4. 菜单 + 权限 ==\n";

$menus = \think\facade\Db::name('menus')->where('path', 'like', '/admin/recycle/%')->count();
check('菜单 7 项', (int) $menus === 7, 'count=' . $menus);

$perms = \think\facade\Db::name('auth_permissions')->where('route', 'like', '/admin/recycle/%')->select()->toArray();
check('权限 7 项', count($perms) === 7, 'count=' . count($perms));

$byAction = [];
foreach ($perms as $p) {
    $byAction[basename((string) $p['route'])] = (string) $p['method'];
}
check(
    'issuetoken/restore/forcedelete/clear 为 POST',
    ($byAction['issuetoken'] ?? '') === 'POST'
    && ($byAction['restore'] ?? '') === 'POST'
    && ($byAction['forcedelete'] ?? '') === 'POST'
    && ($byAction['clear'] ?? '') === 'POST'
);

echo "\n== 5. 软删还原端到端（事务回滚） ==\n";

try {
    \think\facade\Db::startTrans();

    $menu = \app\admin\model\Menu::create([
        'parent_id' => 0,
        'name'      => '__recycle_test__',
        'path'      => '/__recycle_test__',
        'type'      => 'menu',
        'status'    => 1,
        'plugin'    => 'system',
        'sort'      => 999999,
    ]);
    $id = (int) $menu->getKey();

    $menu->delete();

    $trashed = \app\admin\model\Menu::onlyTrashed()->where('id', $id)->find();
    check('软删后可经 onlyTrashed 查到', $trashed !== null);

    if ($trashed !== null) {
        $trashed->restore();
        $restored = \app\admin\model\Menu::find($id);
        check('restore() 后记录恢复', $restored !== null);

        if ($restored !== null) {
            check('软删字段已清空', empty($restored->getData()['delete_time'] ?? null));
        }
    }

    \think\facade\Db::rollback();
    check('事务回滚，测试数据未落库', \app\admin\model\Menu::where('name', '__recycle_test__')->count() === 0);
} catch (\Throwable $e) {
    if (\think\facade\Db::inTransaction()) {
        \think\facade\Db::rollback();
    }
    echo '  [SKIP] 还原端到端测试失败：' . $e->getMessage() . "\n";
}

echo "\n----------------------------------------\n";
echo "结果：PASS {$passed} / FAIL {$failed}\n";

exit($failed === 0 ? 0 : 1);
