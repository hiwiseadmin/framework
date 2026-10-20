<?php

declare(strict_types=1);

namespace plugin\match3game\controller;

/**
 * 玩法参数（§8.2 / §8.4）
 *
 * 展示对局/风控/广告全局参数（config/match3.php），
 * 参数修改走插件中心统一配置页（manifest.json config 声明）。
 */
class Settings extends Base
{
    public function index()
    {
        $config = config('match3game.match3', []);

        $this->assign('battle', $config['battle'] ?? []);
        $this->assign('validator', $config['validator'] ?? []);
        $this->assign('energy', $config['energy'] ?? []);
        $this->assign('adScenes', $config['ad']['scenes'] ?? []);
        $this->assign('gameId', $config['game_id'] ?? 'match3_game');

        return $this->fetch();
    }
}
