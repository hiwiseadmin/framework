<?php
declare(strict_types=1);

namespace plugin\wiseeditor;

use app\common\library\EditorManager;
use app\plugin\contract\EditorPluginInterface;
use plugin\wiseeditor\src\TipTapDriver;
use plugin\wiseeditor\src\MilkdownDriver;

class EditorProvider implements EditorPluginInterface
{
    public function registerEditors(EditorManager $manager): void
    {
        // 注册富文本编辑器 → TipTap
        $manager->registerDriver('richtext', TipTapDriver::class);

        // 注册 Markdown 编辑器 → Milkdown
        $manager->registerDriver('markdown', MilkdownDriver::class);

        // TipTap 也提供简易模式（更少的工具栏按钮）
        $manager->registerDriver('simple', TipTapDriver::class);
    }

    public function getEditorTypes(): array
    {
        return ['richtext', 'markdown', 'simple'];
    }

    public function getEditorModulePath(string $type): ?string
    {
        return 'plugins/wiseeditor/js/wiseeditor.js';
    }

    public function getEditorIcon(string $type): ?string
    {
        $icons = [
            'richtext' => 'bi bi-pencil-square',
            'markdown' => 'bi bi-markdown',
            'simple'   => 'bi bi-pencil',
        ];
        return $icons[$type] ?? null;
    }
}
