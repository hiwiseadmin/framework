<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3LevelDef;
use plugin\match3game\model\Match3Element;
use plugin\match3game\model\Match3Prop;
use plugin\match3game\service\level\LevelSchemaFactory;
use plugin\match3game\service\level\LevelValidator;
use wise\support\context\ContextualLogger;

/**
 * 关卡管理（Level Schema v1）
 *
 * 关卡 CRUD + 三层字符地图可视化编辑（terrain / entity / overlay）+ 保存前 Schema 校验。
 * 协议见 docs/match3/01-level-layout-design.md §4。
 */
class Level extends Base
{
    /** 允许写入的字段白名单 */
    protected const ALLOWED = [
        'code', 'name', 'chapter', 'difficulty', 'description',
        'schema_ver', 'version', 'cell_size', 'board_rows', 'board_cols',
        'regions', 'portals', 'rules', 'palette',
        'behaviours', 'actors', 'props', 'generation',
        'seed_policy', 'fixed_seed', 'reward_conf',
        'pre_unlock', 'unlock_stars', 'sort_order', 'status',
    ];

    /** 需要 JSON 解码的字段（textarea 提交字符串 → 数组） */
    protected const JSON_FIELDS = [
        'regions', 'portals', 'rules', 'palette',
        'behaviours', 'actors', 'props', 'generation', 'reward_conf',
    ];

    /**
     * 关卡列表页
     */
    public function index()
    {
        // 章节候选取自全部状态：草稿/下架关卡所属的章节同样要能被筛选到，
        // 否则软删后的关卡即使列出来也无法按章节定位。
        $chapters = Match3LevelDef::field('chapter')
            ->where('chapter', '<>', '')
            ->group('chapter')
            ->order('chapter')
            ->column('chapter');

        return $this->assign(['chapters' => $chapters])->fetch();
    }

    /**
     * 列表数据（bootstrap-table）
     */
    public function getList()
    {
        $page     = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 20);
        $chapter  = $this->request->param('chapter', '');
        $keyword  = $this->request->param('keyword', '');
        $status   = $this->request->param('status', '');

        // 基查询用 1=1 而非 status=1：
        // 删除是软删（status=0 草稿），若列表恒定只查已发布，删掉的关卡就再也找不回来。
        // status 传空 = 不筛选（全部状态），草稿/下架都能被检索到并改回已发布。
        $query = Match3LevelDef::where('1=1');
        if ($chapter !== '') {
            $query->where('chapter', $chapter);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('code', "%{$keyword}%")->whereOrLike('name', "%{$keyword}%");
            });
        }
        if ($status !== '') {
            $query->where('status', (int) $status);
        }

        $list = $query->order('sort_order', 'asc')->order('code', 'asc')
            ->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row               = $item->toArray();
            $rules             = (array) ($row['rules'] ?? []);
            $row['goal_text']  = $this->describeGoals((array) ($rules['goals'] ?? []));
            $row['moves']      = (int) ($rules['moves'] ?? 0);
            $rows[]            = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    /**
     * 新增/编辑关卡（保存前执行 Level Schema 校验，01 §10.3）
     */
    public function save()
    {
        $data = $this->request->post();
        $id   = (int) ($data['id'] ?? 0);

        $data = array_intersect_key($data, array_flip(self::ALLOWED));

        if (empty($data['code']) || empty($data['name'])) {
            return $this->error('关卡编码与名称不能为空');
        }

        // JSON 字段：textarea 提交字符串 → 数组（模型 $json 自动序列化）
        foreach (self::JSON_FIELDS as $f) {
            if (!array_key_exists($f, $data)) {
                continue;
            }
            if (is_string($data[$f])) {
                if (trim($data[$f]) === '') {
                    $data[$f] = null;
                    continue;
                }
                $decoded = json_decode($data[$f], true);
                if ($decoded === null) {
                    return $this->error("字段 {$f} 的 JSON 格式非法");
                }
                $data[$f] = $decoded;
            }
        }

        // —— Schema 校验（不合法直接拒存）——
        $schema = LevelSchemaFactory::fromRow($data);
        $errors = LevelValidator::validate($schema);
        if ($errors !== []) {
            return $this->error('关卡校验不通过：' . implode('；', $errors));
        }

        // 校验通过后，由工厂把 schema 拆回数据库列（冗余尺寸列自动推导）
        $data = array_merge($data, LevelSchemaFactory::toRow($schema, $data));

        try {
            if ($id > 0) {
                $level = Match3LevelDef::find($id);
                if (!$level) {
                    return $this->error('关卡不存在');
                }
                $level->save($data);
            } else {
                $level = new Match3LevelDef();
                $level->save($data);
            }

            return $this->success('保存成功', ['id' => $level->id]);
        } catch (\Throwable $e) {
            ContextualLogger::error('[match3] Level save error: ' . $e->getMessage());
            return $this->error('保存失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除关卡（软删 status=0）
     */
    public function delete()
    {
        $ids = $this->request->post('ids');
        if (empty($ids)) {
            return $this->error('请选择要删除的关卡');
        }
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        try {
            Match3LevelDef::whereIn('id', $ids)->update(['status' => 0]);
            return $this->success('删除成功');
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 编辑器辅助数据：元素字典 + 道具字典 + 字符图例
     */
    public function getMeta()
    {
        $elements = Match3Element::where('status', 1)->order('sort_order')->select()->toArray();
        $props    = Match3Prop::where('status', 1)->select()->toArray();

        return $this->success('', [
            'elements' => $elements,
            'props'    => $props,
            'legend'   => [
                'terrain' => LevelValidator::TERRAIN_CHARS,
                'entity'  => LevelValidator::ENTITY_CHARS,
                'overlay' => LevelValidator::OVERLAY_CHARS,
            ],
        ]);
    }

    /**
     * 关卡详情（编辑器回填）
     */
    public function detail()
    {
        $id    = $this->request->param('id/d', 0);
        $level = Match3LevelDef::find($id);
        if (!$level) {
            return $this->error('关卡不存在');
        }
        return $this->success('', $level->toArray());
    }

    /**
     * 免保存校验：提交关卡各段 JSON，返回错误列表
     */
    public function check()
    {
        $data   = $this->request->post();
        $schema = LevelSchemaFactory::fromRow(is_array($data) ? $data : []);
        $errors = LevelValidator::validate($schema);

        return $this->success('', [
            'valid'        => $errors === [],
            'errors'       => $errors,
            'fingerprint'  => LevelSchemaFactory::fingerprint($schema),
        ]);
    }

    /**
     * 描述通关目标（列表摘要）
     */
    protected function describeGoals(array $goals): string
    {
        $parts = [];
        foreach ($goals as $goal) {
            if (!is_array($goal)) {
                continue;
            }
            $type  = (string) ($goal['type'] ?? '');
            $count = (int) ($goal['count'] ?? 0);

            $parts[] = match ($type) {
                'score'   => '分数≥' . $count,
                'collect' => '收集' . ($goal['target'] ?? '?') . '×' . $count,
                'clear'   => '清除' . ($goal['layer'] ?? '?') . '×' . $count,
                'order'   => '消除色' . ($goal['gem'] ?? '?') . '×' . $count,
                'rescue'  => '解救' . ($goal['target'] ?? '?') . '×' . $count,
                'boss'    => '击败' . ($goal['actor'] ?? 'Boss'),
                'spread'  => '清除全部' . ($goal['layer'] ?? '蔓延物'),
                'survive' => '存活' . $count . '回合',
                default   => $type,
            };
        }

        return $parts ? implode(' + ', $parts) : '—';
    }
}
