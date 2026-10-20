<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3LevelDef;
use plugin\match3game\model\Match3Relic;
use plugin\match3game\service\level\LevelSchemaFactory;
use plugin\match3game\service\level\LevelValidator;
use plugin\match3game\service\rogue\PatchEngine;
use plugin\match3game\service\rogue\PatchException;
use wise\support\context\ContextualLogger;

/**
 * 遗物管理（Phase 3 · 05 §B5）
 *
 * 遗物 = 对 Level Schema 的一次 patch，纯配置表，**新增遗物不需要发版**。
 * 本页提供 CRUD + patch JSON 编辑 + 「试跑」按钮（真实应用到一个关卡上并跑 LevelValidator）。
 */
class Relic extends Base
{
    /** 允许写入的字段 */
    protected const ALLOWED = [
        'relic_id', 'name', 'rarity', 'category', 'desc', 'icon',
        'patch', 'weight', 'unlock_cost', 'enabled',
    ];

    public function index()
    {
        return $this->assign([
            'rarities'   => Match3Relic::RARITIES,
            'categories' => Match3Relic::CATEGORIES,
        ])->fetch();
    }

    public function getList()
    {
        $page     = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 20);
        $rarity   = $this->request->param('rarity', '');
        $keyword  = $this->request->param('keyword', '');

        $query = Match3Relic::where('1=1');
        if ($rarity !== '') {
            $query->where('rarity', $rarity);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('relic_id', "%{$keyword}%")->whereOrLike('name', "%{$keyword}%");
            });
        }

        $list = $query->order('rarity', 'asc')->order('id', 'asc')
            ->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row           = $item->toArray();
            $row['patch_count'] = is_array($row['patch'] ?? null) ? count($row['patch']) : 0;
            $rows[]        = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    public function save()
    {
        $data = $this->request->post();
        $id   = (int) ($data['id'] ?? 0);

        $data = array_intersect_key($data, array_flip(self::ALLOWED));

        if (empty($data['relic_id']) || empty($data['name'])) {
            return $this->error('遗物 ID 与名称不能为空');
        }
        if (!in_array((string) ($data['rarity'] ?? ''), Match3Relic::RARITIES, true)) {
            return $this->error('稀有度必须是 ' . implode('/', Match3Relic::RARITIES));
        }

        // patch：textarea 提交字符串 → 数组
        if (isset($data['patch']) && is_string($data['patch'])) {
            $decoded = json_decode(trim($data['patch']), true);
            if (!is_array($decoded) || $decoded === []) {
                return $this->error('patch 必须是非空 JSON 数组');
            }
            $data['patch'] = $decoded;
        }
        if (empty($data['patch'])) {
            return $this->error('patch 不能为空');
        }

        // patch 结构预检（不依赖具体关卡）
        $errors = $this->precheckPatch((array) $data['patch']);
        if ($errors !== []) {
            return $this->error('patch 结构非法：' . implode('；', $errors));
        }

        try {
            if ($id > 0) {
                $relic = Match3Relic::find($id);
                if (!$relic) {
                    return $this->error('遗物不存在');
                }
                $relic->save($data);
            } else {
                $exists = Match3Relic::where('relic_id', (string) $data['relic_id'])->find();
                if ($exists) {
                    return $this->error('遗物 ID 已存在: ' . $data['relic_id']);
                }
                $relic = new Match3Relic();
                $relic->save($data);
            }

            return $this->success('保存成功', ['id' => $relic->id]);
        } catch (\Throwable $e) {
            ContextualLogger::error('[match3] Relic save error: ' . $e->getMessage());
            return $this->error('保存失败: ' . $e->getMessage());
        }
    }

    public function delete()
    {
        $ids = $this->request->post('ids');
        if (empty($ids)) {
            return $this->error('请选择要删除的遗物');
        }
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        try {
            Match3Relic::whereIn('id', $ids)->delete();
            return $this->success('删除成功');
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 试跑：把 patch 真实应用到一个关卡上，跑 LevelValidator
     *
     * body: { patch: json 字符串|数组, level_code?: string }
     */
    public function check()
    {
        $raw   = $this->request->param('patch', '');
        $patch = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (!is_array($patch) || $patch === []) {
            return $this->error('patch 必须是非空 JSON 数组');
        }

        $errors = $this->precheckPatch($patch);
        if ($errors !== []) {
            return $this->error('patch 结构非法：' . implode('；', $errors));
        }

        // 选一个关卡作为试跑对象
        $levelCode = (string) $this->request->param('level_code', '');
        $level     = $levelCode !== ''
            ? Match3LevelDef::where('code', $levelCode)->find()
            : Match3LevelDef::where('status', 1)->order('sort_order', 'asc')->find();

        if (!$level) {
            return $this->error('没有可用于试跑的关卡');
        }

        $schema = LevelSchemaFactory::normalize(LevelSchemaFactory::fromRow($level->toArray()));

        try {
            $result = PatchEngine::applyOps($schema, $patch);
        } catch (PatchException $e) {
            return $this->success('', [
                'valid'  => false,
                'errors' => [$e->getMessage()],
                'path'   => $e->getPatchPath(),
            ]);
        }

        $applied = is_array($result->doc) ? $result->doc : json_decode(json_encode($result->doc), true);
        $errors  = LevelValidator::validate($applied);

        return $this->success('', [
            'valid'        => $errors === [],
            'errors'       => $errors,
            'appliedCount' => count($result->applied),
            'skipped'      => $result->skipped,
            'moves'        => (int) ($applied['rules']['moves'] ?? 0),
            'goals'        => $applied['rules']['goals'] ?? [],
            'starScores'   => $applied['rules']['starScores'] ?? [],
            'fingerprint'  => LevelSchemaFactory::fingerprint($applied),
        ]);
    }

    /**
     * patch 结构预检（不依赖具体关卡）
     *
     * 注意：`replace` 目标不存在会抛异常，这属于**运行时**结果，不在这里拦，
     * 由「试跑」按具体关卡给出（不同关卡模板字段可能不同）。
     *
     * @param  array<int,mixed> $patch
     * @return array<int,string>
     */
    protected function precheckPatch(array $patch): array
    {
        $errors = [];
        $allowedOps = [PatchEngine::OP_ADD, PatchEngine::OP_REPLACE, PatchEngine::OP_REMOVE, PatchEngine::OP_MUL];

        foreach ($patch as $i => $op) {
            if (!is_array($op)) {
                $errors[] = "[{$i}] 不是对象";
                continue;
            }
            $opName = (string) ($op['op'] ?? '');
            $path   = (string) ($op['path'] ?? '');

            if (!in_array($opName, $allowedOps, true)) {
                $errors[] = "[{$i}] 未知 op: {$opName}（允许 " . implode('/', $allowedOps) . '）';
            }
            if ($path === '' || $path[0] !== '/') {
                $errors[] = "[{$i}] path 必须以 / 开头";
                continue;
            }
            if (in_array($opName, [PatchEngine::OP_ADD, PatchEngine::OP_REPLACE, PatchEngine::OP_MUL], true)
                && !array_key_exists('value', $op)) {
                $errors[] = "[{$i}] {$opName} 缺少 value";
            }
        }

        return $errors;
    }
}
