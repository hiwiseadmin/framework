<?php
namespace plugin\wisecms\service\engine;

use think\facade\Config;

/**
 * CMS 定时任务接入 wise-crontab
 *
 * wise-crontab 并不提供「运行时注册」API：任务声明在 config('wise-crontab.tasks')
 * 或数据库表（由 `php think wise:crontab:run` 统一调度）。
 *
 * 因此这里做「软注册」——把 CMS 内置任务合并进 wise-crontab.tasks 配置：
 *   - 任务定义仍归属插件自身，无需改动宿主的 config/wise-crontab.php；
 *   - 宿主同名的任务优先（以宿主意愿为准），仅补齐缺失项；
 *   - 宿主只需在系统 crontab 中配置一条：* * * * * cd /path/to/src && php think wise:crontab:run
 *
 */
class CrontabService
{
    /**
     * 合并内置任务到 wise-crontab 任务表（配置级幂等）
     */
    public function registerTasks(): void
    {
        // wise-crontab 未安装时静默跳过，不阻断插件启动
        if (!class_exists(\wise\crontab\CrontabManager::class)) {
            return;
        }

        $existing = config('wise-crontab.tasks', []);
        $existing = is_array($existing) ? $existing : [];

        // 以 name 为键去重：宿主已声明的任务优先
        $byName = [];
        foreach ($existing as $task) {
            if (is_array($task) && !empty($task['name'])) {
                $byName[(string) $task['name']] = $task;
            }
        }

        $changed = false;
        foreach ($this->getBuiltinTasks() as $task) {
            if (isset($byName[$task['name']])) {
                continue;
            }
            $byName[$task['name']] = $task;
            $changed = true;
        }

        if ($changed) {
            Config::set(['tasks' => array_values($byName)], 'wise-crontab');
        }
    }

    /**
     * CMS 内置定时任务（wise-crontab 任务结构）
     *
     * command 为完整 shell 命令行（wise-crontab 通过 Process::fromShellCommandline 执行），
     * 因此调度进程需在项目 src 目录下启动。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBuiltinTasks(): array
    {
        return [
            [
                'name'             => 'wisecms_publish_scheduled',
                'description'      => 'CMS定时发布：每分钟检查并发布排期内容',
                'command'          => 'php think wisecms:publish-scheduled',
                'cron_expression'  => '* * * * *',
                'timeout'          => 300,
                'max_retries'      => 1,
                'allow_concurrent' => false,
                'status'           => 1,
            ],
            [
                'name'             => 'wisecms_sitemap_regenerate',
                'description'      => 'CMS生成Sitemap：每天凌晨3点重新生成',
                'command'          => 'php think wisecms:sitemap:regenerate',
                'cron_expression'  => '0 3 * * *',
                'timeout'          => 900,
                'max_retries'      => 1,
                'allow_concurrent' => false,
                'status'           => 1,
            ],
            [
                'name'             => 'wisecms_seo_push',
                'description'      => 'CMS搜索引擎推送：每6小时推送新内容',
                'command'          => 'php think wisecms:seo:push all',
                'cron_expression'  => '0 */6 * * *',
                'timeout'          => 600,
                'max_retries'      => 1,
                'allow_concurrent' => false,
                'status'           => 1,
            ],
            [
                'name'             => 'wisecms_quality_rescore',
                'description'      => 'CMS质量重评分：每日4:00',
                'command'          => 'php think wisecms:quality:rescore',
                'cron_expression'  => '0 4 * * *',
                'timeout'          => 900,
                'max_retries'      => 0,
                'allow_concurrent' => false,
                'status'           => 1,
            ],
            [
                'name'             => 'wisecms_seo_audit_all',
                'description'      => 'CMSSEO审查：每日5:00',
                'command'          => 'php think wisecms:seo:audit-all',
                'cron_expression'  => '0 5 * * *',
                'timeout'          => 900,
                'max_retries'      => 0,
                'allow_concurrent' => false,
                'status'           => 1,
            ],
            [
                'name'             => 'wisecms_internal_link_rebuild',
                'description'      => 'CMS内链重建：每周日6:00',
                'command'          => 'php think wisecms:internal-link:rebuild',
                'cron_expression'  => '0 6 * * 0',
                'timeout'          => 900,
                'max_retries'      => 0,
                'allow_concurrent' => false,
                'status'           => 1,
            ],
            [
                'name'             => 'wisecms_version_cleanup',
                'description'      => 'CMS版本快照清理：每周日7:00按保留条数裁剪',
                'command'          => 'php think wisecms:version:cleanup',
                'cron_expression'  => '0 7 * * 0',
                'timeout'          => 900,
                'max_retries'      => 0,
                'allow_concurrent' => false,
                'status'           => 1,
            ],
        ];
    }

    /**
     * 获取 CMS 推荐定时任务列表（后台展示用，结构与原实现保持一致）
     */
    public function getSuggestedTasks(): array
    {
        $tasks = [];
        foreach ($this->getBuiltinTasks() as $task) {
            $tasks[] = [
                'command'  => (string) preg_replace('#^php think #', '', (string) $task['command']),
                'schedule' => (string) $task['cron_expression'],
                'desc'     => (string) $task['description'],
            ];
        }

        return $tasks;
    }
}
