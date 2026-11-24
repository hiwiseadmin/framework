<?php
namespace app\admin\controller;


class Icons extends BaseController
{
    /** 图标雪碧图（相对 public 目录） */
    private const SPRITE_PATH = 'statics/libs/bootstrap-icons/bootstrap-icons.svg';

    /**
     * 首页
     */
    public function index()
    {
        $module = $this->request->param('module', '', 'trim');
        if($module == 'bootstrap'){
            return $this->fetch('module');
        }else{
            return $this->fetch();
        }
    }

    /**
     * 下载单个图标 SVG
     * GET /admin/icons/download_svg?name=alarm
     */
    public function downloadSvg()
    {
        $name = trim((string) $this->request->param('name', ''));

        if ($name === '') {
            return redirect('/admin/icons/index?module=bootstrap');
        }

        $svg = self::buildIconSvg($name);
        if ($svg === null) {
            return response('icon not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response($svg, 200, [
            'Content-Type'        => 'image/svg+xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="bi-' . $name . '.svg"',
            'Cache-Control'       => 'private, max-age=86400',
        ]);
    }

    /**
     * 从雪碧图中取出指定 symbol，包装成可独立使用的 SVG
     *
     * 单图标文件已从仓库移除（2078 个 svg），统一以 sprite 为数据源：
     * 内嵌用 <use href=".../bootstrap-icons.svg#name">，代码预览在前端直接解析
     * 静态 sprite，下载走本方法。
     *
     * @param string $name 图标名（不含 bi- 前缀），如 alarm、filetype-json
     */
    public static function buildIconSvg(string $name): ?string
    {
        if ($name === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name)) {
            return null;
        }

        $sprite = public_path() . self::SPRITE_PATH;
        if (!is_file($sprite) || !is_readable($sprite)) {
            return null;
        }

        $content = (string) file_get_contents($sprite);
        if ($content === '') {
            return null;
        }

        $needle = 'id="' . $name . '"';
        $pos    = strpos($content, $needle);
        if ($pos === false) {
            return null;
        }

        $before = $pos > 0 ? $content[$pos - 1] : '';
        if (!in_array($before, [' ', "\t", "\n", "\r"], true)) {
            return null;
        }

        $open = strrpos(substr($content, 0, $pos), '<symbol');
        if ($open === false) {
            return null;
        }

        $openEnd = strpos($content, '>', $open);
        if ($openEnd === false || $openEnd < $pos) {
            return null;
        }

        $close = strpos($content, '</symbol>', $openEnd);
        if ($close === false) {
            return null;
        }

        $body = trim(substr($content, $openEnd + 1, $close - $openEnd - 1));
        if ($body === '') {
            return null;
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor"'
            . ' class="bi bi-' . $name . '" viewBox="0 0 16 16">' . $body . '</svg>';
    }
}
