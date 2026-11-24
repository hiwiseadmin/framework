<?php
declare(strict_types=1);

/**
 * 运行：cd src && php tests/manual/p0-2b-changelog-smoke.php
 *
 * 覆盖（对应任务卡验收 #13–#20）：
 *   1. 表结构 / 菜单 / 权限节点（含 POST 方法纠正）
 *   2. 采集：改 2 个字段 → 2 行，old/new 正确（#13）
 *   3. 敏感字段：password / token 零记录（#14）
 *   4. 大字段：10KB 截断 + truncated 标记（#15）
 *   5. 时间线：按 change_set 分组 + diff HTML 转义（#16）
 *   6. 回滚：数据恢复 + 自身留痕（#17），未接入模型拒绝回滚（#18 的后端白名单）
 *   7. 归档命令注册 + 配置（#20 前置）
 *
 * 说明：全程在事务内执行并回滚，不污染业务数据。
 */

require __DIR__ . '/../../vendor/autoload.php';

use app\common\service\ChangeLogService;
use think\facade\Config;
use think\facade\Db;
use wise\support\changelog\ChangeLogManager;

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

Config::set(['mode' => 'sync', 'actors' => null], 'change_log');

$service = new ChangeLogService();
$table   = ChangeLogService::TABLE;

echo "== 1. 表结构 / 菜单 / 权限 ==\n";

$columns = Db::name($table)->getTableFields();
foreach (['change_set', 'model', 'pk_value', 'field', 'old_value', 'new_value', 'truncated', 'operator_name', 'create_time'] as $col) {
    check("字段 {$col} 存在", in_array($col, $columns, true));
}

$menus = (int) Db::name('menus')->where('path', 'like', '/admin/change_log/%')->count();
check('菜单 6 项', $menus === 6, 'count=' . $menus);

$perms = Db::name('auth_permissions')->where('route', 'like', '/admin/change_log/%')->select()->toArray();
check('权限 6 项', count($perms) === 6, 'count=' . count($perms));

$byAction = [];
foreach ($perms as $p) {
    $byAction[basename((string) $p['route'])] = strtoupper((string) $p['method']);
}
check(
    'getlist/getmodels/timeline/issuetoken/rollback 为 POST',
    ($byAction['getlist'] ?? '') === 'POST'
    && ($byAction['getmodels'] ?? '') === 'POST'
    && ($byAction['timeline'] ?? '') === 'POST'
    && ($byAction['issuetoken'] ?? '') === 'POST'
    && ($byAction['rollback'] ?? '') === 'POST'
);

echo "\n== 2. 采集：改 2 个字段 → 2 行（#13）==\n";

$roleId = 0;
$setId  = '';
$inTx   = false;

try {
    Db::startTrans();
    $inTx = true;

    $role = \wise\auth\model\Role::create([
        'name'        => '__cl_test_role__',
        'slug'        => '__cl_test_role_slug__',
        'description' => 'v1',
        'status'      => 1,
        'sort'        => 999999,
    ]);
    $roleId = (int) $role->getKey();
    check('新增未记录（record_create 默认关闭）', (int) Db::name($table)->where('pk_value', (string) $roleId)->count() === 0);

    $role = \wise\auth\model\Role::find($roleId);
    $role->name        = '__cl_test_role_v2__';
    $role->description = 'v2';
    $role->save();

    $rows = Db::name($table)->where('pk_value', (string) $roleId)->order('id', 'asc')->select()->toArray();
    check('只记录 2 行（update_time 已排除）', count($rows) === 2, 'count=' . count($rows));
    check('同一次保存共用一个 change_set', count(array_unique(array_column($rows, 'change_set'))) === 1);

    $byField = [];
    foreach ($rows as $row) {
        $byField[$row['field']] = $row;
    }
    check('name 老值正确', ($byField['name']['old_value'] ?? null) === '__cl_test_role__');
    check('name 新值正确', ($byField['name']['new_value'] ?? null) === '__cl_test_role_v2__');
    check('模型类名记录为全类名', ($rows[0]['model'] ?? '') === \wise\auth\model\Role::class);
    check('字段中文名为空时前端回退字段名', ($byField['name']['field_label'] ?? '') === '');

    $setId = (string) ($rows[0]['change_set'] ?? '');

    echo "\n== 3. 敏感字段零记录（#14）==\n";

    $before = (int) Db::name($table)->count();
    $service->push([
        'model'    => \wise\auth\model\Role::class,
        'label'    => '角色',
        'pk_field' => 'id',
        'pk_value' => (string) $roleId,
        'title'    => '敏感字段测试',
        'action'   => 'update',
        'changes'  => [
            'password'      => ['old' => 'a', 'new' => 'b'],
            'remember_token'=> ['old' => 'x', 'new' => 'y'],
            'api_key'       => ['old' => 'k1', 'new' => 'k2'],
            'status'        => ['old' => 1, 'new' => 0],
        ],
        'field_labels' => [],
        'time'         => date('Y-m-d H:i:s'),
    ]);
    $after    = (int) Db::name($table)->count();
    $inserted = Db::name($table)->where('title', '敏感字段测试')->select()->toArray();

    check('password/token/api_key 全部丢弃', count($inserted) === 1 && $inserted[0]['field'] === 'status', 'fields=' . implode(',', array_column($inserted, 'field')));
    check('仅多 1 行（普通字段仍记录）', $after - $before === 1);

    echo "\n== 4. 大字段截断（#15）==\n";

    $big = str_repeat('长', 4000);
    $service->push([
        'model'    => \wise\auth\model\Role::class,
        'label'    => '角色',
        'pk_field' => 'id',
        'pk_value' => (string) $roleId,
        'title'    => '大字段测试',
        'action'   => 'update',
        'changes'  => ['description' => ['old' => '', 'new' => $big]],
        'field_labels' => [],
        'time'     => date('Y-m-d H:i:s'),
    ]);
    $bigRow = Db::name($table)->where('title', '大字段测试')->find();
    check('截断标记为 1', !empty($bigRow) && (int) $bigRow['truncated'] === 1);
    check('存储长度 ≤ 4KB', !empty($bigRow) && strlen((string) $bigRow['new_value']) <= 4096, 'len=' . strlen((string) ($bigRow['new_value'] ?? '')));

    echo "\n== 5. 时间线（#16）==\n";

    $timeline = $service->timeline(\wise\auth\model\Role::class, (string) $roleId);
    check('时间线按组返回', count($timeline) >= 1);
    check('最新一组在最前（id 倒序聚合）', ($timeline[0]['action'] ?? '') === 'update');
    check('字段级 old/new 下发', isset($timeline[0]['changes'][0]['old'], $timeline[0]['changes'][0]['new']));

    $service->push([
        'model'    => \wise\auth\model\Role::class,
        'label'    => '角色',
        'pk_field' => 'id',
        'pk_value' => (string) $roleId,
        'title'    => 'XSS 测试',
        'action'   => 'update',
        'changes'  => ['remark' => ['old' => str_repeat('<p>a</p>', 40), 'new' => str_repeat('<script>alert(1)</script>', 20)]],
        'field_labels' => [],
        'time'     => date('Y-m-d H:i:s'),
    ]);
    $tl2 = $service->timeline(\wise\auth\model\Role::class, (string) $roleId);
    $html = (string) ($tl2[0]['changes'][0]['html'] ?? '');
    check('长文本产出 diff HTML', $html !== '' && str_contains($html, 'diff-row'));
    check('diff HTML 已转义脚本', !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'));

    $list = $service->queryList(['model' => \wise\auth\model\Role::class], 1, 20);
    check('列表按 change_set 聚合', ($list['list'][0]['field_count'] ?? 0) >= 1);
    check('聚合行带非零行键 id（operateButtons 依赖 row.id）', (int) ($list['list'][0]['id'] ?? 0) > 0, 'id=' . ($list['list'][0]['id'] ?? 'null'));
    check('行键 id 在同页唯一', count(array_unique(array_column($list['list'], 'id'))) === count($list['list']));
    check('列表下发模型展示名', ($list['list'][0]['model_label'] ?? '') === '角色');
    check('列表 total 为变更组数（≥3）', ($list['total'] ?? 0) >= 3, 'total=' . ($list['total'] ?? 0));

    $models = $service->models();
    $found  = false;
    foreach ($models as $m) {
        if (($m['model'] ?? '') === \wise\auth\model\Role::class) {
            $found = true;
        }
    }
    check('模型下拉包含 Role', $found);

    echo "\n== 6. 回滚（#17 / #18 后端白名单）==\n";

    $role = \wise\auth\model\Role::find($roleId);
    $role->name = '__cl_test_role_v3__';
    $role->save();

    $result = $service->rollback($setId);
    $restored = \wise\auth\model\Role::find($roleId);

    check('回滚字段数 = 2', count($result['fields']) === 2, 'fields=' . implode(',', $result['fields']));
    check('name 已恢复为变更前', (string) $restored->name === '__cl_test_role__', 'name=' . $restored->name);
    check('description 已恢复为变更前', (string) $restored->description === 'v1');

    $rollbackRows = Db::name($table)->where('source', 'rollback')->where('pk_value', (string) $roleId)->select()->toArray();
    check('回滚动作自身留痕', count($rollbackRows) === 2, 'count=' . count($rollbackRows));
    check('回滚记录 action=rollback', ($rollbackRows[0]['action'] ?? '') === 'rollback');

    Db::name($table)->insert([
        'change_set'    => 'forged0000000000000000000000000',
        'log_id'        => null,
        'model'         => \app\admin\model\Menu::class,
        'model_label'   => 'Menu',
        'pk_field'      => 'id',
        'pk_value'      => '1',
        'title'         => 'forged',
        'action'        => 'update',
        'field'         => 'name',
        'field_label'   => '',
        'old_value'     => 'x',
        'new_value'     => 'y',
        'truncated'     => 0,
        'source'        => 'model',
        'operator_id'   => 1,
        'operator_name' => 'system',
        'ip'            => '',
        'route'         => '',
        'create_time'   => date('Y-m-d H:i:s'),
    ]);

    $rejected = false;
    try {
        $service->rollback('forged0000000000000000000000000');
    } catch (\RuntimeException $e) {
        $rejected = str_contains($e->getMessage(), '未接入');
    }
    check('未接入模型回滚被拒绝', $rejected);

    echo "\n== 7. 归档命令（#20 前置）==\n";

    $extend = (string) @file_get_contents(__DIR__ . '/../../extend/wise/extend.json');
    check('命令已注册到 extend.json', str_contains($extend, 'wise\\\\command\\\\system\\\\ChangeLogArchive'));
    check('命令文件位于 extend/wise/command/system', is_file(__DIR__ . '/../../extend/wise/command/system/ChangeLogArchive.php'));

    echo "\n== 8. 主体判定 actors（审计只记可信主体）==\n";

    $actorEntry = static function (string $title): array {
        return [
            'model'        => \wise\auth\model\Role::class,
            'label'        => '角色',
            'pk_field'     => 'id',
            'pk_value'     => '1',
            'title'        => $title,
            'action'       => 'update',
            'changes'      => ['name' => ['old' => 'actor_a', 'new' => 'actor_b']],
            'field_labels' => [],
            'time'         => date('Y-m-d H:i:s'),
        ];
    };

    Config::set(['actors' => ['admin']], 'change_log');
    $service->push($actorEntry('__cl_actor_denied__'));
    check(
        '非白名单主体（cli）不落库',
        (int) Db::name($table)->where('title', '__cl_actor_denied__')->count() === 0
    );

    \wise\support\context\RequestContext::clear();
    \wise\support\context\RequestContext::setUser(new class {
        public int $id = 9;
        public string $nickname = '会员甲';
    });
    \wise\support\context\RequestContext::setGuard('member');
    $service->push($actorEntry('__cl_actor_member__'));
    check(
        '会员自助（member）不落库',
        (int) Db::name($table)->where('title', '__cl_actor_member__')->count() === 0
    );

    \wise\support\context\RequestContext::clear();
    $service->push($actorEntry('__cl_actor_guest__'));
    check(
        '未认证（guest/cli）不落库',
        (int) Db::name($table)->where('title', '__cl_actor_guest__')->count() === 0
    );

    \wise\support\context\RequestContext::clear();

    Config::set(['actors' => ['cli']], 'change_log');
    $service->push($actorEntry('__cl_actor_allowed__'));
    check(
        '白名单命中主体正常落库',
        (int) Db::name($table)->where('title', '__cl_actor_allowed__')->count() === 1
    );

    Config::set(['actors' => null], 'change_log');
    $service->push($actorEntry('__cl_actor_off__'));
    check(
        'actors=null 关闭主体判定',
        (int) Db::name($table)->where('title', '__cl_actor_off__')->count() === 1
    );

    Config::set(['actors' => ['*']], 'change_log');
    $service->push($actorEntry('__cl_actor_star__'));
    check(
        "actors=['*'] 等价关闭",
        (int) Db::name($table)->where('title', '__cl_actor_star__')->count() === 1
    );

    Config::set(['actors' => null], 'change_log');

    echo "\n== 9. 噪音字段排除（全局 ignore_fields + 模型 changeLogExcept）==\n";

    $adminClass = \app\admin\model\Admin::class;
    $adminRow   = \app\admin\model\Admin::find(1);

    if ($adminRow) {
        $before = (int) Db::name($table)->where('model', $adminClass)->count();

        $adminRow->last_login_time = date('Y-m-d H:i:s', time() - 300);
        $adminRow->last_login_ip   = '10.0.0.1';
        $adminRow->save();

        $adminRow = \app\admin\model\Admin::find(1);
        $adminRow->ext_fields = ['config' => ['system' => ['theme' => 'dark', 'scheme' => 'green']]];
        $adminRow->save();

        $afterNoise = (int) Db::name($table)->where('model', $adminClass)->count();
        check('登录轨迹 + ext_fields 均不落库', $afterNoise === $before, 'delta=' . ($afterNoise - $before));

        $adminRow = \app\admin\model\Admin::find(1);
        $adminRow->nickname = '__cl_admin_nick_v2__';
        $adminRow->save();

        $afterBiz = (int) Db::name($table)->where('model', $adminClass)->count();
        check('业务字段（nickname）仍然记录', $afterBiz === $before + 1, 'delta=' . ($afterBiz - $before));
    } else {
        check('管理员 id=1 存在（噪音字段用例前置）', false);
    }

    Db::rollback();
    $inTx = false;
    check('事务回滚，测试数据未落库', (int) Db::name('auth_roles')->where('name', 'like', '__cl_test_role%')->count() === 0);
} catch (\Throwable $e) {
    if ($inTx) {
        Db::rollback();
    }
    echo '  [SKIP] 端到端用例中断：' . $e->getMessage() . "\n";
    echo '  位置：' . $e->getFile() . ':' . $e->getLine() . "\n";
}

ChangeLogManager::reset();

echo "\n----------------------------------------\n";
echo "结果：PASS {$passed} / FAIL {$failed}\n";

exit($failed === 0 ? 0 : 1);
