<?php
namespace plugin\wisecms\controller;

/**
 * 页面请求缺数据的统一兜底（UIUX-V-03 复跑发现）
 *
 * **问题**：`$this->error()` 对任何请求都返回 JSON —— 于是「编辑页 URL 丢了 `?id`」
 * 这类**页面请求**会渲染一坨裸 JSON（`{"code":1,"msg":"模板不存在",...}`）：
 * 用户看到的是接口报文，既没有导航、也不懂含义；而个别 action 因为 `edit($id)`
 * 把 id 声明成**必填**，无 id 时直接抛 `ArgumentCountError` → **500**。
 *
 * **处置**：
 *   · AJAX 请求（`X-Requested-With`，内核 `Wise.util.http` 与所有表单提交都会带）
 *     → 保持 `error()` 的 JSON 语义，调用方完全无感；
 *   · 页面请求 → 302 回对应列表页（与前台 `index\*` 控制器既有的 `redirect()` 做法一致），
 *     不留死页面、不抛 500。
 *
 * 用法：
 * ```php
 * $tmpl = TemplateModel::find($id);
 * if (!$tmpl) {
 *     return $this->pageFallback('模板不存在', plugin_url('wisecms/template/index'));
 * }
 * ```
 *
 * ⚠️ 仅适用于**后台页面控制器**。纯接口场景（前台 `index\*` / `api\*`）不要用，
 *    它们本来就该返回 JSON。
 */
trait PageFallbackTrait
{
    /**
     * @param string $message 失败原因（AJAX 场景回给调用方）
     * @param mixed  $backUrl 页面场景的落点（列表页 URL，可为 UrlBuild 对象）
     *
     * @return \think\Response
     */
    protected function pageFallback(string $message, $backUrl)
    {
        if ($this->request->isAjax()) {
            return $this->error($message);
        }

        return redirect((string) $backUrl);
    }
}
