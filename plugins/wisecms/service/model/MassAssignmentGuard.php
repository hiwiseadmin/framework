<?php
namespace plugin\wisecms\service\model;

/**
 * 批量赋值防护（P1-1）
 *
 * 背景：多处后台 CRUD 直接把 `$this->request->post()` 整包投给模型的
 * `create()/save()`，客户端可借此伪造 `id`、`create_time`、外键、计数列等
 * **非表单字段**（越权/数据伪造）。
 *
 * 策略：**黑名单 unset**，而非字段白名单。
 *   - 后台表单字段天然就是「模型列集合」，新增业务字段时黑名单无需维护；
 *   - 需要拦截的只是少量「框架/DB 托管列」与「服务端计数列」，
 *     恰好符合"要拦的少、要放的宽"这一形态；
 *   - 白名单（如 `request->only([...])`）在新增字段时极易漏改，导致字段静默丢弃。
 *
 * 使用：
 * ```php
 * $data = MassAssignmentGuard::filter($this->request->post(), 'wisecms_ad');
 * ```
 *
 * ⚠️ 新增后台 CRUD 表时，请在 {@see TABLE_DENY} 中登记该表的「服务端托管列」。
 */
final class MassAssignmentGuard
{
    /**
     * 基础黑名单：框架 / DB 托管，任何后台 CRUD 都不允许由请求体写入
     */
    const BASE_DENY = [
        'id',            // 主键：伪造 id 可越权覆盖他人记录
        'create_time',   // 由服务端写入
        'update_time',   // 由服务端写入
        'delete_time',   // 软删标记：客户端可借此「恢复」或隐藏记录
    ];

    /**
     * 各表追加黑名单：服务端统计 / 运行态列
     *
     * 这些列由系统累加或由任务调度写入，交由客户端写会破坏统计可信度。
     */
    const TABLE_DENY = [
        'wisecms_site'        => [],
        'wisecms_webhook'     => ['retry_count', 'last_status'],
        'wisecms_spider_task' => ['last_run_time', 'last_count', 'total_count'],
        // site_id 由服务端按当前站点写入（P1-B3 多站点隔离），不接受客户端指定
        'wisecms_page'        => ['view_count', 'site_id'],
        // 同上（P2：栏目创建/编辑由控制器注入 site_id，表单不可伪造）
        'wisecms_category'    => ['site_id'],
        // P2-03：is_system 为**服务端托管列** —— 只有内置模板安装器（installDefaultTemplates）
        // 能置 1，后台表单不得自行声明「我是系统模板」
        'wisecms_template'    => ['is_system'],
        // site_id 同上（P1-C：重定向已按站点隔离），由服务层取当前站点写入
        'wisecms_redirect'    => ['hit_count', 'site_id'],
        // 注：`wisecms_ad` 原登记的 view_count / click_count 为**死字段**（全插件无任何
        // 写入点、也无前台读取路径），已随 P1-D 死字段清理从建表迁移移除
    ];

    /**
     * 过滤请求体，剔除禁止客户端写入的字段
     *
     * @param array  $data  原始请求体（如 `$this->request->post()`）
     * @param string $table 目标表名（含插件前缀，如 `wisecms_ad`）
     * @return array 过滤后的数据
     */
    public static function filter(array $data, string $table): array
    {
        $deny = self::BASE_DENY;

        if (isset(self::TABLE_DENY[$table])) {
            $deny = array_merge($deny, self::TABLE_DENY[$table]);
        }

        foreach ($deny as $field) {
            unset($data[$field]);
        }

        return $data;
    }
}
