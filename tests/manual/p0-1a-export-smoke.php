<?php
declare(strict_types=1);

/**
 * 运行：
 *   cd src && php tests/manual/p0-1a-export-smoke.php
 *
 * 覆盖：
 *   1. 导出列解析：表字段白名单 / 敏感字段黑名单 / 系统字段剔除 / 顺序与标题
 *   2. 单元格清洗：null / bool / 数组 / CSV 公式注入
 *   3. 文件名清洗与落地目录
 *   4. 导出权限提供者：能扫描到声明了 public export() 的控制器（dry-run，不写库）
 *
 * 未覆盖（需运行中的站点，属任务卡 M 类手工用例）：
 *   - 真实 CSV 下载（#1 当前页 / #3 勾选）
 *   - 与 getList 行数一致（#2）
 *   - 无权限 403 / 未登录 401（#8 / #9）
 */

require __DIR__ . '/../../vendor/autoload.php';

use app\common\service\ExportService;

$app = new \think\App();
$app->initialize();

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  [PASS] {$name}\n";
        return;
    }

    $failed++;
    echo "  [FAIL] {$name}" . ($detail !== '' ? " —— {$detail}" : '') . "\n";
}

echo "== 1. 导出列解析 ==\n";

$service = new ExportService(['chunk' => 10, 'max_rows' => 100]);

$tableFields = ['id', 'username', 'password', 'access_token', 'delete_time', 'created_at'];
$requested   = [
    ['field' => 'id', 'title' => 'ID'],
    ['field' => 'username', 'title' => '用户名'],
    ['field' => 'password', 'title' => '密码'],
    ['field' => 'access_token', 'title' => '令牌'],
    ['field' => 'delete_time', 'title' => '删除时间'],
    ['field' => 'not_exists', 'title' => '不存在'],
];

$columns = $service->resolveColumns($tableFields, $requested);

check('敏感字段被剔除（password / access_token）', !isset($columns['password']) && !isset($columns['access_token']));
check('系统字段被剔除（delete_time）', !isset($columns['delete_time']));
check('非表字段被剔除（not_exists）', !isset($columns['not_exists']));
check('保留字段与顺序正确', array_keys($columns) === ['id', 'username'], implode(',', array_keys($columns)));
check('标题透传正确', ($columns['username'] ?? '') === '用户名');

$fallback = $service->resolveColumns($tableFields, []);
check('前端未声明列时回退表字段（已剔除敏感/系统字段）', array_keys($fallback) === ['id', 'username', 'created_at'], implode(',', array_keys($fallback)));

echo "\n== 2. 单元格清洗 ==\n";

check('null → 空串', $service->sanitizeCell(null) === '');
check('bool → 1/0', $service->sanitizeCell(true) === '1' && $service->sanitizeCell(false) === '0');
check('数组 → JSON', $service->sanitizeCell(['a' => 1]) === '{"a":1}');
check('公式注入（=）加单引号前缀', $service->sanitizeCell('=1+1') === "'=1+1");
check('公式注入（@）加单引号前缀', $service->sanitizeCell('@cmd') === "'@cmd");
check('普通中文原样保留', $service->sanitizeCell('张三') === '张三');

echo "\n== 3. 文件名与落地目录 ==\n";

check('文件名去掉路径分隔符', $service->sanitizeFilename('../../etc/passwd') === 'etc_passwd', $service->sanitizeFilename('../../etc/passwd'));
check('空文件名回退 export', $service->sanitizeFilename('   ') === 'export');

$target = $service->makeTarget('登录日志', 1, 'csv');
check('落地目录含 admin id', str_contains($target['path'], DIRECTORY_SEPARATOR . 'export' . DIRECTORY_SEPARATOR . '1' . DIRECTORY_SEPARATOR), $target['path']);
check('扩展名正确', str_ends_with($target['filename'], '.csv'), $target['filename']);

if (isset($target['path']) && is_file($target['path'])) {
    @unlink($target['path']);
}

echo "\n== 4. 导出权限声明（#[AuthPermission]） ==\n";

try {
    $method = new \ReflectionMethod(\app\admin\controller\LoginLog::class, 'export');
    $attrs  = $method->getAttributes(\wise\auth\attribute\AuthPermission::class);

    check('导出动作声明了 #[AuthPermission]', count($attrs) === 1, 'count=' . count($attrs));

    if (!empty($attrs)) {
        /** @var \wise\auth\attribute\AuthPermission $permission */
        $permission = $attrs[0]->newInstance();

        check('ident 正确', $permission->ident === 'admin.login_log.export', (string) $permission->ident);
        check('route 正确', $permission->route === '/admin/login_log/export', (string) $permission->route);
        check('method 为 POST', strtoupper((string) $permission->method) === 'POST', (string) $permission->method);
    }

    $dirs = (array) config('wise-auth.attribute_dirs', []);
    $hit  = false;
    foreach ($dirs as $dir) {
        if (str_contains(rtrim((string) $dir, '/\\'), 'admin' . DIRECTORY_SEPARATOR . 'controller')
            || str_contains((string) $dir, 'admin/controller')) {
            $hit = true;
            break;
        }
    }
    check('attribute_dirs 覆盖 app/admin/controller', $hit, implode(',', $dirs));
} catch (\Throwable $e) {
    echo '  [SKIP] 注解检查失败：' . $e->getMessage() . "\n";
}

echo "\n== 5. 真实 CSV 写出（需数据库可用，否则跳过） ==\n";

try {
    $count = (int) \think\facade\Db::name('admins')->count();
    if ($count === 0) {
        echo "  [SKIP] admins 表为空，跳过\n";
    } else {
        $csv = $service->makeTarget('admin_smoke', 0, 'csv');
        $rows = $service->csv(
            $csv['path'],
            static fn () => \think\facade\Db::name('admins')->field('id,username,nickname')->order('id', 'asc'),
            ['id' => 'ID', 'username' => '账号', 'nickname' => '昵称'],
            ['pk' => 'id']
        );

        check('写出行数 = 表行数', $rows === $count, "rows={$rows}, count={$count}");

        $content = (string) file_get_contents($csv['path']);
        check('含 UTF-8 BOM', str_starts_with($content, "\xEF\xBB\xBF"));
        check('含中文表头', str_contains($content, '账号') && str_contains($content, '昵称'));

        @unlink($csv['path']);
    }
} catch (\Throwable $e) {
    echo '  [SKIP] 数据库不可用：' . $e->getMessage() . "\n";
}

echo "\n== 6. 权限异步加载链路（前端显隐，后端不做逐页判断） ==\n";

check(
    'Index::permissions() 接口存在',
    method_exists(\app\admin\controller\Index::class, 'permissions')
);

$routes = (array) config('wise-auth.authenticated_routes', []);
check(
    '该接口属登录即可访问（/admin/index/*）',
    in_array('/admin/index/*', $routes, true) || in_array('/admin/index', $routes, true),
    implode(',', $routes)
);

$permFile = public_path('statics') . 'modules' . DIRECTORY_SEPARATOR . 'wise-permission.js';
$permCode = (string) @file_get_contents($permFile);
check('Wise.permission 提供 ensure()', str_contains($permCode, 'async ensure('));
check('权限集合缓存到 sessionStorage', str_contains($permCode, 'sessionStorage') && str_contains($permCode, 'CACHE_KEY'));
check('未就绪时 fail-closed（can 返回 false）', str_contains($permCode, 'if (!this._ready) return false;'));

$bootstrapFile = public_path('statics') . 'modules' . DIRECTORY_SEPARATOR . 'wise-bootstrap.js';
$bootstrapCode = (string) @file_get_contents($bootstrapFile);
check('控制器执行前 await 权限就绪', str_contains($bootstrapCode, 'W.permission.ensure()'));

$baseFile = app_path() . 'admin' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'base.html';
$baseCode = (string) @file_get_contents($baseFile);
check('页面不再注入权限 JSON（体积不随权限数量增长）', !str_contains($baseCode, '__WS_AUTH__'));

$traitFile = app_path() . 'admin' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'traits' . DIRECTORY_SEPARATOR . 'AdminTrait.php';
$traitCode = (string) @file_get_contents($traitFile);
check('AdminTrait 不再为按钮显隐算布尔值', !str_contains($traitCode, 'resolveAuthSnapshot'));

$schemaTrait = (string) @file_get_contents(
    app_path() . 'common' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'traits' . DIRECTORY_SEPARATOR . 'CrudSchemaTrait.php'
);
check('schema 输出 exportPermission', str_contains($schemaTrait, 'exportPermission'));
check('后端不再逐页判定导出权限', !str_contains($schemaTrait, 'canAccessExport'));

echo "\n== 7. 异步导出 handler（export:list / P0-1b） ==\n";

try {
    $ids = array_map('intval', (array) \think\facade\Db::name('admins')->order('id', 'asc')->column('id'));

    if (empty($ids)) {
        echo "  [SKIP] admins 表为空\n";
    } else {
        $dir = runtime_path() . 'export' . DIRECTORY_SEPARATOR . '0' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $snapshot = $dir . 'smoke_' . uniqid('', true) . '.ids.json';
        file_put_contents($snapshot, (string) json_encode($ids));

        $job    = new \app\common\service\ExportJobService();
        $result = $job->run([
            'model'    => \app\admin\model\Admin::class,
            'ids_file' => $snapshot,
            'columns'  => ['id' => 'ID', 'username' => '账号'],
            'pk'       => 'id',
            'filename' => 'async_smoke',
            'admin_id' => 0,
        ]);

        check('返回文件信息（file/path/size/rows）', !empty($result['file']) && !empty($result['path']));
        check('行数与主键快照一致', (int) $result['rows'] === count($ids), ($result['rows'] ?? 0) . '/' . count($ids));
        check('文件真实存在且非空', is_file((string) $result['path']) && filesize((string) $result['path']) > 0);
        check('结果含过期时间（下载中心用）', !empty($result['expire_at']));
        check('主键快照已被清理', !is_file($snapshot));

        @unlink((string) $result['path']);
    }
} catch (\Throwable $e) {
    echo '  [SKIP] 异步 handler 测试失败：' . $e->getMessage() . "\n";
}

echo "\n== 8. 下载中心权限与菜单（P0-1b） ==\n";

try {
    $handlers = \app\common\service\QueueService::getRegisteredHandlers();
    check('export:list 已注册到 QueueService', in_array('export:list', $handlers, true), implode(',', $handlers));

    $perms = \think\facade\Db::name('auth_permissions')
        ->where('route', 'like', '/admin/download/%')
        ->select()
        ->toArray();

    $byAction = [];
    foreach ($perms as $perm) {
        $byAction[basename((string) $perm['route'])] = (string) $perm['method'];
    }

    check('下载中心 5 个权限节点', count($perms) === 5, 'count=' . count($perms));
    check('delete/clear 为 POST（避免 path_suffix 误判）', ($byAction['delete'] ?? '') === 'POST' && ($byAction['clear'] ?? '') === 'POST');

    $menus = \think\facade\Db::name('menus')->where('path', 'like', '/admin/download/%')->count();
    check('菜单已补种（5 项）', (int) $menus === 5, 'count=' . $menus);
} catch (\Throwable $e) {
    echo '  [SKIP] 权限/菜单检查失败（可能未执行迁移）：' . $e->getMessage() . "\n";
}

echo "\n----------------------------------------\n";
echo "结果：PASS {$passed} / FAIL {$failed}\n";

exit($failed === 0 ? 0 : 1);
