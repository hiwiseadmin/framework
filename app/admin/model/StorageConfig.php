<?php
namespace app\admin\model;

use app\common\model\BaseModel;
use think\facade\Db;

class StorageConfig extends BaseModel
{
    protected $name = 'storage_config';
    
    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    // JSON字段自动转换
    protected $json = ['config'];
    
    /**
     * 获取可用的存储配置
     * @return array
     */
    public static function getConfigs()
    {
        return self::order('is_default', 'desc')
                  ->order('id', 'asc')
                  ->select();
    }

    /**
     * 获取可用的存储配置（仅启用状态）
     * @return array
     */
    public static function getAvailableConfigs()
    {
        return self::where('status', 1)
                  ->order('is_default', 'desc')
                  ->order('id', 'asc')
                  ->select();
    }
    
    /**
     * 获取默认存储配置
     * @return StorageConfig|null
     */
    public static function getDefaultConfig()
    {
        return self::where('status', 1)
                  ->where('is_default', 1)
                  ->find();
    }
    
    /**
     * 根据类型获取配置
     * @param string $driver 存储类型
     * @return StorageConfig|null
     */
    public static function getByDriver($driver)
    {
        return self::where('status', 1)
                  ->where('driver', $driver)
                  ->find();
    }
    
    /**
     * 添加存储配置
     * @param array $data 配置数据
     * @return bool
     * @throws \Exception
     */
    public static function add($data)
    {
        Db::startTrans();
        try {
            // 如果设置为默认配置，取消其他默认配置
            if (isset($data['is_default']) && $data['is_default'] == 1) {
                self::where('is_default', 1)->update(['is_default' => 0]);
            }
            
            $config = new self();
            $config->save([
                'name' => $data['name'],
                'driver' => $data['driver'],
                'config' => $data['config'],
                'status' => $data['status'] ?? 1,
                'is_default' => $data['is_default'] ?? 0
            ]);
            
            Db::commit();
            return $config->id;
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception('添加存储配置失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 更新存储配置
     * @param int $id 配置ID
     * @param array $data 配置数据
     * @return bool
     * @throws \Exception
     */
    public static function updateConfig($id, $data)
    {
        $config = self::find($id);
        if (!$config) {
            throw new \Exception('存储配置不存在');
        }
        
        Db::startTrans();
        try {
            // 如果设置为默认配置，取消其他默认配置
            if (isset($data['is_default']) && $data['is_default'] == 1) {
                self::where('is_default', 1)
                    ->where('id', '<>', $id)
                    ->update(['is_default' => 0]);
            }
            
            $config->save($data);
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception('更新存储配置失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 删除存储配置
     * @param int $id 配置ID
     * @return bool
     * @throws \Exception
     */
    public static function deleteConfig($id)
    {
        $config = self::find($id);
        if (!$config) {
            throw new \Exception('存储配置不存在');
        }
        
        // 检查是否为默认配置
        if ($config->is_default == 1) {
            throw new \Exception('不能删除默认存储配置');
        }
        
        // 检查是否有附件使用此存储驱动（使用 wise-attachment 包模型）
        if (class_exists('\wise\attachment\model\AttachmentModel')) {
            $attachmentCount = \wise\attachment\model\AttachmentModel::where('storage', $config->driver)
                                          ->whereNull('delete_time')
                                          ->count();
            if ($attachmentCount > 0) {
                throw new \Exception('有 ' . $attachmentCount . ' 个附件使用此存储驱动，无法删除');
            }
        }
        
        Db::startTrans();
        try {
            $config->delete();
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception('删除存储配置失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 设置默认存储配置
     * @param int $id 配置ID
     * @return bool
     * @throws \Exception
     */
    public static function setDefault($id)
    {
        $config = self::find($id);
        if (!$config) {
            throw new \Exception('存储配置不存在');
        }
        
        if ($config->status != 1) {
            throw new \Exception('存储配置已禁用');
        }
        
        Db::startTrans();
        try {
            // 取消其他默认配置
            self::where('is_default', 1)->update(['is_default' => 0]);
            
            // 设置当前为默认
            $config->save(['is_default' => 1]);
            
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception('设置默认配置失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 验证配置数据（支持 wise-attachment 全部 5 种驱动）
     * @param string $driver 存储类型: local|ftp|qiniu|alioss|cos
     * @param array $config 配置数据
     * @return bool
     * @throws \Exception
     */
    public static function validateConfig($driver, $config)
    {
        switch ($driver) {
            case 'local':
                if (empty($config['root_path']) && empty($config['root'])) {
                    throw new \Exception('本地存储根目录不能为空');
                }
                if (empty($config['base_url']) && empty($config['url'])) {
                    throw new \Exception('本地访问URL不能为空');
                }
                break;
            case 'ftp':
                $required = ['host', 'username', 'password', 'root'];
                foreach ($required as $field) {
                    if (empty($config[$field])) {
                        throw new \Exception('FTP存储配置不完整，缺少: ' . $field);
                    }
                }
                break;
            case 'qiniu':
                $required = ['access_key', 'secret_key', 'bucket', 'domain'];
                foreach ($required as $field) {
                    if (empty($config[$field])) {
                        throw new \Exception('七牛云存储配置不完整，缺少: ' . $field);
                    }
                }
                break;
            case 'alioss':
                $required = ['access_key', 'secret_key', 'bucket', 'endpoint'];
                foreach ($required as $field) {
                    if (empty($config[$field])) {
                        throw new \Exception('阿里云OSS配置不完整，缺少: ' . $field);
                    }
                }
                break;
            case 'cos':
                $required = ['access_key', 'secret_key', 'bucket', 'region'];
                foreach ($required as $field) {
                    if (empty($config[$field])) {
                        throw new \Exception('腾讯云COS配置不完整，缺少: ' . $field);
                    }
                }
                break;
            default:
                throw new \Exception('不支持的存储类型: ' . $driver . '（支持: local/ftp/qiniu/alioss/cos）');
        }
        
        return true;
    }
}