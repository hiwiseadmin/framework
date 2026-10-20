<?php
namespace app\admin\validate;

use think\Validate;
use think\facade\Config;

class StorageConfig extends Validate
{
    protected $rule = [
        'id' => 'require|number',
        'name' => 'require|max:100',
        'driver' => 'require|in:local,aliyun,baidu',
        'config' => 'require|array',
        'status' => 'in:0,1',
        'is_default' => 'in:0,1'
    ];
    
    protected $message = [
        'id.require' => '配置ID不能为空',
        'id.number' => '配置ID必须为数字',
        'name.require' => '配置名称不能为空',
        'name.max' => '配置名称长度不能超过100个字符',
        'driver.require' => '存储类型不能为空',
        'driver.in' => '存储类型不正确',
        'config.require' => '配置信息不能为空',
        'config.array' => '配置信息必须为数组',
        'status.in' => '状态值不正确',
        'is_default.in' => '默认配置值不正确'
    ];
    
    // 添加场景
    public function sceneAdd()
    {
        return $this->only(['name', 'driver', 'config', 'status', 'is_default'])
                   ->remove('id', 'require')
                   ->append('config', 'checkConfig');
    }
    
    // 编辑场景
    public function sceneEdit()
    {
        return $this->only(['id', 'name', 'driver', 'config', 'status', 'is_default'])
                   ->append('config', 'checkConfig');
    }
    
    /**
     * 验证配置信息
     * @param mixed $value 配置值
     * @param string $rule 规则
     * @param array $data 所有数据
     * @return bool|string
     */
    protected function checkConfig($value, $rule, $data)
    {
        $driver = $data['driver'] ?? '';
        
        switch ($driver) {
            case 'local':
                return $this->validateLocalConfig($value);
            case 'aliyun':
                return $this->validateAliyunConfig($value);
            case 'baidu':
                return $this->validateBaiduConfig($value);
            default:
                return '不支持的存储类型';
        }
    }
    
    /**
     * 验证本地存储配置
     * @param array $config 配置信息
     * @return bool|string
     */
    protected function validateLocalConfig($config)
    {
        if (empty($config['root'])) {
            return '本地存储根目录不能为空';
        }
        
        if (empty($config['url'])) {
            return '本地存储访问URL不能为空';
        }
        
        // 检查目录是否可写
        $rootPath = public_path($config['root']);
        if (!is_dir($rootPath)) {
            // 尝试创建目录
            if (!mkdir($rootPath, 0755, true)) {
                return '本地存储根目录创建失败，请检查权限';
            }
        } elseif (!is_writable($rootPath)) {
            return '本地存储根目录不可写，请检查权限';
        }
        
        return true;
    }
    
    /**
     * 验证阿里云OSS配置
     * @param array $config 配置信息
     * @return bool|string
     */
    protected function validateAliyunConfig($config)
    {
        $requiredFields = ['access_key', 'secret_key', 'bucket', 'endpoint'];
        
        foreach ($requiredFields as $field) {
            if (empty($config[$field])) {
                return "阿里云OSS配置缺少必要字段: {$field}";
            }
        }
        
        // 验证access_key格式（通常是长度验证）
        if (strlen($config['access_key']) < 10) {
            return '阿里云AccessKey格式不正确';
        }
        
        // 验证secret_key格式
        if (strlen($config['secret_key']) < 10) {
            return '阿里云SecretKey格式不正确';
        }
        
        // 验证bucket名称格式
        if (!preg_match('/^[a-z0-9\-]{3,63}$/', $config['bucket'])) {
            return '阿里云Bucket名称格式不正确';
        }
        
        return true;
    }
    
    /**
     * 验证百度云BOS配置
     * @param array $config 配置信息
     * @return bool|string
     */
    protected function validateBaiduConfig($config)
    {
        $requiredFields = ['access_key', 'secret_key', 'bucket', 'endpoint'];
        
        foreach ($requiredFields as $field) {
            if (empty($config[$field])) {
                return "百度云BOS配置缺少必要字段: {$field}";
            }
        }
        
        // 验证access_key格式
        if (strlen($config['access_key']) < 10) {
            return '百度云AccessKey格式不正确';
        }
        
        // 验证secret_key格式
        if (strlen($config['secret_key']) < 10) {
            return '百度云SecretKey格式不正确';
        }
        
        // 验证bucket名称格式
        if (!preg_match('/^[a-z0-9\-]{3,63}$/', $config['bucket'])) {
            return '百度云Bucket名称格式不正确';
        }
        
        return true;
    }
}