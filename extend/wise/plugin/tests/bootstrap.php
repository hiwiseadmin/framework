<?php
declare(strict_types=1);

/**
 * 插件 manifest 工具单测 bootstrap。
 *
 * 复用宿主 src/vendor 的自动加载器（composer 的 psr-0 已把 `extend/` 纳入，
 * 因此 `wise\plugin\manifest\*` 与 `wise\plugin\tests\*` 均可直接自动加载）。
 */

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
