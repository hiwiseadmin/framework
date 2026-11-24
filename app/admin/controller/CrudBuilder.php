<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\controller\BaseController;
use think\facade\Db;
use wise\auth\attribute\AuthPermission;
use wise\crud\CrudGenerator;
use wise\crud\CrudMap;
use wise\crud\CrudPlanBuilder;
use wise\crud\CrudWriter;
use wise\crud\TableIntrospector;

/**
 * CRUD 构建器（后台可视化入口）
 *
 * 设计原则：**控制器只做参数校验与服务编排，不持有任何生成逻辑**。
 * 三段式服务（`CrudPlanBuilder` → `CrudGenerator` → `CrudWriter`）与 CLI
 * `wise:crud` 完全同源，因此「页面预览」与「命令行 dry-run」结果必然一致，
 * 不会出现第二套判定规则。
 *
 * 安全边界：
 *   - 表名 / 类名 / 插件标识全部白名单校验（它们会进入 SQL、命名空间与文件路径）；
 *   - 落盘路径由 `CrudWriter::artifactsFor()` 决定，控制器不拼接任何路径；
 *   - 写操作（generate / saveMap）只接受 POST，依赖 admin 应用既有的
 *     `VerifyCsrfToken` 中间件（见 `app/admin/middleware.php` 第 7 项）；
 *   - 数据库字段注释原样返回，前端必须做转义后再插入 DOM（见 crud_builder.js）。
 *
 * @package app\admin\controller
 */
class CrudBuilder extends BaseController
{
    /** 允许的表名形态：字母数字下划线，且不以数字开头 */
    private const TABLE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * 页面
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 数据表清单（左侧栏）
     *
     * GET /admin/crud_builder/tables
     */
    #[AuthPermission(
        ident: 'admin.crud_builder.tables',
        name: 'CRUD 构建器 - 表清单',
        type: 'route',
        route: '/admin/crud_builder/tables',
        method: 'GET'
    )]
    public function tables()
    {
        try {
            $introspector = new TableIntrospector();
            $rows         = $introspector->tables();
        } catch (\Throwable $e) {
            return $this->fail('读取数据表失败', $e);
        }

        $map      = CrudMap::fromConfig();
        $declared = array_flip($map->isEnabled() ? $map->tables() : []);

        $list = [];

        foreach ($rows as $row) {
            $list[] = [
                'name'     => $row['name'],
                'comment'  => $row['comment'],
                'declared' => isset($declared[$row['name']]),
            ];
        }

        return $this->success('ok', [
            'tables'  => $list,
            'total'   => count($list),
            'map'     => [
                'enabled' => $map->isEnabled(),
                'tables'  => $map->isEnabled() ? $map->tables() : [],
            ],
        ]);
    }

    /**
     * 判定计划（中栏字段表）
     *
     * GET/POST /admin/crud_builder/plan
     */
    #[AuthPermission(
        ident: 'admin.crud_builder.plan',
        name: 'CRUD 构建器 - 判定计划',
        type: 'route',
        route: '/admin/crud_builder/plan',
        method: 'GET'
    )]
    public function plan()
    {
        try {
            $plan = $this->buildPlan();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('推导失败', $e);
        }

        return $this->success('ok', [
            'plan'  => $plan,
            'stats' => $this->stats($plan),
        ]);
    }

    /**
     * 产物预览（右侧抽屉）：**不写任何文件**
     *
     * POST /admin/crud_builder/preview
     */
    #[AuthPermission(
        ident: 'admin.crud_builder.preview',
        name: 'CRUD 构建器 - 产物预览',
        type: 'route',
        route: '/admin/crud_builder/preview',
        method: 'POST'
    )]
    public function preview()
    {
        try {
            $plan = $this->buildPlan();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('推导失败', $e);
        }

        $generator = new CrudGenerator();
        $writer    = new CrudWriter($generator);
        $variables = $generator->variables($plan);
        $root      = $this->app->getRootPath();

        $artifacts = [];

        foreach ($writer->artifactsFor($plan) as $artifact) {
            $relative = strtr($artifact['path'], $variables);
            $content  = $generator->render($artifact['stub'], $variables);

            if ($content === null) {
                $artifacts[] = [
                    'path'     => $relative,
                    'stub'     => $artifact['stub'],
                    'status'   => 'missing_stub',
                    'required' => $artifact['required'],
                    'content'  => '',
                    'existing' => '',
                ];
                continue;
            }

            $absolute = $root . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $exists   = is_file($absolute);

            $artifacts[] = [
                'path'     => $relative,
                'stub'     => $artifact['stub'],
                'status'   => $exists ? 'overwrite' : 'new',
                'required' => $artifact['required'],
                'content'  => $content,
                'existing' => $exists ? (string) file_get_contents($absolute) : '',
            ];
        }

        return $this->success('ok', [
            'plan'      => $plan,
            'stats'     => $this->stats($plan),
            'artifacts' => $artifacts,
        ]);
    }

    /**
     * 落盘
     *
     * POST /admin/crud_builder/generate
     */
    #[AuthPermission(
        ident: 'admin.crud_builder.generate',
        name: 'CRUD 构建器 - 生成代码',
        type: 'route',
        route: '/admin/crud_builder/generate',
        method: 'POST'
    )]
    public function generate()
    {
        if (!$this->request->isPost()) {
            return $this->error('生成操作只接受 POST');
        }

        try {
            $plan = $this->buildPlan();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('推导失败', $e);
        }

        $generator = new CrudGenerator();
        $writer    = new CrudWriter($generator);

        try {
            $result = $writer->write($plan, [
                'force'          => (bool) $this->request->post('force', false),
                'skip'           => array_values(array_filter([
                    $this->request->post('no_js') ? 'no-js' : null,
                    $this->request->post('no_menu') ? 'no-menu' : null,
                ])),
                'merge_manifest' => (bool) $this->request->post('merge_manifest', false),
            ]);
        } catch (\Throwable $e) {
            return $this->fail('写入失败', $e);
        }

        if ($result['required_missing'] !== []) {
            return $this->error('模板缺失，必需产物未生成：' . implode(', ', $result['required_missing']));
        }

        if ($result['fatal']) {
            return $this->error('没有任何文件被生成，请检查模板目录：' . $generator->stubDir());
        }

        return $this->success(sprintf('完成：新增 %d，跳过 %d', count($result['written']), count($result['skipped'])), [
            'written' => $result['written'],
            'skipped' => $result['skipped'],
            'missing' => $result['missing'],
        ]);
    }

    /**
     * 仅保存声明映射（不改代码）
     *
     * POST /admin/crud_builder/save_map
     */
    #[AuthPermission(
        ident: 'admin.crud_builder.save_map',
        name: 'CRUD 构建器 - 保存字段映射',
        type: 'route',
        route: '/admin/crud_builder/save_map',
        method: 'POST'
    )]
    public function saveMap()
    {
        if (!$this->request->isPost()) {
            return $this->error('保存映射只接受 POST');
        }

        $table = trim((string) $this->request->post('table', ''));

        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            return $this->error('表名非法：' . $table);
        }

        $fields    = $this->postArray('field');
        $relations = $this->postArray('relation');

        $declaredFields    = [];
        $declaredRelations = [];

        try {
            foreach ($fields as $spec) {
                $parts = explode(':', (string) $spec, 3);

                if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                    continue;
                }

                $override = ['type' => $parts[1]];
                $options  = \wise\crud\CommentOptionParser::parse($parts[2] ?? '');

                if ($options !== []) {
                    $override['options'] = $options;
                }

                $declaredFields[$parts[0]] = $override;
            }

            foreach ($relations as $raw) {
                if (trim((string) $raw) === '') {
                    continue;
                }

                $parsed = CrudMap::parseRelation((string) $raw);

                $declaredRelations[$parsed['field']] = [
                    'table' => $parsed['table'],
                    'value' => $parsed['value'],
                    'label' => $parsed['label'],
                ];
            }
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        $result = CrudMap::mergeInto(
            $this->app->getRootPath() . 'config' . DIRECTORY_SEPARATOR . 'crud_map.php',
            $table,
            $declaredFields,
            $declaredRelations
        );

        if ($result['status'] === 'error') {
            return $this->error($result['message']);
        }

        return $this->success($result['message'], [
            'status'  => $result['status'],
            'path'    => $result['path'],
            'snippet' => $result['snippet'],
        ]);
    }


    /**
     * 统一失败出口
     *
     * 原始异常只进日志：数据库异常常带 SQLSTATE、库名、表结构片段与服务器路径，
     * 对使用者没有价值，却是截图/工单里的泄露面。用户输入类错误走
     * `InvalidArgumentException` 分支，仍原样返回（那是可操作的提示）。
     *
     * @param string      $action 动作描述（如「推导失败」）
     * @param \Throwable  $e      原始异常
     * @return mixed
     */
    protected function fail(string $action, \Throwable $e)
    {
        \think\facade\Log::error('[crud-builder] ' . $action . ': ' . $e->getMessage(), [
            'exception' => $e,
        ]);

        return $this->error($action . '，请查看服务器日志');
    }


    /**
     * 从请求构造判定计划（预览与生成共用，避免两处参数解析漂移）。
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException 参数非法时
     */
    protected function buildPlan(): array
    {
        $table = trim((string) $this->request->param('table', ''));

        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            throw new \InvalidArgumentException('表名非法（需字母或下划线开头，仅含字母、数字、下划线）：' . $table);
        }

        return (new CrudPlanBuilder())->build($table, [
            'class'         => (string) $this->request->param('class', ''),
            'title'         => (string) $this->request->param('title', ''),
            'module'        => (string) $this->request->param('module', 'admin'),
            'plugin'        => (string) $this->request->param('plugin', ''),
            'field'         => $this->paramArray('field'),
            'relation'      => $this->paramArray('relation'),
            'ignore_fields' => (string) $this->request->param('ignore_fields', ''),
            'crud_map'      => $this->request->param('no_map') ? new CrudMap([], false) : null,
            'connection'    => (string) Db::getConfig('default', 'mysql'),
        ]);
    }

    /**
     * 计划 → 统计（供前端顶栏与徽标使用）。
     *
     * @param array<string, mixed> $plan
     * @return array<string, int>
     */
    protected function stats(array $plan): array
    {
        $stats = ['declared' => 0, 'heuristic' => 0, 'fallback' => 0, 'skipped' => 0, 'warnings' => 0];

        foreach ((array) $plan['fields'] as $field) {
            if (!empty($field['warnings'])) {
                $stats['warnings'] += count((array) $field['warnings']);
            }

            if (!empty($field['skip_form'])) {
                $stats['skipped']++;
                continue;
            }

            match ((string) ($field['layer'] ?? '')) {
                'P0' => $stats['declared']++,
                'P1', 'P2', 'P3' => $stats['heuristic']++,
                default => $stats['fallback']++,
            };
        }

        return $stats;
    }

    /**
     * 读取 POST 数组参数（兼容 `field[]=a&field[]=b` 与 JSON body）。
     *
     * @return array<int, string>
     */
    protected function postArray(string $key): array
    {
        return $this->normalizeArray($this->request->post($key, []));
    }

    /**
     * 读取任意方法数组参数。
     *
     * @return array<int, string>
     */
    protected function paramArray(string $key): array
    {
        return $this->normalizeArray($this->request->param($key, []));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    protected function normalizeArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== ''
        ));
    }
}
