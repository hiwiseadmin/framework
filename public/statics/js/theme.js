$(function() {
    "use strict";

    /**
     * 主题切换按钮组件：自动在右下角添加浮动按钮，点击切换 <html> 的 data-bs-theme 属性 (dark/light)
     * 依赖：jQuery, Bootstrap 5, Bootstrap Icons
     * 特性：完全使用 Bootstrap 工具类实现样式和布局，无任何自定义 CSS
     */
    function initThemeToggle() {
        // 防止重复添加按钮 (通过特定类名识别)
        if ($('.theme-toggle-btn').length) return;

        // 获取 <html> 元素，操作其 data-bs-theme 属性
        var $html = $('html');
        var currentTheme = $html.attr('data-bs-theme');

        // 如果 <html> 上没有设置 data-bs-theme，则初始化为 'light' (Bootstrap 默认亮色)
        if (!currentTheme) {
            currentTheme = 'light';
            $html.attr('data-bs-theme', currentTheme);
        } else {
            // 如果已有值但不是 'dark' 也不是 'light' (比如 'auto')，为了图标正确，我们仍然用已有值，但切换逻辑会处理
            // 为了图标展示，我们只根据当前值是否为 'dark' 来决定图标，否则默认视为非暗色（显示太阳）
        }

        // 创建浮动按钮，只使用 Bootstrap 工具类：
        // position-fixed bottom-0 end-0 → 固定右下角
        // m-4 → 外边距 (间距)
        // btn btn-outline-primary → 按钮基础样式，带轮廓，适应亮/暗主题
        // rounded-circle p-3 → 正圆，内边距使图标居中
        // shadow → 轻微阴影增加层次感
        // d-flex align-items-center justify-content-center → 确保图标完全居中 (即使后续改变大小)
        var $btn = $('<button class="theme-toggle-btn btn btn-outline-success bg-success position-fixed bottom-0 end-0 m-4 rounded-circle p-3 shadow d-flex align-items-center justify-content-center text-white" style="width: 50px; height: 50px;" type="button" aria-label="切换亮色/暗色主题" title="切换主题">');

        // 根据当前主题设置图标: 亮色用太阳 (bi-sun)，暗色用月亮 (bi-moon)
        var $icon = $('<i class="bi">').addClass(currentTheme === 'dark' ? 'bi-moon' : 'bi-sun');
        $btn.append($icon);

        // 绑定点击事件
        $btn.on('click', function() {
            // 获取当前 data-bs-theme 值，若不存在则默认为 'light' (但之前已确保存在)
            var nowTheme = $html.attr('data-bs-theme') || 'light';
            // 切换：如果现在是 dark 则设为 light，否则设为 dark
            var newTheme = nowTheme === 'dark' ? 'light' : 'dark';
            $html.attr('data-bs-theme', newTheme);

            // 更新图标：移除现有的 sun/moon 类，添加对应新主题的类
            $icon.removeClass('bi-sun bi-moon').addClass(newTheme === 'dark' ? 'bi-moon' : 'bi-sun');

            // 可选：更新按钮 title 提示 (非必须，但提升体验)
            $btn.attr('title', newTheme === 'dark' ? '当前为暗色主题，点击切换亮色' : '当前为亮色主题，点击切换暗色');
        });

        // 设置初始提示
        $btn.attr('title', currentTheme === 'dark' ? '当前为暗色主题，点击切换亮色' : '当前为亮色主题，点击切换暗色');

        // 将按钮添加到 body
        $('body').append($btn);
    }

    // 在 DOM 准备就绪后执行初始化
    initThemeToggle();

});