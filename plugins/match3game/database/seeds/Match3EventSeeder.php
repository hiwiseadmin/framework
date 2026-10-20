<?php

declare(strict_types=1);

use think\migration\Seeder;

/**
 * 肉鸽事件种子（Phase 4 · 22 个，05 §B6.4 要求 ≥ 20）
 *
 * 效果字典见 service/rogue/RunEffects.php：
 *   coin / life / buff_moves / score_buff / coin_buff / relic / curse / remove_curse / stone
 *
 * 设计原则（04 「风险改造类（双刃剑）是肉鸽乐趣的关键」）：
 *   每个事件至少有一个"高风险高收益"选项（`risk: true`），
 *   且永远保留一个"稳妥但收益小"的选项，避免玩家被迫吃亏。
 */
class Match3EventSeeder extends Seeder
{
    public function run(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        foreach ($this->events() as $e) {
            $rows[] = [
                'event_id'   => $e['event_id'],
                'title'      => $e['title'],
                'desc'       => $e['desc'],
                'icon'       => 'event_' . $e['event_id'] . '.png',
                'options'    => json_encode($e['options'], JSON_UNESCAPED_UNICODE),
                'weight'     => $e['weight'] ?? 100,
                'min_layer'  => $e['min_layer'] ?? 1,
                'enabled'    => 1,
                'create_time' => $now,
                'update_time' => $now,
            ];
        }

        $this->table('match3_event')->insert($rows)->save();
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    protected function events(): array
    {
        return [
            /* ============ 灵玉类 ============ */
            [
                'event_id' => 'ev_cache',
                'title'    => '尘封的木箱',
                'desc'     => '半埋在沙土里，锁扣已经锈蚀。',
                'options'  => [
                    ['id' => 'open', 'label' => '撬开它', 'risk' => true, 'effects' => [
                        ['kind' => 'coin', 'value' => 110],
                        ['kind' => 'life', 'value' => -1],
                    ]],
                    ['id' => 'careful', 'label' => '谨慎取出散落的灵玉', 'effects' => [
                        ['kind' => 'coin', 'value' => 35],
                    ]],
                ],
            ],
            [
                'event_id' => 'ev_gambler',
                'title'    => '赌徒的邀请',
                'desc'     => '他摊开三张覆着的牌，"押多少，随你"。',
                'options'  => [
                    ['id' => 'bet', 'label' => '押上 50 灵玉', 'risk' => true, 'effects' => [
                        ['kind' => 'coin', 'value' => -50],
                        ['kind' => 'relic', 'rarity' => 'rare'],
                    ]],
                    ['id' => 'watch', 'label' => '只旁观', 'effects' => [
                        ['kind' => 'coin', 'value' => 15],
                    ]],
                ],
            ],
            [
                'event_id' => 'ev_smuggler',
                'title'    => '走私者',
                'desc'     => '斗篷下露出半截陌生的纹章。',
                'options'  => [
                    ['id' => 'deal', 'label' => '成交（-60 灵玉）', 'effects' => [
                        ['kind' => 'coin', 'value' => -60],
                        ['kind' => 'relic', 'rarity' => 'rare'],
                    ]],
                    ['id' => 'report', 'label' => '举报他', 'effects' => [
                        ['kind' => 'coin', 'value' => 55],
                    ]],
                    ['id' => 'rob', 'label' => '黑吃黑（机会 -1）', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'coin', 'value' => 220],
                    ]],
                ],
                'min_layer' => 2,
            ],

            /* ============ 机会 / 生命类 ============ */
            [
                'event_id' => 'ev_moon_well',
                'title'    => '月井',
                'desc'     => '井水中浮着一轮不真实的月亮。',
                'options'  => [
                    ['id' => 'pray', 'label' => '许愿（-80 灵玉）', 'effects' => [
                        ['kind' => 'coin', 'value' => -80],
                        ['kind' => 'life', 'value' => 1],
                    ]],
                    ['id' => 'draw', 'label' => '打水', 'effects' => [
                        ['kind' => 'coin', 'value' => 30],
                    ]],
                ],
            ],
            [
                'event_id' => 'ev_arena',
                'title'    => '竞技场',
                'desc'     => '看台上没有观众，只有计分板。',
                'options'  => [
                    ['id' => 'fight', 'label' => '下场比试', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'coin', 'value' => 160],
                    ]],
                    ['id' => 'judge', 'label' => '当裁判', 'effects' => [
                        ['kind' => 'coin', 'value' => 25],
                    ]],
                ],
                'min_layer' => 2,
            ],
            [
                'event_id' => 'ev_desert',
                'title'    => '沙暴',
                'desc'     => '风墙正从地平线推进。',
                'options'  => [
                    ['id' => 'rush', 'label' => '硬闯', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'coin', 'value' => 100],
                    ]],
                    ['id' => 'wait', 'label' => '原地扎营', 'effects' => [
                        ['kind' => 'coin', 'value' => -10],
                    ]],
                ],
            ],
            [
                'event_id' => 'ev_spring_poison',
                'title'    => '温热的泉眼',
                'desc'     => '水面泛着不自然的虹彩。',
                'options'  => [
                    ['id' => 'drink', 'label' => '饮下', 'risk' => true, 'effects' => [
                        ['kind' => 'buff_moves', 'value' => 8],
                        ['kind' => 'life', 'value' => -1],
                    ]],
                    ['id' => 'leave', 'label' => '绕开并采集泉盐', 'effects' => [
                        ['kind' => 'coin', 'value' => 20],
                    ]],
                ],
            ],

            /* ============ 步数 / 节奏类 ============ */
            [
                'event_id' => 'ev_spring',
                'title'    => '灵泉',
                'desc'     => '泉水清冽，入口回甘。',
                'options'  => [
                    ['id' => 'drink', 'label' => '饮下（后续每关步数 +5）', 'effects' => [
                        ['kind' => 'buff_moves', 'value' => 5],
                    ]],
                    ['id' => 'bottle', 'label' => '装瓶卖掉', 'effects' => [
                        ['kind' => 'coin', 'value' => 65],
                    ]],
                ],
            ],
            [
                'event_id' => 'ev_peddler',
                'title'    => '货郎',
                'desc'     => '扁担两头压得吱呀作响。',
                'options'  => [
                    ['id' => 'boots', 'label' => '买轻足靴（-40 灵玉）', 'effects' => [
                        ['kind' => 'coin', 'value' => -40],
                        ['kind' => 'buff_moves', 'value' => 6],
                    ]],
                    ['id' => 'charm', 'label' => '买聚财符（-70 灵玉）', 'effects' => [
                        ['kind' => 'coin', 'value' => -70],
                        ['kind' => 'coin_buff', 'value' => 25],
                    ]],
                ],
            ],
            [
                'event_id' => 'ev_trap',
                'title'    => '落石隘口',
                'desc'     => '碎石还在往下掉。',
                'options'  => [
                    ['id' => 'force', 'label' => '强行通过', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'coin', 'value' => 130],
                    ]],
                    ['id' => 'detour', 'label' => '绕远路（后续步数 -3）', 'effects' => [
                        ['kind' => 'buff_moves', 'value' => -3],
                        ['kind' => 'coin', 'value' => 30],
                    ]],
                    ['id' => 'pay', 'label' => '雇人清理（-50 灵玉）', 'effects' => [
                        ['kind' => 'coin', 'value' => -50],
                        ['kind' => 'buff_moves', 'value' => 3],
                    ]],
                ],
            ],

            /* ============ 分数 / 经济效率类 ============ */
            [
                'event_id' => 'ev_library',
                'title'    => '藏书阁',
                'desc'     => '书页间夹着前人的批注。',
                'options'  => [
                    ['id' => 'study', 'label' => '研读（分数 +15%）', 'effects' => [
                        ['kind' => 'score_buff', 'value' => 15],
                    ]],
                    ['id' => 'sell', 'label' => '把旧书卖掉', 'effects' => [
                        ['kind' => 'coin', 'value' => 80],
                    ]],
                    ['id' => 'forbidden', 'label' => '抄录禁书（机会 -1）', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'score_buff', 'value' => 30],
                    ]],
                ],
            ],
            [
                'event_id' => 'ev_fountain',
                'title'    => '许愿池',
                'desc'     => '池底铺满铜钱。',
                'options'  => [
                    ['id' => 'toss', 'label' => '投币 40 灵玉（分数 +10%）', 'effects' => [
                        ['kind' => 'coin', 'value' => -40],
                        ['kind' => 'score_buff', 'value' => 10],
                    ]],
                    ['id' => 'collect', 'label' => '捞几枚上来', 'effects' => [
                        ['kind' => 'coin', 'value' => 25],
                    ]],
                    ['id' => 'dive', 'label' => '潜下去捞（机会 -1）', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'coin', 'value' => 130],
                    ]],
                ],
            ],
            [
                'event_id' => 'ev_hermit',
                'title'    => '隐者',
                'desc'     => '他盘坐在崖边，问你愿不愿听一段话。',
                'options'  => [
                    ['id' => 'listen', 'label' => '听道（分数 +20%，机会 -1）', 'risk' => true, 'effects' => [
                        ['kind' => 'score_buff', 'value' => 20],
                        ['kind' => 'life', 'value' => -1],
                    ]],
                    ['id' => 'leave', 'label' => '留下干粮告辞', 'effects' => [
                        ['kind' => 'coin', 'value' => 30],
                    ]],
                ],
                'min_layer' => 2,
            ],
            [
                'event_id' => 'ev_wanderer',
                'title'    => '云游道人',
                'desc'     => '袖中露出一角签筒。',
                'options'  => [
                    ['id' => 'fortune', 'label' => '求一支签（分数 +10%）', 'effects' => [
                        ['kind' => 'score_buff', 'value' => 10],
                    ]],
                    ['id' => 'almsgiving', 'label' => '布施 30 灵玉', 'effects' => [
                        ['kind' => 'coin', 'value' => -30],
                        ['kind' => 'life', 'value' => 1],
                    ]],
                    ['id' => 'defy', 'label' => '求他逆天改命（机会 -1）', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'score_buff', 'value' => 25],
                    ]],
                ],
            ],
            [
                'event_id' => 'ev_relic_smith',
                'title'    => '遗物匠',
                'desc'     => '他的工作台上摆着各式各样的"零件"。',
                'options'  => [
                    ['id' => 'polish', 'label' => '打磨（灵玉收益 +20%）', 'effects' => [
                        ['kind' => 'coin_buff', 'value' => 20],
                    ]],
                    ['id' => 'purify', 'label' => '净化（移除 1 个诅咒）', 'effects' => [
                        ['kind' => 'remove_curse', 'value' => 1],
                    ]],
                    ['id' => 'dismantle', 'label' => '强行拆解（机会 -1）', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'relic', 'rarity' => 'rare'],
                    ]],
                ],
            ],

            /* ============ 遗物类 ============ */
            [
                'event_id' => 'ev_traveler',
                'title'    => '落魄商人',
                'desc'     => '他把最后一件货物推到面前。',
                'options'  => [
                    ['id' => 'buy', 'label' => '买下（-30 灵玉）', 'effects' => [
                        ['kind' => 'coin', 'value' => -30],
                        ['kind' => 'relic', 'rarity' => 'common'],
                    ]],
                    ['id' => 'refuse', 'label' => '婉拒，塞给他几个灵玉', 'effects' => [
                        ['kind' => 'coin', 'value' => -10],
                        ['kind' => 'buff_moves', 'value' => 3],
                    ]],
                    ['id' => 'rob', 'label' => '翻他的另一只箱子（机会 -1）', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'relic', 'rarity' => 'rare'],
                    ]],
                ],
            ],
            [
                'event_id' => 'ev_shrine',
                'title'    => '古神像',
                'desc'     => '石像的脸被风雨磨平了。',
                'options'  => [
                    ['id' => 'blood', 'label' => '以血献祭（机会 -1）', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'relic', 'rarity' => 'epic'],
                    ]],
                    ['id' => 'coin', 'label' => '以财献祭（-60 灵玉）', 'effects' => [
                        ['kind' => 'coin', 'value' => -60],
                        ['kind' => 'relic', 'rarity' => 'rare'],
                    ]],
                    ['id' => 'pass', 'label' => '合十离开', 'effects' => [
                        ['kind' => 'coin', 'value' => 15],
                    ]],
                ],
                'min_layer' => 2,
            ],
            [
                'event_id' => 'ev_merchant_curse',
                'title'    => '诅咒商人',
                'desc'     => '他卖的每件东西都缠着黑气，价格却低得可疑。',
                'options'  => [
                    ['id' => 'buy', 'label' => '买一件（获得诅咒遗物）', 'risk' => true, 'effects' => [
                        ['kind' => 'curse'],
                        ['kind' => 'coin', 'value' => 160],
                    ]],
                    ['id' => 'refuse', 'label' => '不予理会', 'effects' => [
                        ['kind' => 'coin', 'value' => 20],
                    ]],
                ],
            ],
            [
                'event_id' => 'ev_altar',
                'title'    => '血色祭坛',
                'desc'     => '凹槽里的痕迹是暗褐色的。',
                'options'  => [
                    ['id' => 'offering', 'label' => '献上机会（-1）', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'relic', 'rarity' => 'rare'],
                        ['kind' => 'relic', 'rarity' => 'rare'],
                    ]],
                    ['id' => 'loot', 'label' => '搜刮祭品', 'effects' => [
                        ['kind' => 'coin', 'value' => 70],
                    ]],
                ],
                'min_layer' => 2,
            ],
            [
                'event_id' => 'ev_temple',
                'title'    => '荒废古庙',
                'desc'     => '供台上还剩半截香。',
                'options'  => [
                    ['id' => 'offer', 'label' => '供奉 100 灵玉', 'effects' => [
                        ['kind' => 'coin', 'value' => -100],
                        ['kind' => 'relic', 'rarity' => 'epic'],
                    ]],
                    ['id' => 'search', 'label' => '翻找供台', 'risk' => true, 'effects' => [
                        ['kind' => 'coin', 'value' => 60],
                        ['kind' => 'life', 'value' => -1],
                    ]],
                ],
                'min_layer' => 2,
            ],
            [
                'event_id' => 'ev_old_soldier',
                'title'    => '老兵',
                'desc'     => '"并肩走一段？" 他拍了拍空着的另一侧。',
                'options'  => [
                    ['id' => 'join', 'label' => '并肩同行（机会 -1）', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'relic', 'rarity' => 'rare'],
                        ['kind' => 'buff_moves', 'value' => 4],
                    ]],
                    ['id' => 'part', 'label' => '交换情报后分别', 'effects' => [
                        ['kind' => 'coin', 'value' => 40],
                    ]],
                ],
                'min_layer' => 3,
            ],

            /* ============ meta（灵石）类 ============ */
            [
                'event_id' => 'ev_stone_mine',
                'title'    => '灵石矿脉',
                'desc'     => '岩壁上嵌着细密的蓝光。',
                'options'  => [
                    ['id' => 'dig', 'label' => '深入开采（机会 -1）', 'risk' => true, 'effects' => [
                        ['kind' => 'life', 'value' => -1],
                        ['kind' => 'stone', 'value' => 160],
                    ]],
                    ['id' => 'surface', 'label' => '只采表层', 'effects' => [
                        ['kind' => 'stone', 'value' => 50],
                    ]],
                ],
            ],
            [
                'event_id' => 'ev_obsidian',
                'title'    => '黑曜石柱',
                'desc'     => '柱身上刻着一串看不懂的符号。',
                'options'  => [
                    ['id' => 'touch', 'label' => '触碰符号', 'risk' => true, 'effects' => [
                        ['kind' => 'stone', 'value' => -40],
                        ['kind' => 'relic', 'rarity' => 'epic'],
                    ]],
                    ['id' => 'copy', 'label' => '拓印下来（灵石 +60）', 'effects' => [
                        ['kind' => 'stone', 'value' => 60],
                    ]],
                ],
                'min_layer' => 2,
            ],
        ];
    }
}
