<?php
namespace app\common\service;

use think\facade\Cache;

class CacheService
{
    const PREFIX = 'attachment:';
    
    const TTL = 3600;
    
    /**
     * 获取分类缓存
     * @return array|null
     */
    public static function getCategories()
    {
        return Cache::get(self::PREFIX . 'categories');
    }
    
    /**
     * 设置分类缓存
     * @param array $categories 分类数据
     * @return bool
     * 修复：使用 Cache::tag('attachment')->set() 替代 Cache::set()，使标签与 clearAll() 关联
     */
    public static function setCategories($categories)
    {
        return Cache::tag('attachment')->set(self::PREFIX . 'categories', $categories, self::TTL);
    }
    
    /**
     * 删除分类缓存
     * @return bool
     */
    public static function deleteCategories()
    {
        return Cache::delete(self::PREFIX . 'categories');
    }
    
    /**
     * 获取分组缓存
     * @return array|null
     */
    public static function getGroups()
    {
        return Cache::get(self::PREFIX . 'groups');
    }
    
    /**
     * 设置分组缓存
     * @param array $groups 分组数据
     * @return bool
     * 修复：使用 Cache::tag('attachment')->set() 替代 Cache::set()，使标签与 clearAll() 关联
     */
    public static function setGroups($groups)
    {
        return Cache::tag('attachment')->set(self::PREFIX . 'groups', $groups, self::TTL);
    }
    
    /**
     * 删除分组缓存
     * @return bool
     */
    public static function deleteGroups()
    {
        return Cache::delete(self::PREFIX . 'groups');
    }
    
    /**
     * 获取存储配置缓存
     * @return array|null
     */
    public static function getStorageConfigs()
    {
        return Cache::get(self::PREFIX . 'storage_configs');
    }
    
    /**
     * 设置存储配置缓存
     * @param array $configs 存储配置数据
     * @return bool
     * 修复：使用 Cache::tag('attachment')->set() 替代 Cache::set()，使标签与 clearAll() 关联
     */
    public static function setStorageConfigs($configs)
    {
        return Cache::tag('attachment')->set(self::PREFIX . 'storage_configs', $configs, self::TTL);
    }
    
    /**
     * 删除存储配置缓存
     * @return bool
     */
    public static function deleteStorageConfigs()
    {
        return Cache::delete(self::PREFIX . 'storage_configs');
    }
    
    /**
     * 获取文件信息缓存
     * @param string $md5 文件MD5
     * @return array|null
     */
    public static function getFileInfo($md5)
    {
        return Cache::get(self::PREFIX . 'file:' . $md5);
    }
    
    /**
     * 设置文件信息缓存
     * @param string $md5 文件MD5
     * @param array $fileInfo 文件信息
     * @return bool
     * 修复：使用 Cache::tag('attachment')->set() 替代 Cache::set()，使标签与 clearAll() 关联
     */
    public static function setFileInfo($md5, $fileInfo)
    {
        return Cache::tag('attachment')->set(self::PREFIX . 'file:' . $md5, $fileInfo, self::TTL);
    }
    
    /**
     * 删除文件信息缓存
     * @param string $md5 文件MD5
     * @return bool
     */
    public static function deleteFileInfo($md5)
    {
        return Cache::delete(self::PREFIX . 'file:' . $md5);
    }
    
    /**
     * 清空所有附件相关缓存
     * @return bool
     */
    public static function clearAll()
    {
        Cache::tag('attachment')->clear();
        return true;
    }
}