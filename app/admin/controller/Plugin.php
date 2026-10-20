<?php
namespace app\admin\controller;

use wise\plugin\PluginManager;
use wise\plugin\PluginContainerKeys;
use wise\plugin\config\PluginConfigForm;
use wise\plugin\config\PluginConfigSchema;
// use wise\plugin\exception\PluginDependencyException;
// use wise\plugin\composer_bridge\ComposerEngine;
// use wise\plugin\composer_bridge\PluginComposerInstaller;
use think\facade\Filesystem;
use wise\support\tools\ArchiveTool;
use wise\support\tools\HttpClient;
use wise\support\tools\HttpException;
use wise\support\context\ContextualLogger;
use wise\operationlog\attribute\OperationLog;

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
        // 单行模式：带上 row_ident 时只返回该插件的一行（结构与列表项完全一致）。
        // 供前端「安装/卸载成功后只刷新该行按钮状态」使用 —— 不再整表 refresh，
        // 避免列表重排/滚动复位，让运营可以连续安装多个插件而不必每次重新查找。
        // 复用本动作而非新开路由：沿用同一套权限匹配，避免非超管被 403。
        // 参数名带前缀，避免与列表自身的查询参数（sort/filter/search…）撞名。
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

        // 分类参数：远程市场用数字 catid，本地插件用分类名（manifest 的 category 字段）。
        // 故先取原始字符串，远程场景才强转为 int —— 若一开始就用 catid/d 强转，
        // 中文分类名会被吞成 0，本地分类查询将失效。
        $catidRaw = $this->request->param('catid/s', '0', 'trim');
        $catid    = (int) $catidRaw;

        $type = $this->request->param('type/s', '', 'trim');
        $keyword = $this->request->param('keyword/s', '', 'trim');
        
        if($type == 'local'){
            // 本地插件模式同样支持分类（manifest 的 category 字段）与关键字查询，
            // 与远程列表共用同一套分类 tab（catid 即本地分类名）
            $list = $this->pluginManager->getLocalPluginList([
                'catid'   => $catidRaw,
                'keyword' => $keyword,
            ]);
        }else{
            $list = $this->pluginManager->getOnlinePluginList(['page' => $this->page, 'limit' => $this->limit, 'catid' => $catid, 'keyword' => $keyword]);

            // 在线列表兜底补齐 actions，确保前端按钮展示可控
            if (!empty($list['list'])) {
                foreach ($list['list'] as &$item) {
                    $ident = $item['ident'] ?? ($item['name'] ?? '');
                    // 保证前端 pk（ident）始终有值
                    $item['ident'] = $ident;
                    // 远程数据不可信，先做标识合法性校验再查询本地状态
                    $state = ($ident !== '' && PluginManager::checkPluginIdent($ident))
                        ? $this->pluginManager->getPluginState($ident)
                        : PluginManager::STATE_NOT_INSTALLED;
                    $installed = $state !== PluginManager::STATE_NOT_INSTALLED;

                    // 与本地列表保持一致：未安装只给"安装"，已安装给"卸载"（+远程声明的其它动作）
                    if (empty($item['actions']) || !is_array($item['actions'])) {
                        $item['actions'] = $installed ? ['uninstall'] : ['install'];
                    } elseif ($installed) {
                        if (!in_array('uninstall', $item['actions'], true)) {
                            $item['actions'][] = 'uninstall';
                        }
                        // 已安装时远程返回的 install 已失效，移除避免同时出现"安装/卸载"
                        $item['actions'] = array_values(array_filter(
                            $item['actions'],
                            static fn ($action) => $action !== 'install'
                        ));
                    }

                    $item['installed'] = $installed ? 1 : 0;
                    $item['state']     = $state;
                    $item['status']    = $state === PluginManager::STATE_ENABLED ? 1 : 0;
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

        // 解压缩文件
        if ($file) {
            $safeName = preg_replace('/[^\w\.\-]/u', '', $file->getOriginalName());
            // $savename = config('filesystem.disks.local.root') . '/plugins/' . $safeName;
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
        // 检查插件标识合法性
        if(!PluginManager::checkPluginIdent($plugin)){
            return $this->error('插件标识不符合规范');
        }

        // 已安装的插件不允许重复安装：迁移可幂等，但 seed 会重复写入初始数据
        if ($this->pluginManager->isInstalled($plugin)) {
            return $this->error('插件已安装，如需重新安装请先卸载');
        }

        // 插件包已在 plugins/ 目录（手动放置 / 开发版 / 之前下载过）时，
        // 无需再从市场下载，直接执行本地安装流程（迁移 + 填充 + 菜单权限）
        if (is_dir(plugin_path() . $plugin) && is_file(plugin_path() . $plugin . '/manifest.json')) {
            try {
                $result = $this->pluginManager->install($plugin);
                return $this->success('安装成功', $result->toArray());
            } catch (\Throwable $e) {
                return $this->error('安装失败：' . $e->getMessage());
            }
        }

        // ===== Composer 引擎模式（插件以 Composer 包形式分发时）=====
        // 由插件市场回传 composer_package，或站点配置 plugin.install_mode=composer 时启用。
        // 该模式下版本冲突由 Composer 求解器在安装前统一检测，无需手写解压。
        // $composerPackage = $this->request->param('composer_package', '', 'trim');
        // $composerMode = $composerPackage !== '' || (string) config('plugin.install_mode') === 'composer';
        // if ($composerMode) {
        //     try {
        //         $engine = new ComposerEngine();
        //         $installer = new PluginComposerInstaller($engine, $this->pluginManager);
        //         $package = $composerPackage !== '' ? $composerPackage : ('wiseadmin/' . $plugin);
        //         $constraint = ($version !== '' && $version !== 'last' && $version !== 'all') ? $version : '';
        //         $result = $installer->install($package, $constraint, $plugin);
        //         return $this->success('安装成功', $result['metadata']->toArray());
        //     } catch (PluginDependencyException $e) {
        //         return $this->error('依赖版本冲突：' . $e->getMessage(), ['blockers' => $e->getMissingDependencies()]);
        //     } catch (\Exception $e) {
        //         return $this->error('安装失败：' . $e->getMessage());
        //     }
        // }
        // ── 下载安装包（契约见 docs/wise-market-upgrade-plan.md 阶段 1）────────────
        // 新契约：GET + query(ident, version[, license_key])。
        // 目标市场服务端若未提供 checkUpdate（旧契约），自动回退 installLegacy()。
        $licenseKey = (string) config('plugin.license_key', '');

        try {
            $pre = $this->marketCheckUpdate($plugin, $version);
        } catch (HttpException $e) {
            // 业务错误（如参数不完整）：直接回显，不触发旧流程回退
            return $this->error('检查更新失败：' . $e->getMessage());
        }

        if ($pre === null) {
            ContextualLogger::warning('市场服务端不支持 checkUpdate 契约，回退旧版安装流程', ['plugin' => $plugin]);
            return $this->installLegacy($plugin, $version);
        }

        if ((string) ($pre['dist_url'] ?? '') === '') {
            return $this->error('市场未提供该插件的可安装版本（可能未上架或已下架）');
        }

        // 付费插件必须带许可：提前给出可执行提示，避免把 JSON 错误信封当成插件包去解压
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

        // 流式模式下 HttpClient 不保留响应体（Response::getJson() 恒为 null），
        // 故错误信封需从**落盘文件**识别；否则会把 JSON 当 zip 解压，报出误导性的「解压失败」
        if ($jsonMsg = $this->jsonErrorFromSavedFile($res->getSavedPath())) {
            return $this->error($jsonMsg);
        }

        // 解压缩插件安装包
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
            // $removeFiles = false：后台卸载**保留插件源码目录**，这样用户还能再次安装；
            // 若要连目录一起删，用 CLI `php think plugin:uninstall <name>`（默认删目录）。
            $result = $this->pluginManager->uninstall($plugin, false);

            // 迁移回滚没清干净时必须让用户看见：否则界面提示"卸载成功"，
            // 用户再点安装就会撞 1050「数据表已存在」，而库里其实一堆残留表。
            if (($result['residual_tables'] ?? []) !== []) {
                return $this->error(
                    $result['message']
                    . '。插件已卸载，残留数据表需另行处理：'
                    . '请在插件列表找到「' . $plugin . '」这一行，点「重装」按钮 →'
                    . '选「清库重装（删除数据表）」清理残留；'
                    . '若只想让插件重新可用且保留数据，则选「仅修复状态（保留数据）」'
                );
            }

            // 菜单/权限清理失败不能被"卸载成功"掩盖（2026-10-13 加固）
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
                // 第一步：清库（只清数据表与迁移记录，保留插件目录与静态资源）
                $purge = $this->pluginManager->purgeResidual($plugin);
                if ($purge['residual_tables'] !== []) {
                    return $this->error(
                        '清库未完成，仍有 ' . count($purge['residual_tables']) . ' 张表未删除：'
                        . implode('、', $purge['residual_tables']),
                        ['residual_tables' => $purge['residual_tables']]
                    );
                }

                // 第二步：完整安装（重建表 + 灌初始数据 + 菜单权限 + 状态）
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

        // 契约（阶段 1）：先向市场确认「有新版本」并取下载地址，再交给 PluginManager 执行。
        // 历史实现在此处**不传 packageUrl**，而 PluginManager::upgrade() 只在拿到 packageUrl 时
        // 才下载新包 → 「升级」实际只重跑迁移、不换版本（见 docs/wise-market-upgrade-plan.md §三）。
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

        $packageUrl = (string) $pre['dist_url'];
        $licenseKey = (string) config('plugin.license_key', '');

        // 付费插件：带上许可下载（下载端点会校验 license_key）
        if ((int) ($pre['is_charge'] ?? 0) === 1) {
            if ($licenseKey === '') {
                return $this->error('该插件为付费插件，请先配置 config/plugin.php 的 license_key 后重试');
            }

            $this->activateMarketLicense($licenseKey);
            $packageUrl .= (str_contains($packageUrl, '?') ? '&' : '?') . 'license_key=' . urlencode($licenseKey);
        }

        try {
            $result = $this->pluginManager->upgrade($name, (string) $pre['latest_version'], $packageUrl);
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
                'query'   => ['ident' => $ident, 'version' => $version],
            ]);
        } catch (HttpException $e) {
            // 旧契约市场服务端（无该端点 → 404）或网络不可达
            return null;
        }

        $json = $res->getJson();

        // 非标准信封（旧服务端返回 HTML 404 页等）→ 视为契约不可用
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

        // 是 JSON 但不是标准信封 → 仍然不是插件包，给出明确提示而不是让它去解压
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
        // 从远程服务器下载插件安装包
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

        // 解压缩插件安装包
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
            // 开关只负责 enabled 状态位；未安装的插件不允许用开关启用，
            // 建表必须由"安装"按钮显式触发，避免静默创建数据库
            if ($status != 0 && $this->pluginManager->getPluginState($name) === PluginManager::STATE_NOT_INSTALLED) {
                return $this->error('插件尚未安装，请先点击「安装」创建数据表');
            }

            if ($status == 1) {
                $this->pluginManager->enable($name);
            } elseif ($status == 0) {
                $this->pluginManager->disable($name);
            } else {
                // 自动取反
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

        // 生效值 = schema 默认值 ⊕ 已存储值
        $values = $schema === [] ? [] : $this->pluginManager->getPluginConfig($name);

        // 展示元数据（tab / group 的中文标题与顺序）：manifest 顶层 config_tabs / config_groups
        $configMeta = $this->pluginManager->getPluginConfigMeta($name);

        return $this->assign([
            // 传数组而非 PluginMetadata 对象：模板用 {$plugin.xxx} 访问，
            // 对象既无 public 属性也未实现 ArrayAccess，会触发
            // "Cannot use object of type PluginMetadata as array"
            'plugin'       => $plugin->toArray(),
            'plugin_ident' => $name,
            'schema'       => $schema,
            'values'       => $values,
            // tab_prefix：Tab 的 HTML id 前缀（取插件标识），避免多插件/多次渲染时 id 冲突
            // tabs / groups：展示元数据来自 manifest 顶层声明（中文标题与顺序），未声明时渲染器回退内置表
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

        // 在补齐缺失键之前统计「未声明的键」：表单由 schema 渲染时不会出现，
        // 一旦出现说明字段名写错或请求被手工构造，需明确提示便于排查。
        $ignored = array_values(array_map('strval', array_diff(array_keys($input), array_keys($schema))));

        // 按 schema 收敛：缺失键按类型补空值，保证开关能真正关闭
        $data = [];
        foreach ($schema as $key => $field) {
            if (!array_key_exists($key, $input)) {
                $input[$key] = self::emptySubmitValue((string) $field['type']);
            }
            $data[$key] = PluginConfigSchema::validate($field, $input[$key]);
        }

        // 必填校验
        $missing = PluginConfigSchema::validateRequired($schema, $data);
        if ($missing !== []) {
            return $this->error('以下配置项为必填：' . implode('、', $missing));
        }

        try {
            $result = $this->pluginManager->setPluginConfig($name, $data);
        } catch (\Throwable $e) {
            return $this->error('配置保存失败：' . $e->getMessage());
        }

        // 合并两处丢弃的键：控制器层（未声明）与存储层（保留键）
        $dropped = array_values(array_unique(array_merge($result['dropped'], $ignored)));

        $msg = '配置保存成功';

        // 有字段被丢弃时明确告知，避免「提示成功但没保存」的困惑
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