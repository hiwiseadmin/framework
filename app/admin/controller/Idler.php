<?php
namespace app\admin\controller;

use think\facade\Cache;
use wise\support\tools\DirFile;

/**
 * 临时工
 */
class Idler extends BaseController
{
    /**
     * 清除缓存
     */
    public function clearCache(){
        $type = $this->request->post('type','all');
        $runtimePath = root_path() . 'runtime/';

        if($type == 'all'){
            try{
                $this->clearTemplateCache($runtimePath);
                $this->clearPluginCache($runtimePath);
                $this->clearModuleCache($runtimePath);
                return $this->success('缓存已清理');
            }catch(\Exception $e){
                return $this->error('缓存清理失败：' . $e->getMessage());
            }
        }else if($type == 'template'){
            try{
                $this->clearTemplateCache($runtimePath);
                return $this->success('模板缓存已清理');
            }catch(\Exception $e){
                return $this->error('模板缓存清理失败：' . $e->getMessage());
            }
        }else if($type == 'plugin'){
            try{
                $this->clearPluginCache($runtimePath);
                return $this->success('插件缓存已清理');
            }catch(\Exception $e){
                return $this->error('插件缓存清理失败：' . $e->getMessage());
            }
        }else if($type == 'module'){
            try{
                $this->clearModuleCache($runtimePath);
                return $this->success('模块缓存已清理');
            }catch(\Exception $e){
                return $this->error('模块缓存清理失败：' . $e->getMessage());
            }
        }else{
            return $this->error('未指定缓存清理类型');
        }
    }

    /**
     * 清除模板缓存
     */
    private function clearTemplateCache(string $runtimePath): void
    {
        $dirs = scandir($runtimePath);
        $excludes = ['.', '..', 'temp', 'cache', 'log', 'session'];
        foreach ($dirs as $dir) {
            if (!is_dir($runtimePath . $dir) || in_array($dir, $excludes)) {
                continue;
            }
            if (is_dir($runtimePath . $dir . '/temp')) {
                DirFile::rmdirs($runtimePath . $dir . '/temp', false);
            }
        }
    }

    /**
     * 清除插件缓存
     */
    private function clearPluginCache(string $runtimePath): void
    {
        DirFile::rmdirs($runtimePath . 'temp', false);
    }

    /**
     * 清除模块缓存
     */
    private function clearModuleCache(string $runtimePath): void
    {
        Cache::store('shared_file')->inc('module_version');
    }
}