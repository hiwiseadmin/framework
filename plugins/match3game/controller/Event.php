<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

use plugin\match3game\model\Match3Event;
use plugin\match3game\service\rogue\RunEffects;
use wise\support\context\ContextualLogger;

/**
 * 事件配置管理（Phase 4 遗留补齐 · 05 §B4 事件节点）
 *
 * 22 个肉鸽事件由 `Match3EventSeeder` 初始化，本页供策划自助维护：
 *   · 新增/修改事件（标题、描述、权重、最早出现层）
 *   · 编辑选项与其 effects（**保存前用 RunEffects 校验效果字典**）
 *   · 「试跑」按钮：把选项 effects 真实跑一遍 RunEffects，看文案与产物
 *
 * 效果字典见 `service/rogue/RunEffects.php`。
 */
class Event extends Base
{
    protected const ALLOWED = ['event_id', 'title', 'desc', 'icon', 'options', 'weight', 'min_layer', 'enabled'];

    public function index()
    {
        return $this->assign([
            'kinds' => [
                RunEffects::KIND_COIN         => '灵玉（value）',
                RunEffects::KIND_LIFE         => '失败机会（value）',
                RunEffects::KIND_BUFF_MOVES   => '后续步数（value）',
                RunEffects::KIND_SCORE_BUFF   => '分数%（value）',
                RunEffects::KIND_COIN_BUFF    => '灵玉%（value）',
                RunEffects::KIND_RELIC        => '获得遗物（rarity/relic_id）',
                RunEffects::KIND_CURSE        => '获得诅咒遗物',
                RunEffects::KIND_REMOVE_CURSE => '移除诅咒（value）',
                RunEffects::KIND_STONE        => '灵石（value）',
            ],
        ])->fetch();
    }

    public function getList()
    {
        $page     = $this->request->param('pageNumber/d', 1);
        $pageSize = $this->request->param('pageSize/d', 20);
        $keyword  = (string) $this->request->param('keyword', '');
        $enabled  = $this->request->param('enabled', '');

        $query = Match3Event::where('1=1');
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('event_id', "%{$keyword}%")->whereOrLike('title', "%{$keyword}%");
            });
        }
        if ($enabled !== '') {
            $query->where('enabled', (int) $enabled);
        }

        $list = $query->order('id', 'asc')->paginate(['list_rows' => $pageSize, 'page' => $page]);

        $rows = [];
        foreach ($list->items() as $item) {
            $row            = $item->toArray();
            $row['opt_count'] = is_array($row['options'] ?? null) ? count($row['options']) : 0;
            $row['risk_count'] = 0;
            foreach ((array) ($row['options'] ?? []) as $opt) {
                if (!empty($opt['risk'])) {
                    $row['risk_count']++;
                }
            }
            $rows[] = $row;
        }

        return $this->success('', ['total' => $list->total(), 'rows' => $rows]);
    }

    public function save()
    {
        $data = $this->request->post();
        $id   = (int) ($data['id'] ?? 0);
        $data = array_intersect_key($data, array_flip(self::ALLOWED));

        if (empty($data['event_id']) || empty($data['title'])) {
            return $this->error('事件 ID 与标题不能为空');
        }

        if (isset($data['options']) && is_string($data['options'])) {
            $decoded = json_decode(trim($data['options']), true);
            if (!is_array($decoded) || $decoded === []) {
                return $this->error('options 必须是非空 JSON 数组');
            }
            $data['options'] = $decoded;
        }
        if (empty($data['options']) || !is_array($data['options'])) {
            return $this->error('options 不能为空');
        }

        $errors = $this->validateOptions((array) $data['options']);
        if ($errors !== []) {
            return $this->error('选项非法：' . implode('；', $errors));
        }

        try {
            if ($id > 0) {
                $row = Match3Event::find($id);
                if (!$row) {
                    return $this->error('事件不存在');
                }
                $row->save($data);
            } else {
                if (Match3Event::where('event_id', $data['event_id'])->find()) {
                    return $this->error('事件 ID 已存在: ' . $data['event_id']);
                }
                (new Match3Event())->save($data);
            }

            return $this->success('保存成功');
        } catch (\Throwable $e) {
            ContextualLogger::error('[match3] Event save error: ' . $e->getMessage());

            return $this->error('保存失败: ' . $e->getMessage());
        }
    }

    public function delete()
    {
        $ids = $this->request->post('ids');
        if (empty($ids)) {
            return $this->error('请选择要删除的事件');
        }
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        Match3Event::whereIn('id', $ids)->delete();

        return $this->success('删除成功');
    }

    /**
     * 试跑：把 effects 真实跑一遍 RunEffects（不落库），返回应用文案
     */
    public function check()
    {
        $raw = $this->request->param('effects', '');
        $effects = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (!is_array($effects)) {
            return $this->error('effects 必须是 JSON 数组');
        }

        $errors = $this->validateEffects($effects);

        // 用一个"标准局"试跑，看清楚每个效果实际落地成什么
        $run = ['coins' => 200, 'lives' => 3, 'relics' => [], 'buffs' => []];
        $result = RunEffects::apply($run, $effects, ['seed' => 1, 'salt' => 1, 'pool' => []]);

        return $this->success('', [
            'valid'    => $errors === [],
            'errors'   => $errors,
            'preview'  => RunEffects::describe($effects),
            'applied'  => $result['applied'],
            'failed'   => $result['failed'],
            'after'    => [
                'coins' => $run['coins'],
                'lives' => $run['lives'],
                'buffs' => $run['buffs'],
                'relics' => count($run['relics']),
            ],
        ]);
    }

    /* ---------------- 校验 ---------------- */

    /**
     * @param  array<int,mixed> $options
     * @return array<int,string>
     */
    protected function validateOptions(array $options): array
    {
        $errors = [];

        if (count($options) < 2) {
            $errors[] = '每个事件至少 2 个选项';
        }

        $ids = [];
        foreach ($options as $i => $opt) {
            if (!is_array($opt)) {
                $errors[] = "[{$i}] 选项不是对象";
                continue;
            }
            $optId = (string) ($opt['id'] ?? '');
            if ($optId === '') {
                $errors[] = "[{$i}] 缺少 id";
            } elseif (in_array($optId, $ids, true)) {
                $errors[] = "[{$i}] 选项 id 重复: {$optId}";
            } else {
                $ids[] = $optId;
            }
            if (empty($opt['label'])) {
                $errors[] = "[{$i}] 缺少 label";
            }

            foreach ($this->validateEffects((array) ($opt['effects'] ?? [])) as $err) {
                $errors[] = "[{$i}] " . $err;
            }
        }

        return $errors;
    }

    /**
     * @param  array<int,mixed> $effects
     * @return array<int,string>
     */
    protected function validateEffects(array $effects): array
    {
        $known = [
            RunEffects::KIND_COIN, RunEffects::KIND_LIFE, RunEffects::KIND_BUFF_MOVES,
            RunEffects::KIND_SCORE_BUFF, RunEffects::KIND_COIN_BUFF, RunEffects::KIND_RELIC,
            RunEffects::KIND_CURSE, RunEffects::KIND_REMOVE_CURSE, RunEffects::KIND_UPGRADE,
            RunEffects::KIND_STONE,
        ];

        $errors = [];
        foreach ($effects as $i => $eff) {
            if (!is_array($eff)) {
                $errors[] = "effects[{$i}] 不是对象";
                continue;
            }
            $kind = (string) ($eff['kind'] ?? '');
            if (!in_array($kind, $known, true)) {
                $errors[] = "effects[{$i}] 未知 kind: {$kind}（允许 " . implode('/', $known) . '）';
            }
            if ($kind === RunEffects::KIND_UPGRADE && empty($eff['relic_id'])) {
                $errors[] = "effects[{$i}] upgrade 必须带 relic_id";
            }
        }

        return $errors;
    }
}
