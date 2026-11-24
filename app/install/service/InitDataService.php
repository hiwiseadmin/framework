<?php
declare(strict_types=1);

namespace app\install\service;

use think\facade\App;
use think\facade\Db;

/**
 * 初始化数据导入服务
 *
 * 职责：安装期将框架级默认数据（SQL 文件）导入数据库。
 *
 * 设计原则：
 *   1. 数据内容（如系统级配置项）属于框架资产，由框架层统一维护，
 *      与 wise-config 等业务包解耦（不侵入包、不依赖包契约）。
 *   2. 连接复用框架 Db 门面（执行时框架数据库配置已就绪），不再手动创建 PDO。
 *   3. 占位符替换：__PREFIX__ → 表前缀（从 Db 连接配置读取），
 *      __SITE_NAME__ / __ADMIN_EMAIL__ 等 → 安装期动态值。
 *      注意：Db::execute() 执行的是原生 SQL，不会自动处理表前缀，故仍需替换 __PREFIX__。
 *   4. 按分号切分逐条执行，规避 PDO 默认不支持多语句的限制。
 *
 * 幂等性：SQL 文件内使用 INSERT IGNORE（依赖目标表的唯一索引），重复执行不会重复插入。
 */
class InitDataService
{
    /**
     * SQL 文件目录（相对应用根路径）
     */
    private const SQL_DIR = 'app' . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'data';

    /**
     * 导入初始化数据
     *
     * @param array       $replace 动态占位符替换表，如 ['__SITE_NAME__' => '站点名', '__ADMIN_EMAIL__' => 'a@b.com']
     * @param string|null $prefix  表前缀；为 null/空时从框架数据库默认连接配置读取
     * @return array{success:bool, executed:int, errors:string[]}
     */
    public static function import(array $replace = [], ?string $prefix = null): array
    {
        $sqlDir = App::getRootPath() . self::SQL_DIR;

        if (!is_dir($sqlDir)) {
            return ['success' => false, 'executed' => 0, 'errors' => ['初始化 SQL 目录不存在：' . $sqlDir]];
        }

        $files = glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql');
        if (empty($files)) {
            return ['success' => false, 'executed' => 0, 'errors' => ['初始化 SQL 目录下无 .sql 文件：' . $sqlDir]];
        }
        sort($files);

        if (null === $prefix || '' === $prefix) {
            $prefix = (string) (config('database.connections.mysql.prefix') ?: 'ws_');
        }

        $dynamic = [];
        foreach ($replace as $key => $value) {
            $dynamic[$key] = addslashes((string) $value);
        }
        $replaceAll = array_merge(['__PREFIX__' => $prefix], $dynamic);

        $executed = 0;
        $errors   = [];

        foreach ($files as $file) {
            $result = self::importFile($file, $replaceAll);
            $executed += $result['executed'];
            foreach ($result['errors'] as $error) {
                $errors[] = $error;
            }
        }

        return [
            'success'  => empty($errors),
            'executed' => $executed,
            'errors'   => $errors,
        ];
    }

    /**
     * 导入单个 SQL 文件
     *
     * @param string $file
     * @param array  $replace
     * @return array{executed:int, errors:string[]}
     */
    protected static function importFile(string $file, array $replace): array
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return ['executed' => 0, 'errors' => ['读取 SQL 文件失败：' . $file]];
        }

        $content    = strtr($content, $replace);
        $statements = self::splitStatements($content);

        $executed = 0;
        $errors   = [];

        foreach ($statements as $sql) {
            try {
                Db::execute($sql);
                $executed++;
            } catch (\Throwable $e) {
                $errors[] = sprintf('[%s] %s', basename($file), $e->getMessage());
            }
        }

        return ['executed' => $executed, 'errors' => $errors];
    }

    /**
     * 按分号切分 SQL 语句（去除行注释后）
     *
     * 基础实现，适用于不含存储过程 / 触发器、字符串中不含分号的受控 SQL 文件。
     *
     * @param string $content
     * @return string[]
     */
    protected static function splitStatements(string $content): array
    {
        $content = preg_replace('/^\s*(--|#).*$/m', '', $content);

        $statements = [];
        foreach (explode(';', $content) as $part) {
            $sql = trim($part);
            if ($sql !== '') {
                $statements[] = $sql;
            }
        }

        return $statements;
    }
}
