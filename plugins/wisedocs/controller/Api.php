<?php

declare(strict_types=1);

namespace plugin\wisedocs\controller;

use plugin\FrontController;
use plugin\wisedocs\model\Document as DocumentModel;
use plugin\wisedocs\service\ScheduleService;
use plugin\wisedocs\service\SpaceService;

/**
 * API 文档前台（P3-4）
 *
 * 由原后台式实现改为前台（FrontController）：免登录可读；
 * 导出接口改用框架 response()（不再 header()+exit 绕过框架）。
 */
class Api extends FrontController
{
    protected SpaceService $spaceService;

    protected function initPlugin(): void
    {
        $this->spaceService = new SpaceService($this->request);
        $this->spaceService->applyTheme();

        // 惰性发布到期的定时文档（P5-2）
        (new ScheduleService())->publishDue();
    }

    protected function apiQuery()
    {
        $query = DocumentModel::where('status', DocumentModel::STATUS_PUBLISHED)
            ->where('content_type', DocumentModel::CONTENT_TYPE_API);

        $projectId = $this->spaceService->currentId();
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }

        return $query;
    }

    /**
     * API 文档首页（按请求方法分组）
     */
    public function index()
    {
        $grouped = $this->groupedDocuments();

        $this->assign([
            'space'     => $this->spaceService->current(),
            'grouped'   => $grouped,
            'total'     => array_sum(array_map('count', $grouped)),
            'keyword'   => '',
            'title'     => 'API 文档',
            // 前台主题（视图）所需：主区宽度修饰类
            'mainClass' => 'wd-main__inner--wide',
        ]);

        return $this->fetch();
    }

    /**
     * 按请求方法分组的 API 文档（主区列表与侧栏导航共用）
     *
     * @return array<string, array<int, mixed>>
     */
    protected function groupedDocuments(): array
    {
        $documents = $this->apiQuery()
            ->with(['category'])
            ->order('sort_order DESC, id DESC')
            ->select();

        $grouped = [];
        foreach ($documents as $doc) {
            $method = strtoupper((string) ($doc->api_method ?: 'GET'));
            $grouped[$method][] = $doc;
        }

        return $grouped;
    }

    /**
     * API 文档详情
     */
    public function detail($slug = '')
    {
        $document = $this->apiQuery()->with(['category'])->where('slug', $slug)->find();

        if (!$document) {
            return $this->error('API 文档不存在');
        }

        $this->assign([
            'space'    => $this->spaceService->current(),
            'document' => $document,
            'category' => $document->category,
            'extra'    => $this->decodeExtra($document->extra_fields),
            'keyword'  => '',
            'title'    => $document->title . ' - API 文档',
            // 侧栏 API 导航 + 主区宽度
            'grouped'   => $this->groupedDocuments(),
            'mainClass' => 'wd-main__inner--wide',
        ]);

        return $this->fetch();
    }

    /**
     * 单篇 API 文档 JSON
     */
    public function json($slug = '')
    {
        $document = $this->apiQuery()->where('slug', $slug)->find();

        if (!$document) {
            return $this->error('API 文档不存在');
        }

        $extra = $this->decodeExtra($document->extra_fields);

        return $this->success('', [
            'id'          => $document->id,
            'title'       => $document->title,
            'method'      => $document->api_method,
            'endpoint'    => $document->api_endpoint,
            'description' => $document->excerpt,
            'content'     => $document->content,
            'request'     => $extra['request'] ?? [],
            'response'    => $extra['response'] ?? [],
            'examples'    => $extra['examples'] ?? [],
            'update_time' => $document->update_time,
        ]);
    }

    /**
     * 导出 OpenAPI 3.0
     */
    public function export()
    {
        $documents = $this->apiQuery()->with(['category'])->order('sort_order DESC, id DESC')->select();

        $openapi = [
            'openapi' => '3.0.0',
            'info'    => [
                'title'       => 'API 文档',
                'description' => '由 WiseDocs 自动生成',
                'version'     => '1.0.0',
            ],
            'servers' => [
                ['url' => $this->request->domain(), 'description' => '当前站点'],
            ],
            'paths'      => [],
            'components' => [
                'schemas'         => [],
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                ],
            ],
        ];

        foreach ($documents as $doc) {
            $path   = (string) ($doc->api_endpoint ?: '/');
            $method = strtolower((string) ($doc->api_method ?: 'GET'));
            $extra  = $this->decodeExtra($doc->extra_fields);

            if (!isset($openapi['paths'][$path])) {
                $openapi['paths'][$path] = [];
            }

            $operation = [
                'summary'     => $doc->title,
                'description' => (string) $doc->excerpt,
                'tags'        => [$doc->category->name ?? '未分类'],
                'responses'   => [
                    '200' => [
                        'description' => '成功',
                        'content'     => [
                            'application/json' => [
                                'schema' => [
                                    'type'       => 'object',
                                    'properties' => $this->formatSchema($extra['response'] ?? []),
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            if (!empty($extra['request'])) {
                $operation['parameters'] = $this->formatParameters($extra['request']);
            }

            if (in_array($method, ['post', 'put', 'patch'], true) && !empty($extra['request_body'])) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content'  => [
                        'application/json' => [
                            'schema' => [
                                'type'       => 'object',
                                'properties' => $this->formatSchema($extra['request_body']),
                            ],
                        ],
                    ],
                ];
            }

            $openapi['paths'][$path][$method] = $operation;
        }

        $json = json_encode($openapi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response((string) $json, 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="openapi.json"',
        ]);
    }

    /**
     * 解析 extra_fields JSON
     */
    protected function decodeExtra($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 格式化响应/请求体字段
     */
    protected function formatSchema(array $fields): array
    {
        $properties = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['name'])) {
                continue;
            }
            $properties[(string) $field['name']] = [
                'type'        => $this->mapType($field['type'] ?? 'string'),
                'description' => (string) ($field['description'] ?? ''),
            ];
        }

        return $properties;
    }

    /**
     * 格式化请求参数
     */
    protected function formatParameters(array $parameters): array
    {
        $result = [];
        foreach ($parameters as $param) {
            if (!is_array($param) || !isset($param['name'])) {
                continue;
            }
            $result[] = [
                'name'        => (string) $param['name'],
                'in'          => (string) ($param['in'] ?? 'query'),
                'description' => (string) ($param['description'] ?? ''),
                'required'    => (bool) ($param['required'] ?? false),
                'schema'      => ['type' => $this->mapType($param['type'] ?? 'string')],
            ];
        }

        return $result;
    }

    /**
     * 类型映射
     */
    protected function mapType($type): string
    {
        $map = [
            'string'  => 'string',
            'integer' => 'integer',
            'int'     => 'integer',
            'number'  => 'number',
            'float'   => 'number',
            'boolean' => 'boolean',
            'bool'    => 'boolean',
            'array'   => 'array',
            'object'  => 'object',
        ];

        return $map[strtolower((string) $type)] ?? 'string';
    }
}
