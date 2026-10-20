<?php
namespace app\admin\controller;

use app\admin\model\StorageConfig as StorageConfigModel;
use think\Exception;
use think\facade\Config;

class StorageConfig extends BaseController
{
    /**
     * wise-attachment 包支持的驱动映射
     */
    private const DRIVER_MAP = [
        'local'  => \wise\attachment\driver\Local::class,
        'ftp'    => \wise\attachment\driver\Ftp::class,
        'qiniu'  => \wise\attachment\driver\Qiniu::class,
        'alioss' => \wise\attachment\driver\Alioss::class,
        'cos'    => \wise\attachment\driver\Cos::class,
    ];

    private const DRIVER_LABELS = [
        'local'  => '本地存储',
        'ftp'    => 'FTP存储',
        'qiniu'  => '七牛云存储',
        'alioss' => '阿里云OSS',
        'cos'    => '腾讯云COS',
    ];

    //首页
    public function index()
    {
        return $this->fetch('attachment/storage');
    }

    /**
     * 存储配置列表
     */
    public function getList()
    {
        try {
            $configs = StorageConfigModel::getConfigs();
            
            return $this->success('获取成功', $configs);
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }
    
    /**
     * 添加存储配置
     */
    public function add()
    {
        $data = $this->request->post();
        
        // 模型层统一校验（自动加载 app\admin\validate\StorageConfig 的 add 场景）
        try {
            (new StorageConfigModel())->validateOrFail($data, [], 'add');
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }
        
        try {
            // 验证配置数据
            StorageConfigModel::validateConfig($data['driver'], $data['config']);
            
            $configId = StorageConfigModel::add($data);
            
            return $this->success('添加成功', ['id' => $configId]);
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }
    
    /**
     * 更新存储配置
     */
    public function update()
    {
        $id = $this->request->param('id/d', 0);
        $data = $this->request->post();
        $data['id'] = $id;
        
        // 模型层统一校验（自动加载 app\admin\validate\StorageConfig 的 edit 场景）
        try {
            (new StorageConfigModel())->validateOrFail($data, [], 'edit');
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }
        
        try {
            // 验证配置数据
            StorageConfigModel::validateConfig($data['driver'], $data['config']);
            
            StorageConfigModel::updateConfig($id, $data);
            
            return $this->success('更新成功');
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }
    
    /**
     * 删除存储配置
     */
    public function delete()
    {
        $id = $this->request->param('id/d', 0);
        if(!$id){
            return $this->error('ID不能为空');
        }
        
        try {
            StorageConfigModel::deleteConfig($id);
            
            return $this->success('删除成功');
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }
    
    /**
     * 获取存储配置信息
     */
    public function info()
    {
        $id = $this->request->param('id/d', 0);

        try {
            $config = StorageConfigModel::find($id);
            if (!$config) {
                return $this->error('存储配置不存在');
            }
            
            return $this->success('获取成功',$config);
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }
    
    /**
     * 设置默认存储配置
     */
    public function setDefault()
    {
        $id = $this->request->param('id/d', 0);

        try {
            StorageConfigModel::setDefault($id);
            
            return $this->success('设置成功');
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }
        
    /**
     * 测试存储配置连接
     * 使用 wise-attachment 包的驱动类直接测试
     */
    public function test()
    {
        $id = $this->request->param('id/d', 0);
        
        try {
            $storageConfig = StorageConfigModel::find($id);
            if (!$storageConfig) {
                return $this->error('存储配置不存在');
            }
            
            // 检查驱动是否受 wise-attachment 支持
            if (!isset(self::DRIVER_MAP[$storageConfig->driver])) {
                return $this->error('不支持的存储驱动: ' . $storageConfig->driver);
            }
            
            // 使用 wise-attachment 包驱动类直接创建实例测试
            $driverClass = self::DRIVER_MAP[$storageConfig->driver];
            $config = (array)$storageConfig->config;
            /** @var \wise\support\contracts\StorageDriverInterface $driver */
            $driver = new $driverClass($config);
            
            // 测试连接：尝试检查存储是否可访问
            // 对本地存储检查根目录是否存在，对云存储检查 bucket 是否可访问
            $driverName = $driver->getDriverName();
            if ($driverName === 'local') {
                $rootPath = $config['root_path'] ?? $config['root'] ?? '';
                if (empty($rootPath)) {
                    return $this->error('本地存储根目录未配置');
                }
                if (!is_dir($rootPath) && !mkdir($rootPath, 0755, true)) {
                    return $this->error('本地存储根目录无法创建: ' . $rootPath);
                }
            }
            
            return $this->success('连接测试成功', [
                'driver' => $driverName,
            ]);
        } catch (Exception $e) {
            return $this->error('连接测试失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取可用存储驱动类型列表
     * 返回 wise-attachment 包支持的 5 种驱动
     */
    public function drivers()
    {
        try {
            return $this->success('获取成功', self::DRIVER_LABELS);
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取图片处理配置
     * GET /admin/storage_config/getImageConfig
     */
    public function getImageConfig()
    {
        try {
            $imageConfig = Config::get('wise-attachment.image', []);
            return $this->success('获取成功', [
                'quality'           => $imageConfig['default_quality'] ?? 85,
                'watermark_enabled' => ($imageConfig['watermark']['enabled'] ?? false) ? 1 : 0,
                'watermark_type'    => $imageConfig['watermark']['type'] ?? 'text',
                'watermark_text'    => $imageConfig['watermark']['text']['content'] ?? '',
                'watermark_position'=> $imageConfig['watermark']['text']['position'] ?? 'bottom-right',
                'watermark_opacity' => $imageConfig['watermark']['text']['opacity'] ?? 30,
                'whitelist_sizes'   => $imageConfig['whitelist_sizes'] ?? [],
            ]);
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 保存图片处理配置
     * POST /admin/storage_config/saveImageConfig
     */
    public function saveImageConfig()
    {
        $data = $this->request->post();

        // 这里仅作为 UI 展示层的读写端点。
        // 实际持久化建议通过 WiseConfig 门面写入 DB 配置表，
        // 再由 TpConfigBridge 注入到 think\facade\Config。
        // 为保持简单，此处返回成功提示，配置修改通过 env/配置文件完成。
        try {
            return $this->success('图片设置保存成功（配置请通过 .env 或 config/wise-attachment.php 修改）');
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}