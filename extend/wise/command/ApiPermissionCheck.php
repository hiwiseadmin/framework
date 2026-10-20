<?php
declare(strict_types=1);

namespace wise\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\console\Table;
use think\facade\Db;
use wise\auth\model\Permission;

/**
 * App API 权限 ident 对账命令
 *
 * 背景：/api/admin/* 的接口权限不为 API 单独建节点，ident 直接复用后台菜单派生的
 * 权限节点（config/menu_permission.php → path_to_dot，如 /admin/admin/index →
 * admin.admin.index）。这带来一类静默故障：
 *
 *   路由里写了一个库里不存在的 ident → 角色授权页里根本看不到这个节点 → 任何角色
 *   都无法被授予它 → 除超管（super_admin_ids / super_role）外**所有账号恒 403**，
 *   且响应与「真的没权限」完全一样，线上极难定位。
 *
 * 本命令把这类问题提前到提交期发现：
 *   1. 提取路由文件中 AdminApiPermission 声明的 ident 字面量；
 *   2. 校验节点是否存在 / 已软删除 / 已禁用（status != 1 无法被授权）；
 *   3. 校验节点是否被任何角色授予过（否则接口等于没人能用）；
 *   4. 节点不存在时给出最相近的已有 ident（did you mean）；
 *   5. 检测「无参挂载」——组级挂载 AdminApiPermission 不带 ident，
 *      在 fail_closed=true 下必然 403（组中间件先于路由中间件执行）。
 *
 * 用法：
 *   php think api:perm-check
 *   php think api:perm-check --show-ok
 *   php think api:perm-check --strict            # CI：有 ERROR 时退出码 1
 *   php think api:perm-check --route-file app/api/route/api.php
 */
class ApiPermissionCheck extends Command
{
    /**
     * 权限中间件类名
     *
     * 用文本扫描而非加载路由对象：ident 必须是字面量才能被静态对账，
     * 也因此本命令不依赖应用启动顺序与路由缓存。
     */
    protected const MIDDLEWARE_REF = 'AdminApiPermission::class';

    /**
     * did you mean 的最大编辑距离（超过则不提示，避免给出误导性建议）
     */
    protected const SUGGEST_MAX_DISTANCE = 4;

    protected function configure(): void
    {
        $this->setName('api:perm-check')
            ->setDescription('App API 权限 ident 对账：路由声明 vs auth_permissions')
            ->addOption(
                'route-file',
                null,
                Option::VALUE_OPTIONAL,
                '待扫描的路由文件（默认 app/api/route/api.php）',
                ''
            )
            ->addOption('strict', null, Option::VALUE_NONE, '存在 ERROR 时返回非 0（CI 用）')
            ->addOption('show-ok', null, Option::VALUE_NONE, '输出全部 ident 明细（默认只列问题项）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $raw  = (string) $input->getOption('route-file');
        $file = $this->resolveRouteFile($raw);

        if ($file === null) {
            $output->writeln('<error>路由文件不存在：' . ($raw === '' ? $this->defaultRouteFile() : $raw) . '</error>');

            return 1;
        }

        $output->writeln('<info>App API 权限对账</info>');
        $output->writeln('路由文件：' . $file);

        // 先剥注释再扫描：路由文件头部的约定说明里会提到 AdminApiPermission::class，
        // 直接文本统计会把文档当成「无参挂载」误报。用 token_get_all 剥，避免误伤
        // 字符串字面量（如 URL 中的 //），并保留换行以维持行号。
        $source = $this->stripComments((string) @file_get_contents($file));

        [$idents, $mountedBlocks] = $this->extractIdents($source);
        $refs = substr_count($source, self::MIDDLEWARE_REF);

        $output->writeln(sprintf(
            '声明扫描：AdminApiPermission 引用 %d 处，带 ident 的挂载 %d 处，唯一 ident %d 个',
            $refs,
            $mountedBlocks,
            count($idents)
        ));

        // 无参挂载：组级挂载（或漏写 ident）。组中间件先执行，必然拿不到 ident，
        // fail_closed=true 下等于整组恒 403。
        if ($refs > $mountedBlocks) {
            $output->writeln(sprintf(
                '<comment>[WARN] 有 %d 处 AdminApiPermission 引用未携带 ident 字面量：'
                . '若为组级挂载请改为路由级（组中间件先于路由中间件执行，见 Rule::getOption）</comment>',
                $refs - $mountedBlocks
            ));
        }

        if ($idents === []) {
            $output->writeln('<comment>未发现带 ident 的权限声明：当前 /api/admin 只有登录态接口，属正常</comment>');

            return 0;
        }

        try {
            $nodes   = $this->loadNodes(array_keys($idents));
            $granted = $this->loadGrantedRoleCounts(array_keys($idents));
            $allIdents = $this->loadAllActiveIdents();
        } catch (\Throwable $e) {
            $output->writeln('<error>数据库不可用或权限表缺失：' . $e->getMessage() . '</error>');

            return 1;
        }

        $rows  = [];
        $ok    = 0;
        $warn  = 0;
        $error = 0;

        // 按 ERROR → WARN → OK 排序输出，问题项永远在最前
        $ranked = [];
        foreach ($idents as $ident => $lines) {
            $node  = $nodes[$ident] ?? null;
            $roles = $granted[$ident] ?? 0;
            $line  = implode(',', $lines);

            if ($node === null) {
                $error++;
                $suggest = $this->suggest($ident, $allIdents);
                $ranked[] = [0, [
                    $ident,
                    '×',
                    '-',
                    $line,
                    'ERROR 节点不存在' . ($suggest !== null ? '（did you mean: ' . $suggest . '）' : ''),
                ]];
                continue;
            }

            if (!empty($node['delete_time'])) {
                $error++;
                $ranked[] = [0, [$ident, '软删除', (string) $roles, $line, 'ERROR 节点已软删除']];
                continue;
            }

            if ((int) ($node['status'] ?? 0) !== 1) {
                $error++;
                $ranked[] = [0, [$ident, '×', (string) $roles, $line, 'ERROR 节点已禁用（status != 1，无法被授权）']];
                continue;
            }

            if ($roles === 0) {
                $warn++;
                $ranked[] = [1, [$ident, '√', '0', $line, 'WARN 无任何角色被授予（接口目前仅超管可用）']];
                continue;
            }

            $ok++;
            $ranked[] = [2, [$ident, '√', (string) $roles, $line, 'OK']];
        }

        usort($ranked, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $showOk = (bool) $input->getOption('show-ok');
        foreach ($ranked as [$level, $row]) {
            if ($level === 2 && !$showOk) {
                continue;
            }
            $rows[] = $row;
        }

        if ($rows !== []) {
            $table = new Table();
            $table->setHeader(['ident', '节点', '已授权角色', '路由行', '结论']);
            $table->setRows($rows);
            $output->writeln('');
            // Command::table() 内部已 writeln 输出，返回值无需重复打印
            $this->table($table);
        }

        $output->writeln(sprintf(
            '合计 %d 个 ident：<info>OK %d</info> / <comment>WARN %d</comment> / <error>ERROR %d</error>',
            count($idents),
            $ok,
            $warn,
            $error
        ));

        if (!$showOk && $ok > 0) {
            $output->writeln('（OK 明细已折叠，加 --show-ok 查看）');
        }

        $strict = (bool) $input->getOption('strict');

        if ($error > 0) {
            $output->writeln($strict
                ? '<error>存在 ERROR，退出码 1</error>'
                : '<comment>存在 ERROR；加 --strict 可让 CI 失败</comment>');

            return $strict ? 1 : 0;
        }

        return 0;
    }

    /**
     * 默认待扫描的路由文件
     */
    protected function defaultRouteFile(): string
    {
        // app_path() / root_path() 会为目录用途在末尾补分隔符，文件路径必须 rtrim
        return rtrim(app_path('api/route/api.php'), '/\\');
    }

    /**
     * 解析路由文件路径
     *
     * 支持绝对路径、项目根相对路径（app/api/route/api.php）；
     * 默认指向 App API 路由文件。
     */
    protected function resolveRouteFile(string $file): ?string
    {
        if ($file === '') {
            $file = $this->defaultRouteFile();
        }

        $candidates = [$file];

        if (!$this->isAbsolutePath($file)) {
            $candidates[] = root_path($file);
            $candidates[] = app_path($file);
        }

        foreach ($candidates as $candidate) {
            // 相对路径经 root_path()/app_path() 拼接后末尾会带分隔符
            $candidate = rtrim($candidate, '/\\');

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 是否为绝对路径（兼容 Windows 盘符）
     */
    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    /**
     * 剥离注释，保留换行以维持行号
     *
     * token_get_all 只在解析到合法 PHP 时可用；路由文件本身即 PHP，安全。
     * 剥注释的目的：避免文档注释中出现的 "AdminApiPermission::class" 被当成真实挂载。
     */
    protected function stripComments(string $source): string
    {
        $result = '';

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $result .= $token;
                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $result .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }

            $result .= $token[1];
        }

        return $result;
    }

    /**
     * 从路由源码中提取 ident 声明
     *
     * 约定写法（均在 AdminApiPermission::class 之后）：
     *   [[AdminApiPermission::class, 'admin.admin.index']]
     *   [[AdminApiPermission::class, ['admin.admin.index', 'admin.role.index']]]
     *   [[AdminApiPermission::class, 'admin.admin.index,admin.role.index']]
     *
     * @param  string $source 路由源码
     * @return array{0: array<string, int[]>, 1: int} [ident => 行号列表, 带 ident 的挂载数]
     */
    protected function extractIdents(string $source): array
    {
        $pattern = '/' . preg_quote(self::MIDDLEWARE_REF, '/') . '\s*,\s*(\[[^\]]*\]|\'[^\']*\')/';

        if (!preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            return [[], 0];
        }

        $idents = [];
        $blocks = 0;

        foreach ($matches[1] as [$argument, $offset]) {
            $blocks++;

            $line = substr_count(substr($source, 0, $offset), "\n") + 1;

            // 从参数中取出所有字符串字面量，再按逗号拆分（兼容 'a,b' 写法）
            if (!preg_match_all("/'([^']*)'/", $argument, $quoted)) {
                continue;
            }

            foreach ($quoted[1] as $item) {
                foreach (explode(',', $item) as $ident) {
                    $ident = trim($ident);

                    if ($ident === '') {
                        continue;
                    }

                    $idents[$ident][] = $line;
                }
            }
        }

        // 同一 ident 在多个路由重复声明是合法的，行号去重即可
        foreach ($idents as $ident => $lines) {
            $idents[$ident] = array_values(array_unique($lines));
        }

        ksort($idents);

        return [$idents, $blocks];
    }

    /**
     * 读取权限节点（含软删除，用于区分「不存在」与「已删除」）
     *
     * @param  string[] $idents
     * @return array<string, array<string, mixed>> ident => 节点
     */
    protected function loadNodes(array $idents): array
    {
        $rows = Permission::withTrashed()
            ->whereIn('ident', $idents)
            ->field('id,ident,status,delete_time')
            ->select();

        $nodes = [];
        foreach ($rows as $row) {
            $nodes[(string) $row['ident']] = $row->toArray();
        }

        return $nodes;
    }

    /**
     * 统计每个 ident 被多少个角色授予过
     *
     * 与 RbacManager::resolveRolesPermissions() 同口径：
     *   只认 role_permission JOIN permissions 且 permissions.status = 1。
     *
     * @param  string[] $idents
     * @return array<string, int>
     */
    protected function loadGrantedRoleCounts(array $idents): array
    {
        $rows = Db::name('auth_role_permission')
            ->alias('rp')
            ->join('auth_permissions p', 'rp.permission_id = p.id')
            ->whereIn('p.ident', $idents)
            ->where('p.status', 1)
            ->field('p.ident, COUNT(DISTINCT rp.role_id) AS role_count')
            ->group('p.ident')
            ->select();

        $granted = [];
        foreach ($rows as $row) {
            $granted[(string) $row['ident']] = (int) $row['role_count'];
        }

        return $granted;
    }

    /**
     * 全部启用状态的 ident（用于 did you mean 建议）
     *
     * @return string[]
     */
    protected function loadAllActiveIdents(): array
    {
        return Permission::where('status', 1)->column('ident');
    }

    /**
     * 找出最相近的已有 ident
     *
     * @param string   $ident
     * @param string[] $candidates
     */
    protected function suggest(string $ident, array $candidates): ?string
    {
        $best     = null;
        $distance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $current = levenshtein($ident, (string) $candidate);

            if ($current < $distance) {
                $distance = $current;
                $best     = (string) $candidate;
            }
        }

        return $best !== null && $distance <= self::SUGGEST_MAX_DISTANCE ? $best : null;
    }
}
