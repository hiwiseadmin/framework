<?php
declare(strict_types=1);

/**
 * CRUD 生成器单测 bootstrap。
 *
 * 复用宿主 src/vendor 的自动加载器（composer 的 psr-0 已把 `extend/` 纳入，
 * 因此 `wise\crud\*` 与 `wise\crud\tests\*` 均可直接自动加载）。
 */

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
