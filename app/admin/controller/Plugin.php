<?php
namespace app\admin\controller;

use wise\plugin\PluginManager;
use wise\plugin\PluginContainerKeys;
use wise\plugin\config\PluginConfigForm;
use wise\plugin\config\PluginConfigSchema;
use think\facade\Filesystem;
use wise\support\tools\ArchiveTool;
use wise\support\tools\HttpClient;
use wise\support\tools\HttpException;
use wise\support\context\ContextualLogger;
use wise\operationlog\attribute\OperationLog;
use wise\plugin\compat\FrameworkCompatibility;

#[OperationLog(channel: 'admin', tags: ['admin', 'plugin'])]
class Plugin extends BaseController
{
    /**
     * 插件管理器实例
     * @var PluginManager
     */
    protected $pluginManager;
    
    protected function _initialize()
    {
        $this->pluginManager = app(PluginContainerKeys::MANAGER);
    }

    /**
     * 解析请求中的插件标识。
     *
     * 列表渲染展示的是插件显示名（manifest 的 title，可能是中文），
     * 而插件生命周期操作需要的是目录名/标识，这里统一解析，
     * 避免把中文显示名直接丢给 PluginManager 触发"无效的插件名称"。
     *
     * @param string $defaultParam 主参数名
     * @return string
     */
    protected function resolvePluginIdent(string $defaultParam = 'name'): string
    {
        $value = $this->request->param($defaultParam, '', 'trim');

        if ($value === '') {
            $value = $this->request->param('ident', '', 'trim');
        }
        if ($value === '') {
            $value = $this->request->param('plugin', '', 'trim');
        }

        return $value === '' ? '' : $this->pluginManager->resolveIdent($value);
    }
    
    /**
     * 插件首页
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 获取插件列表
     */ 
    public function getList()
    {
        $singleIdent = $this->request->param('row_ident/s', '', 'trim');
        if ($singleIdent !== '') {
            if (!PluginManager::checkPluginIdent($singleIdent)
                || !$row = $this->pluginManager->getPluginRow($singleIdent)) {
                return $this->error('插件不存在或已被移除');
            }

            return $this->success('获取成功', [
                'list'     => [$row],
                'total'    => 1,
                'category' => [],
                'page'     => 1,
                'limit'    => 1,
            ]);
        }

        $this->buildQueryParams();

        $catidRaw = $this->request->param('catid/s', '0', 'trim');
        $catid    = (int) $catidRaw;

        $type = $this->request->param('type/s', '', 'trim');
        $keyword = $this->request->param('keyword/s', '', 'trim');
        
        if($type == 'local'){
            $list = $this->pluginManager->getLocalPluginList([
                'catid'   => $catidRaw,
                'keyword' => $keyword,
            ]);
        }else{
            $list = $this->pluginManager->getOnlinePluginList(['page' => $this->page, 'limit' => $this->limit, 'catid' => $catid, 'keyword' => $keyword]);

            if (!empty($list['list'])) {
                foreach ($list['list'] as &$item) {
                    $ident = $item['ident'] ?? ($item['name'] ?? '');
                    $item['ident'] = $ident;
                    $state = ($ident !== '' && PluginManager::checkPluginIdent($ident))
                        ? $this->pluginManager->getPluginState($ident)
                        : PluginManager::STATE_NOT_INSTALLED;
                    $installed = $state !== PluginManager::STATE_NOT_INSTALLED;

                    if (empty($item['actions']) || !is_array($item['actions'])) {
                        $item['actions'] = $installed ? ['uninstall'] : ['install'];
                    } elseif ($installed) {
                        if (!in_array('uninstall', $item['actions'], true)) {
                            $item['actions'][] = 'uninstall';
                        }
                        $item['actions'] = array_values(array_filter(
                            $item['actions'],
                            static fn ($action) => $action !== 'install'
                        ));
                    }

                    $item['installed'] = $installed ? 1 : 0;
                    $item['state']     = $state;
                    $item['status']    = $state === PluginManager::STATE_ENABLED ? 1 : 0;

                    $ranges = \wise\plugin\compat\FrameworkCompatibility::normalize($item['compatible_with'] ?? []);
                    $min    = trim((string) ($item['min_framework_version'] ?? ''));

                    if ($ranges === [] && $min !== '') {
                        $ranges = ['>=' . $min];
                    }

                    $item['compatible_with'] = $ranges;
                    $item['compat_status']   = \wise\plugin\compat\FrameworkCompatibility::check($ranges);
                    $item['compat_note']     = \wise\plugin\compat\FrameworkCompatibility::describe($ranges);
                    $item['framework_version'] = \wise\plugin\compat\FrameworkCompatibility::currentVersion();
                }
                unset($item);
            }
        }
        
        if (!$list) {
            return $this->error('获取插件列表失败');
        }

        return $this->success('获取插件列表成功', [
            'list' => $list['list'], 
            'total' => $list['total'], 
            'category' => $list['category'] ?? [],
            'page' => $list['page'], 
            'limit' => $list['limit'],
        ]);
    }

    /**
     * 本地安装插件
     */
    #[OperationLog(template: '本地安装插件 {plugin}', level: 'WARNING')]
    public function localInstall(){
        $file = $this->request->file('file');
        $plugin = $this->request->param('plugin', '', 'trim');
        if (!$file && !$plugin) {
            return $this->error('请选择插件文件或插件名称');
        }

        if($file && !in_array(strtolower($file->extension()), ['zip'])){
            return $this->error('插件文件必须是zip格式');
        }

        if ($file) {
            $safeName = preg_replace('/[^\w\.\-]/u', '', $file->getOriginalName());
            $savename = plugin_path() . $safeName;
            if(file_exists($savename)){
                unlink($savename);
            }
            \think\facade\Filesystem::putFileAs('plugins', $file, $safeName);
            $baseName = pathinfo($safeName, PATHINFO_FILENAME);
            $plugin = empty($plugin) ? $baseName : $plugin;
                        
            $archiveTool = new ArchiveTool();
            $list = $archiveTool->listContents($savename);

            if($list && count($list) > 1){
                $ret = $archiveTool->extract($savename, plugin_path() . $baseName);
            }else{
                $ret = $archiveTool->extract($savename, plugin_path());
            }
            if($ret !== true){
                return $this->error('解压缩插件文件失败：' . $archiveTool->getLastError());
            }
        }

        try {
            $result = $this->pluginManager->install($plugin);
            return $this->success('安装成功', $result->toArray());
        } catch (\Exception $e) {
            return $this->error('安装失败：' . $e->getMessage());
        }
    }

    /**
     * 在线安装插件
     */
    #[OperationLog(template: '在线安装插件 {plugin}', level: 'WARNING')]
    public function install()
    {
        $plugin = $this->request->param('plugin', '', 'trim');
        $version = $this->request->param('version', 'last', 'trim');

        if (!$plugin) {
            return $this->error('请输入插件标识');
        }
        if(!PluginManager::checkPluginIdent($plugin)){
            return $this->error('插件标识不符合规范');
        }

        if ($this->pluginManager->isInstalled($plugin)) {
            return $this->error('插件已安装，如需重新安装请先卸载');
        }

        if (is_dir(plugin_path() . $plugin) && is_file(plugin_path() . $plugin . '/manifest.json')) {
            try {
                $result = $this->pluginManager->install($plugin);
                return $this->success('安装成功', $result->toArray());
            } catch (\Throwable $e) {
                return $this->error('安装失败：' . $e->getMessage());
            }
        }

        $licenseKey = (string) config('plugin.license_key', '');

        try {
            $pre = $this->marketCheckUpdate($plugin, $version);
        } catch (HttpException $e) {
            return $this->error('检查更新失败：' . $e->getMessage());
        }

        if ($pre === null) {
            ContextualLogger::warning('市场服务端不支持 checkUpdate 契约，回退旧版安装流程', ['plugin' => $plugin]);
            return $this->installLegacy($plugin, $version);
        }

        if ((string) ($pre['dist_url'] ?? '') === '') {
            return $this->error('市场未提供该插件的可安装版本（可能未上架或已下架）');
        }

        if ((int) ($pre['is_charge'] ?? 0) === 1) {
            if ($licenseKey === '') {
                return $this->error('该插件为付费插件，请先配置 config/plugin.php 的 license_key（或 .env 的 PLUGIN_LICENSE_KEY）');
            }

            $this->activateMarketLicense($licenseKey);
        }

        try {
            $res = (new HttpClient())->get((string) $pre['dist_url'], [
                'timeout'  => 0,
                'stream'   => true,
                'savefile' => plugin_path(),
                'query'    => array_filter(
                    ['license_key' => $licenseKey],
                    static fn ($value): bool => $value !== '' && $value !== null
                ),
            ]);
        } catch (HttpException $e) {
            return $this->error('下载安装包失败：' . $e->getMessage());
        }

        if ($jsonMsg = $this->jsonErrorFromSavedFile($res->getSavedPath())) {
            return $this->error($jsonMsg);
        }

        $archiveTool = new ArchiveTool();
        $list = $archiveTool->listContents($res->getSavedPath());

        if($list && count($list) > 1){
            $ret = $archiveTool->extract($res->getSavedPath(), plugin_path() . $plugin);
        }else{
            $ret = $archiveTool->extract($res->getSavedPath(), plugin_path());
        }
        
        if($ret !== true){
            return $this->error('解压缩插件文件失败：' . $archiveTool->getLastError());
        }

        try {
            $result = $this->pluginManager->install($plugin);
            return $this->success('安装成功', $result->toArray());
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
    
    /**
     * 卸载插件
     */
    #[OperationLog(template: '卸载插件 {plugin}', level: 'WARNING')]
    public function uninstall()
    {
        $plugin = $this->resolvePluginIdent('plugin');

        if (!$plugin) {
            return $this->error('请输入插件名称');
        }

        try {
            $result = $this->pluginManager->uninstall($plugin, false);

            if (($result['residual_tables'] ?? []) !== []) {
                return $this->error(
                    $result['message']
                    . '。插件已卸载，残留数据表需另行处理：'
                    . '请在插件列表找到「' . $plugin . '」这一行，点「重装」按钮 →'
                    . '选「清库重装（删除数据表）」清理残留；'
                    . '若只想让插件重新可用且保留数据，则选「仅修复状态（保留数据）」'
                );
            }

            $cleanup = (array) ($result['cleanup'] ?? []);
            if (($cleanup['menus_ok'] ?? true) === false || ($cleanup['permissions_ok'] ?? true) === false) {
                return $this->error(
                    $result['message']
                    . '。请在服务器上复核并清理残留的菜单/权限记录（见 runtime 日志）'
                );
            }

            return $this->success(
                '卸载成功（菜单与权限已软删，重装同名插件会自动恢复；插件源码目录已保留）',
                $cleanup
            );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
    
    /**
     * 重装插件（安装状态一致性冲突的修复入口）
     *
     * 两种模式：
     *   - repair（默认，安全）：保留数据表，只补齐安装状态/菜单权限/配置默认值/服务提供者。
     *     适用于"表结构完好，只是状态键丢了"这类分裂态（列表会显示「数据残留·需修复」）。
     *   - force（危险）：先清库（回滚迁移删表 + 删迁移记录）再走完整安装重建。
     *     适用于表结构已被改坏、必须重建的场景；**会删除该插件全部业务数据**。
     *
     * 之所以需要这个入口：`install` 只认"是否已安装"，遇到"表在但状态说没装"时
     * 会直接去跑建表迁移，撞 1050「Table ... already exists」，用户没有可用的出路。
     */
    #[OperationLog(template: '重装插件 {plugin}（模式 {mode}）', level: 'WARNING')]
    public function reinstall()
    {
        $plugin = $this->resolvePluginIdent('plugin');
        $mode   = $this->request->param('mode', 'repair', 'trim');

        if (!$plugin) {
            return $this->error('请输入插件标识');
        }
        if (!PluginManager::checkPluginIdent($plugin)) {
            return $this->error('插件标识不符合规范');
        }
        if (!in_array($mode, ['repair', 'force'], true)) {
            return $this->error('不支持的重装模式：' . $mode);
        }

        try {
            if ($mode === 'force') {
                $purge = $this->pluginManager->purgeResidual($plugin);
                if ($purge['residual_tables'] !== []) {
                    return $this->error(
                        '清库未完成，仍有 ' . count($purge['residual_tables']) . ' 张表未删除：'
                        . implode('、', $purge['residual_tables']),
                        ['residual_tables' => $purge['residual_tables']]
                    );
                }

                $result = $this->pluginManager->install($plugin);

                return $this->success('已清库重装完成（业务数据已删除）', $result->toArray());
            }

            $result = $this->pluginManager->repairInstallState($plugin);

            return $this->success('已修复安装状态（数据表与业务数据均保留）', $result);
        } catch (\Throwable $e) {
            return $this->error(
                ($mode === 'force' ? '清库重装失败：' : '修复安装状态失败：') . $e->getMessage()
            );
        }
    }

    /**
     * 升级插件
     */
    #[OperationLog(template: '升级插件 {name} 到 {version}', level: 'WARNING')]
    public function upgrade()
    {
        $name = $this->request->param('name', '', 'trim');
        $version = $this->request->param('version', '', 'trim');

        if (!$name) {
            return $this->error('请输入插件名称');
        }

        try {
            $pre = $this->marketCheckUpdate($name, $version);
        } catch (HttpException $e) {
            return $this->error('检查更新失败：' . $e->getMessage());
        }

        if ($pre === null) {
            return $this->error('市场服务端不支持检查更新（checkUpdate），无法获取新版本安装包；'
                . '请升级市场服务端，或改用「本地上传安装包」方式升级');
        }

        if ((string) ($pre['latest_version'] ?? '') === '' || (string) ($pre['dist_url'] ?? '') === '') {
            return $this->error('市场未提供可升级的版本');
        }

        if (empty($pre['has_update'])) {
            return $this->success('已是最新版本（' . $pre['latest_version'] . '）');
        }

        $ignoreCompat = (bool) $this->request->param('ignore_compat/d', 0);
        $ranges       = (array) ($pre['compatible_with'] ?? []);
        $minFramework = trim((string) ($pre['min_framework_version'] ?? ''));

        if ($ranges === [] && $minFramework !== '') {
            $ranges = ['>=' . $minFramework];
        }

        if (!$ignoreCompat && $ranges !== []
            && FrameworkCompatibility::check($ranges) === FrameworkCompatibility::INCOMPATIBLE
        ) {
            return $this->error(FrameworkCompatibility::violationMessage($name, $ranges));
        }

        $packageUrl = (string) $pre['dist_url'];
        $licenseKey = (string) config('plugin.license_key', '');

        if ((int) ($pre['is_charge'] ?? 0) === 1) {
            if ($licenseKey === '') {
                return $this->error('该插件为付费插件，请先配置 config/plugin.php 的 license_key 后重试');
            }

            $this->activateMarketLicense($licenseKey);
            $packageUrl .= (str_contains($packageUrl, '?') ? '&' : '?') . 'license_key=' . urlencode($licenseKey);
        }

        try {
            $result = $this->pluginManager->upgrade(
                $name,
                (string) $pre['latest_version'],
                $packageUrl,
                $ignoreCompat
            );
            return $this->success('升级成功', $result->toArray());
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
    
    /**
     * 向市场请求「检查更新」：取可安装版本信息与下载地址
     *
     * 契约（阶段 1）：`GET {plugin.update_url}?ident=&version=`
     * → `{code:0,data:{has_update,is_charge,latest_version,changelog,dist_url,min_framework_version}}`
     *
     * 返回语义：
     *   - array：市场正常响应；
     *   - null ：**端点不可用**（HTTP >= 400 / 网络异常 / 响应非标准信封）→ 调用方回退旧契约；
     *   - 抛 HttpException：市场返回了业务错误（`code != 0`）→ 调用方直接回显。
     *
     * @return array{has_update:bool,is_charge:int,latest_version:string,changelog:string,dist_url:string,min_framework_version:string}|null
     * @throws HttpException
     */
    protected function marketCheckUpdate(string $ident, string $version): ?array
    {
        $url = (string) config('plugin.update_url');
        if ($url === '') {
            return null;
        }

        try {
            $res = (new HttpClient())->get($url, [
                'timeout' => 15,
                'query'   => [
                    'ident'     => $ident,
                    'version'   => $version,
                    'framework' => FrameworkCompatibility::currentVersion(),
                ],
            ]);
        } catch (HttpException $e) {
            return null;
        }

        $json = $res->getJson();

        if (!is_array($json) || !array_key_exists('code', $json)) {
            return null;
        }

        if ((int) $json['code'] !== 0) {
            throw new HttpException((string) ($json['msg'] ?? '检查更新失败'));
        }

        $data = (array) ($json['data'] ?? []);

        return [
            'has_update'            => (bool) ($data['has_update'] ?? false),
            'is_charge'             => (int) ($data['is_charge'] ?? 0),
            'latest_version'        => (string) ($data['latest_version'] ?? ''),
            'changelog'             => (string) ($data['changelog'] ?? ''),
            'dist_url'              => (string) ($data['dist_url'] ?? ''),
            'min_framework_version' => (string) ($data['min_framework_version'] ?? ''),
            'compatible_with'       => FrameworkCompatibility::normalize($data['compatible_with'] ?? []),
            'framework_compatible'  => isset($data['framework_compatible']) ? (bool) $data['framework_compatible'] : null,
        ];
    }

    /**
     * 付费插件安装/升级前的许可域名绑定（幂等）
     *
     * 绑定失败**不阻断**主流程：是否放行最终由市场的下载鉴权裁决，此处只是让
     * 「首次激活」自动完成，省去用户手工调用的步骤。
     */
    protected function activateMarketLicense(string $licenseKey): void
    {
        $base = rtrim((string) config('plugin.service_url'), '/');
        if ($base === '') {
            return;
        }

        try {
            (new HttpClient())->post($base . '/api/market/license/activate', [
                'timeout'     => 15,
                'form_params' => [
                    'license_key' => $licenseKey,
                    'domain'      => (string) $this->request->domain(),
                ],
            ]);
        } catch (\Throwable $e) {
            ContextualLogger::warning('许可激活失败（不阻断安装/升级）', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 从**落盘文件**中识别市场返回的 JSON 错误信封
     *
     * 为什么必须这么做：流式下载（`stream + savefile`）时 `HttpClient` **不保留响应体**
     * （`Response::__construct($code, $headers, null)`，故 `getJson()` 恒为 null）。
     * 没有本判断，市场的 JSON 错误（如「付费插件需许可」）会被当成 zip 送进解压流程，
     * 最终报出与真实原因无关的「解压缩插件文件失败」。
     *
     * @param string|null $path 落盘路径（`Response::getSavedPath()`）
     * @return string|null 命中时返回可读错误信息；非 JSON 内容返回 null
     */
    protected function jsonErrorFromSavedFile(?string $path): ?string
    {
        if (!$path || !is_file($path)) {
            return null;
        }

        $head = (string) file_get_contents($path, false, null, 0, 8192);
        $head = ltrim($head);

        if ($head === '' || $head[0] !== '{') {
            return null;
        }

        $json = json_decode($head, true);
        if (is_array($json) && array_key_exists('code', $json)) {
            return (string) ($json['msg'] ?? '下载安装包失败');
        }

        return '下载安装包失败：市场返回了非插件包内容';
    }

    /**
     * 旧版在线安装流程（POST + form_params(plugin, version)）
     *
     * 仅作为**兼容通道**：目标市场服务端尚未提供 `checkUpdate` / GET 契约时使用。
     * 新市场服务端走 install() 内的新契约路径。
     */
    protected function installLegacy(string $plugin, string $version)
    {
        $url = config('plugin.download_url');

        try{
            $httpClient = new HttpClient();
            $res = $httpClient->post($url, [
                'timeout' => 0,
                'stream' => true,
                'savefile' => plugin_path(),
                'form_params' => [
                    'plugin' => $plugin,
                    'version' => $version,
                ],
            ]);

            if($json = $res->getJson()){
                return $this->error($json['msg'] ?? '下载安装包失败');
            }
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }

        if ($jsonMsg = $this->jsonErrorFromSavedFile($res->getSavedPath())) {
            return $this->error($jsonMsg);
        }

        $archiveTool = new ArchiveTool();
        $list = $archiveTool->listContents($res->getSavedPath());

        if($list && count($list) > 1){
            $ret = $archiveTool->extract($res->getSavedPath(), plugin_path() . $plugin);
        }else{
            $ret = $archiveTool->extract($res->getSavedPath(), plugin_path());
        }

        if($ret !== true){
            return $this->error('解压缩插件文件失败：' . $archiveTool->getLastError());
        }

        try {
            $result = $this->pluginManager->install($plugin);
            return $this->success('安装成功', $result->toArray());
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 启用插件
     */
    #[OperationLog(template: '启用插件 {name}', level: 'INFO')]
    public function enable()
    {
        $name = $this->resolvePluginIdent();
        try {
            $this->pluginManager->enable($name);
            return $this->success('启用成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 禁用插件
     */
    #[OperationLog(template: '禁用插件 {name}', level: 'WARNING')]
    public function disable()
    {
        $name = $this->resolvePluginIdent();
        try {
            $this->pluginManager->disable($name);
            return $this->success('禁用成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
    
    /**
     * 切换插件启用/禁用
     */
    #[OperationLog(template: '切换插件 {name} 状态', level: 'INFO')]
    public function toggle()
    {
        $name   = $this->resolvePluginIdent();
        $status = $this->request->param('status/d', -1);

        if (empty($name)) {
            return $this->error('参数错误');
        }

        try {
            if ($status != 0 && $this->pluginManager->getPluginState($name) === PluginManager::STATE_NOT_INSTALLED) {
                return $this->error('插件尚未安装，请先点击「安装」创建数据表');
            }

            if ($status == 1) {
                $this->pluginManager->enable($name);
            } elseif ($status == 0) {
                $this->pluginManager->disable($name);
            } else {
                $this->pluginManager->isEnabled($name)
                    ? $this->pluginManager->disable($name)
                    : $this->pluginManager->enable($name);
            }
            return $this->success('操作成功');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 插件详情
     */
    public function detail()
    {
        $name = $this->resolvePluginIdent();
        if (empty($name)) {
            return $this->error('请输入插件名称');
        }

        try {
            $plugin = $this->pluginManager->getPlugin($name);
            $data = is_object($plugin) && method_exists($plugin, 'toArray')
                ? $plugin->toArray()
                : (is_array($plugin) ? $plugin : []);

            $data['installed'] = $this->pluginManager->isInstalled($name);
            $data['enabled']   = $this->pluginManager->isEnabled($name);

            return $this->success('获取成功', $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 插件市场列表
     */
    public function online()
    {
        $keyword = $this->request->param('keyword/s', '', 'trim');
        $page    = $this->request->param('page/d', 1);
        $limit   = $this->request->param('limit/d', 10);

        try {
            $list = $this->pluginManager->getOnlinePluginList([
                'page'    => $page,
                'limit'   => $limit,
                'keyword' => $keyword,
            ]);
            return $this->success('获取成功', $list);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 验证插件标识
     */
    public function validateIdent()
    {
        $ident = $this->request->param('ident', '', 'trim');
        if (empty($ident)) {
            return $this->error('请输入插件标识');
        }

        $valid = PluginManager::checkPluginIdent($ident);
        return $this->success($valid ? '合法' : '不合法', ['valid' => $valid]);
    }

    /**
     * 插件配置页面（通用表单，按 manifest schema 渲染）。
     *
     * 视图为统一的 plugin/config.html，不再依赖各插件自建 config 模板 ——
     * 此前 fetch("plugin/{$name}/config") 指向的模板一个都不存在，点击「配置」必报错。
     *
     * 前端入口：public/statics/admin/plugin.js:264 以 layer.iframe 打开本页。
     */
    public function configIndex()
    {
        $name = $this->resolvePluginIdent();
        if ($name === '') {
            return '参数错误：缺少插件标识';
        }

        try {
            $plugin = $this->pluginManager->getPlugin($name);
            $schema = $this->pluginManager->getPluginConfigSchema($name);
        } catch (\Throwable $e) {
            return '插件不存在或无法读取配置：' . $e->getMessage();
        }

        $values = $schema === [] ? [] : $this->pluginManager->getPluginConfig($name);

        $configMeta = $this->pluginManager->getPluginConfigMeta($name);

        $inLayer = $this->request->param('layer/d', 0) === 1;

        return $this->assign([
            'plugin'       => $plugin->toArray(),
            'plugin_ident' => $name,
            'schema'       => $schema,
            'values'       => $values,
            'in_layer'     => $inLayer,
            'form_html'    => PluginConfigForm::render($schema, $values, [
                'tab_prefix' => $name,
                'tabs'       => $configMeta['tabs'],
                'groups'     => $configMeta['groups'],
            ]),
        ])->fetch('plugin/config');
    }

    /**
     * 保存插件配置（白名单 + 类型校验）。
     *
     * 关键点：jQuery 的 serialize() 会跳过未勾选的 checkbox / radio，
     * 因此开关（switch/boolean）在关闭状态、多选（checkbox）在全不选时
     * 不会出现在 POST 数据中。这里必须按 schema 逐个补齐缺失键，
     * 否则「关闭开关」将永远无法保存（库中仍是旧值 true）。
     */
    #[OperationLog(template: '保存插件 {name} 配置', level: 'INFO')]
    public function config()
    {
        $name = $this->resolvePluginIdent();
        if ($name === '') {
            return $this->error('参数错误：缺少插件标识');
        }

        try {
            $schema = $this->pluginManager->getPluginConfigSchema($name);
        } catch (\Throwable $e) {
            return $this->error('插件不存在或无法读取配置：' . $e->getMessage());
        }

        if ($schema === []) {
            return $this->error('该插件未声明任何配置项，无法保存');
        }

        $input = $this->request->post('config/a', []);

        $ignored = array_values(array_map('strval', array_diff(array_keys($input), array_keys($schema))));

        $data = [];
        foreach ($schema as $key => $field) {
            if (!array_key_exists($key, $input)) {
                $input[$key] = self::emptySubmitValue((string) $field['type']);
            }
            $data[$key] = PluginConfigSchema::validate($field, $input[$key]);
        }

        $missing = PluginConfigSchema::validateRequired($schema, $data);
        if ($missing !== []) {
            return $this->error('以下配置项为必填：' . implode('、', $missing));
        }

        try {
            $result = $this->pluginManager->setPluginConfig($name, $data);
        } catch (\Throwable $e) {
            return $this->error('配置保存失败：' . $e->getMessage());
        }

        $dropped = array_values(array_unique(array_merge($result['dropped'], $ignored)));

        $msg = '配置保存成功';

        if ($dropped !== []) {
            $msg .= '（忽略未声明字段：' . implode('、', $dropped) . '）';
        }

        return $this->success($msg, ['saved' => $result['saved'], 'dropped' => $dropped]);
    }

    /**
     * 获取字段在「前端未提交」时应填充的值。
     *
     * 对应 PluginConfigForm 中会被 serialize() 跳过的控件类型。
     *
     * @param string $type 字段类型
     * @return mixed
     */
    protected static function emptySubmitValue(string $type): mixed
    {
        return match ($type) {
            'checkbox'          => [],
            'switch', 'boolean' => false,
            default             => '',
        };
    }
}