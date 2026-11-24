<?php

declare(strict_types=1);

namespace wise\plugin\adapter;

use think\App;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use wise\support\context\ContextualLogger;
use wise\plugin\command\migrate\Run as MigrateRun;
use wise\plugin\command\migrate\Rollback as MigrateRollback;
use wise\plugin\command\seed\Run as SeedRun;

/**
 * 数据库迁移执行器 — 直接调用迁移命令类执行插件迁移。
 *
 * 从 Console::call() 改为直接实例化，消除子进程开销。
 * 插件迁移/回滚/填充统一通过此类调度。
 */
class MigrationRunner
{
    /**
     * 插件目录根路径。
     *
     * @var string
     */
    protected string $pluginPath;

    /**
     * 应用实例（用于注入到命令对象）。
     *
     * @var App|null
     */
    protected ?App $app;

    /**
     * 请求级缓存：当前连接下的全部数据表名。
     *
     * 探测失败（库不可达等）时不写入缓存，下次调用重新尝试。
     *
     * @var string[]|null
     */
    protected ?array $allTables = null;

    public function __construct(?App $app = null)
    {
        $this->pluginPath = plugin_path();
        $this->app = $app;
    }

    /**
     * 为命令注入应用实例，并返回该命令。
     *
     * 直接实例化命令时，命令内部的 $this->app 默认为 null（正常流程由
     * Console::addCommand() 调用 setApp() 注入）。此处手动注入，避免
     * 在 getDbConfig() 等场景触发 "Attempt to read property config on null"。
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function makeCommand(string $class)
    {
        $command = new $class();
        if ($this->app !== null && method_exists($command, 'setApp')) {
            $command->setApp($this->app);
        }
        return $command;
    }

    /**
     * 执行数据库迁移。
     *
     * @param string $pluginIdent 插件标识
     * @return array ['success' => bool, 'message' => string]
     */
    public function migrate(string $pluginIdent): array
    {
        $migratePath = $this->pluginPath . $pluginIdent . '/database/migrations/';
        $migrateFiles = glob($migratePath . '*.php');
        if (!is_dir($migratePath) || $migrateFiles === false || count($migrateFiles) === 0) {
            return ['success' => true, 'message' => '无迁移文件，跳过'];
        }

        try {
            $input  = new Input(['--plugin', $pluginIdent]);
            $output = new Output('buffer');

            $command = $this->makeCommand(MigrateRun::class);
            $command->run($input, $output);

            $message = $output->fetch();
            ContextualLogger::info("插件迁移执行：{$pluginIdent}，输出：{$message}");
            return ['success' => true, 'message' => $message];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            ContextualLogger::error("插件迁移失败：{$pluginIdent}，错误：{$message}");
            return ['success' => false, 'message' => $message];
        }
    }

    /**
     * 判断插件是否存在迁移文件。
     *
     * @param string $pluginIdent 插件标识
     * @return bool
     */
    public function hasMigrations(string $pluginIdent): bool
    {
        $migratePath = $this->pluginPath . $pluginIdent . '/database/migrations/';
        if (!is_dir($migratePath)) {
            return false;
        }

        $migrateFiles = glob($migratePath . '*.php');

        return $migrateFiles !== false && count($migrateFiles) > 0;
    }

    /**
     * 插件迁移记录表是否已存在。
     *
     * ws_plugin_migrations 由 Phinx 适配器在首次执行插件迁移时自动创建
     * （PdoAdapter::setConnection() → createSchemaTable()），
     * 从未跑过插件迁移的站点上该表不存在，属正常状态，不是错误。
     *
     * @return bool
     */
    public function hasMigrationTable(): bool
    {
        try {
            return !empty(Db::query("SHOW TABLES LIKE '{$this->getMigrationTableName()}'"));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 获取插件迁移记录表名（含表前缀）。
     *
     * 借助 Db 自身解析前缀，保证与 getMigratedPlugins() 查询的是同一张表。
     *
     * @return string
     */
    protected function getMigrationTableName(): string
    {
        return (string) Db::name('plugin_migrations')->getTable();
    }

    /**
     * 获取所有已执行过迁移的插件标识。
     *
     * 单次查询 plugin_migrations 并按 plugin 去重，避免逐个插件查询造成 N+1。
     * 表不存在（全新环境尚未执行过任何插件迁移）时返回空数组。
     *
     * @return string[]
     */
    public function getMigratedPlugins(): array
    {
        if (!$this->hasMigrationTable()) {
            return [];
        }

        try {
            $rows = Db::name('plugin_migrations')->distinct(true)->field('plugin')->select();
        } catch (\Throwable $e) {
            return [];
        }

        $plugins = [];
        foreach ($rows as $row) {
            $plugin = (string) ($row['plugin'] ?? '');
            if ($plugin !== '') {
                $plugins[] = $plugin;
            }
        }

        return $plugins;
    }

    /**
     * 统计某插件在 plugin_migrations 中的记录条数。
     *
     * 与 getMigratedPlugins() 查的是同一张表；表不存在（站点从未执行过插件迁移）
     * 时返回 0。用于安装一致性自检：把"状态键 / 迁移记录 / 实际表"三处事实摆在一起。
     *
     * @param string $pluginIdent 插件标识
     * @return int
     */
    public function migrationCount(string $pluginIdent): int
    {
        if (!$this->hasMigrationTable()) {
            return 0;
        }

        try {
            return (int) Db::name('plugin_migrations')->where('plugin', $pluginIdent)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 删除某插件的全部迁移记录。
     *
     * 用于"清库重装"：`--all` 走文件法回滚后，记录表里可能仍留有本插件的记录，
     * 若不清掉，重新安装时 `plugin:migrate:run` 会认为"已执行"而**跳过 up()**，
     * 数据表不会被重建。
     *
     * @param string $pluginIdent 插件标识
     * @return int 删除的记录条数
     */
    public function clearMigrationRecords(string $pluginIdent): int
    {
        if (!$this->hasMigrationTable()) {
            return 0;
        }

        try {
            return (int) Db::name('plugin_migrations')->where('plugin', $pluginIdent)->delete();
        } catch (\Throwable $e) {
            ContextualLogger::warning("清理插件迁移记录失败：{$pluginIdent}，" . $e->getMessage());
            return 0;
        }
    }

    /**
     * 回滚数据库迁移。
     *
     * @param string $pluginIdent 插件标识
     * @param int    $target      回滚目标版本号，<=0 表示全部回滚
     * @return array ['success' => bool, 'message' => string]
     */
    public function rollback(string $pluginIdent, int $target = 0): array
    {
        $migratePath = $this->pluginPath . $pluginIdent . '/database/migrations/';
        $migrateFiles = glob($migratePath . '*.php');
        if (!is_dir($migratePath) || $migrateFiles === false || count($migrateFiles) === 0) {
            return ['success' => true, 'message' => '无迁移文件，跳过回滚'];
        }

        try {
            $params = $target > 0
                ? ['--plugin', $pluginIdent, '--target', (string) $target]
                : ['--plugin', $pluginIdent, '--all'];

            $input  = new Input($params);
            $output = new Output('buffer');

            $command = $this->makeCommand(MigrateRollback::class);
            $command->run($input, $output);

            $message = $output->fetch();
            ContextualLogger::info("插件回滚执行：{$pluginIdent}，输出：{$message}");

            if ($target <= 0) {
                $leftovers = $this->remainingTables($pluginIdent);
                if ($leftovers !== []) {
                    $message .= '；回滚后仍存在的数据表：' . implode(', ', $leftovers);
                    ContextualLogger::warning(
                        "插件回滚不彻底：{$pluginIdent}，仍存在数据表 " . implode(', ', $leftovers)
                    );
                    return ['success' => false, 'message' => $message];
                }
            }

            return ['success' => true, 'message' => $message];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            ContextualLogger::error("插件回滚失败：{$pluginIdent}，错误：{$message}");
            return ['success' => false, 'message' => $message];
        }
    }

    /**
     * 提取插件「建表迁移」声明创建的数据表名（不含表前缀）。
     *
     * 只认建表迁移：文件名形如 20251028000001_create_xxx_table.php。
     * 刻意忽略 add_* / repair_* / convert_* / insert_* 等文件 —— 它们里的
     * `->table('已有表')` 指向 ALTER / 写入目标，可能是框架表（如 attachment），
     * 当成"本插件的表"会造成误判。
     *
     * 表名优先取文件内第一处 `$this->table('xxx')`（真实建表名），
     * 取不到时回落到文件名推导（create_ 与 _table 之间的部分）。
     *
     * @param string $pluginIdent 插件标识
     * @return string[] 表名（不含前缀，已去重）
     */
    public function tableNames(string $pluginIdent): array
    {
        $migratePath = $this->pluginPath . $pluginIdent . '/database/migrations/';
        $files = glob($migratePath . '*.php');
        if ($files === false) {
            return [];
        }

        $tables = [];
        foreach ($files as $file) {
            if (preg_match('/_create_(.+?)_table\.php$/i', basename($file), $matches) !== 1) {
                continue;
            }

            $table   = $matches[1];
            $content = @file_get_contents($file);
            if (is_string($content)
                && preg_match('/->table\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $content, $tableMatch) === 1
            ) {
                $table = $tableMatch[1];
            }

            $tables[$table] = true;
        }

        return array_keys($tables);
    }

    /**
     * 查询「本插件声明创建、且当前库中仍然存在」的数据表。
     *
     * 用于卸载回滚后的残留校验：命令行输出在 buffer 里，命令内部的早退
     * 无法通过异常感知，只有落到"表还在不在"才是可信结论。
     *
     * @param string $pluginIdent 插件标识
     * @return string[] 实际存在的完整表名（含表前缀）
     */
    public function remainingTables(string $pluginIdent): array
    {
        $tables = $this->tableNames($pluginIdent);
        if ($tables === []) {
            return [];
        }

        $all = $this->allTables();
        if ($all === []) {
            return [];
        }

        $remaining = [];
        foreach ($tables as $table) {
            foreach ($all as $fullTable) {
                if ($fullTable === $table || str_ends_with($fullTable, $table)) {
                    $remaining[$fullTable] = true;
                    break;
                }
            }
        }

        return array_keys($remaining);
    }

    /**
     * 直接物理 DROP 指定数据表（清库重装的兜底手段）。
     *
     * 【为什么必须兜底】Phinx 的 `Table::drop()` 只是把 DropTable 动作**入队**，
     * 必须再调一次 `save()` 才真正执行（见 `Phinx\Db\Table::drop()`）：
     *     $this->table('x')->drop();          // ← 什么都不做，静默无效
     *     $this->table('x')->drop()->save();  // ← 正确写法
     * 仓内 19 个插件迁移文件的 down() 漏了这个 save()（match3game 19 / pipe-game 1；
     * 原 pluginhub 的 6 个文件已在迁包过程中整改为 `hasTable() ? drop()->save()`，
     * 现随 wise-market 包走 `migrate:run/rollback`），于是 rollback 会"报成功 1 个"却一张表都没删，
     * 表全部残留 —— 这正是"卸载完再安装撞 1050 Table already exists"的历史根因。
     * 「清库重装」的语义就是"把残留清干净"，故此处按插件自己声明的表名直接删，
     * 保证用户不会卡在"点安装报错、点卸载没反应"的死角。
     *
     * 安全：只删 `remainingTables()` 返回的表名（来源是插件自己的建表迁移），
     * 且做表名白名单校验，杜绝拼接注入。
     *
     * @param string[] $tables 完整表名（含前缀）
     * @return string[] 实际执行删除的表名
     */
    public function dropTables(array $tables): array
    {
        $dropped = [];

        foreach ($tables as $table) {
            $table = trim((string) $table);

            if ($table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                ContextualLogger::warning("清库重装：跳过非法表名 {$table}");
                continue;
            }

            try {
                Db::execute(sprintf('DROP TABLE IF EXISTS `%s`', $table));
                $dropped[] = $table;
                ContextualLogger::info("清库重装：已删除残留数据表 {$table}");
            } catch (\Throwable $e) {
                ContextualLogger::error("清库重装：删除数据表 {$table} 失败，" . $e->getMessage());
            }
        }

        if ($dropped !== []) {
            $this->allTables = null;
        }

        return $dropped;
    }

    /**
     * 获取当前连接下的全部数据表名（请求级缓存）。
     *
     * 用 SHOW TABLES 全量取回而不是 `SHOW TABLES LIKE ?`：后者在部分
     * MySQL/PDO 组合下不支持参数占位符；表数量级很小，全量取回无成本。
     *
     * @return string[]
     */
    protected function allTables(): array
    {
        if ($this->allTables !== null) {
            return $this->allTables;
        }

        $tables = [];
        try {
            foreach (Db::query('SHOW TABLES') as $row) {
                $name = is_array($row) ? reset($row) : $row;
                if (is_string($name) && $name !== '') {
                    $tables[] = $name;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $this->allTables = $tables;
    }

    /**
     * 执行数据填充。
     *
     * @param string $pluginIdent 插件标识
     * @return array ['success' => bool, 'message' => string]
     */
    public function seed(string $pluginIdent): array
    {
        $seedPath = $this->pluginPath . $pluginIdent . '/database/seeds/';
        $seedFiles = glob($seedPath . '*.php');
        if (!is_dir($seedPath) || $seedFiles === false || count($seedFiles) === 0) {
            return ['success' => true, 'message' => '无 Seeder 文件，跳过'];
        }

        try {
            $input  = new Input(['--plugin', $pluginIdent]);
            $output = new Output('buffer');

            $command = $this->makeCommand(SeedRun::class);
            $command->run($input, $output);

            $message = $output->fetch();
            ContextualLogger::info("插件 Seeder 执行：{$pluginIdent}，输出：{$message}");
            return ['success' => true, 'message' => $message];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            ContextualLogger::error("插件 Seeder 失败：{$pluginIdent}，错误：{$message}");
            return ['success' => false, 'message' => $message];
        }
    }
}
