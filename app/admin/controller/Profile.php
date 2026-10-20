<?php
namespace app\admin\controller;

use think\facade\Filesystem;
use app\admin\model\Admin as AdminModel;
use app\admin\facade\CurrentAdmin;
use wise\support\tools\DirFile;
use tihiy\Compressor\ImageCompressor;
use wise\operationlog\attribute\OperationLog;

#[OperationLog(channel: 'admin', tags: ['admin', 'profile'])]
class Profile extends BaseController
{
    // 显示个人资料页面
    public function index()
    {
        $adminId = CurrentAdmin::getAdminId();
        $adminData = AdminModel::where('id', $adminId)->findOrEmpty();
        
        return $this->assign('admin', $adminData)->fetch();
    }
    
    // 处理个人资料更新
    #[OperationLog(template: '更新个人资料', level: 'INFO')]
    public function update()
    {
        $adminId = CurrentAdmin::getAdminId();

        // 仅允许更新白名单字段，杜绝批量赋值（防 username/password/role_ids/status 等被篡改）
        $allow = ['nickname', 'email', 'mobile', 'avatar', 'ext_fields'];
        $data  = array_intersect_key($this->request->post(), array_flip($allow));

        $admin = AdminModel::find($adminId);
        if (!$admin) {
            return $this->error('管理员不存在');
        }

        // 走模型层统一校验 + 保存（自动加载 app\admin\validate\Admin 的 edit 场景）
        if (!$admin->validateSave($data, [], 'edit')) {
            return $this->error($admin->getValidateError());
        }

        // 刷新管理员信息缓存
        $admin = CurrentAdmin::getInfo(true); 

        // 更新session中的管理员信息
        session('admin_username', $admin['username']);
        session('admin_nickname', $admin['nickname']);
        
        return $this->success('个人资料更新成功', $data);
    }
    
    // 处理密码修改
    #[OperationLog(template: '修改密码', level: 'WARNING')]
    public function changePassword()
    {
        $data = $this->request->post();

        $admin = AdminModel::find(CurrentAdmin::getAdminId());
        if (!$admin) {
            return $this->error('管理员不存在');
        }

        // 模型层统一校验（自动加载 app\admin\validate\Admin 的 change_password 场景）
        try {
            $admin->validateOrFail($data, [], 'change_password');
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getMessage());
        }

        // 校验旧密码是否正确
        if (!password_verify($data['old_password'], (string) $admin->password)) {
            return $this->error('当前密码错误');
        }

        // 更新密码（走 setPasswordAttr 加密）
        $admin->password = $data['new_password'];
        $admin->save();
        
        return $this->success('密码修改成功');
    }
    
    // 获取管理员数据（用于AJAX）
    public function getAdminData()
    {
        // 模拟从数据库获取管理员数据
        $adminId = CurrentAdmin::getAdminId();
        $adminData = AdminModel::where('id', $adminId)->findOrEmpty();
        
        if($adminData->isEmpty()){
            return $this->error('管理员不存在');
        }
        return $this->success('获取管理员数据成功', $adminData);
    }

    // 获取预设头像列表
    public function getAvatars()
    {
        // 预设头像列表（实际项目中可以从数据库或配置文件中读取）
        $avatars = [];
        for ($i = 1; $i <= 12; $i++) {
            $avatars[] = [
                'id' => $i,
                'url' => '/statics/images/avatar/' . sprintf('%02d', $i) . '.jpg',
                'name' => '头像' . $i
            ];
        }
        
        return $this->success('ok',$avatars);
    }

    // 处理头像裁切上传
    #[OperationLog(template: '上传头像', level: 'INFO')]
    public function uploadCroppedAvatar()
    {
        if ($this->request->isPost()) {
            try {
                $data = $this->request->post();
                
                // 获取Base64图片数据
                $imageData = $data['avatar_data'];
                
                // 移除Base64前缀
                if (strpos($imageData, 'base64,') !== false) {
                    $imageData = explode('base64,', $imageData)[1];
                }
                
                // 解码Base64数据
                $imageData = base64_decode($imageData);
                
                if ($imageData === false) {
                    throw new \Exception('图片数据格式错误');
                }
                
                // 生成文件名
                $filename = substr(md5(CurrentAdmin::getAdminId()), 0, 10) . '.png';
                $savePath = 'avatar/admin/' . date('Ymd') . '/' . $filename;
                
                // 保存图片
                $fullPath = public_path() . 'uploads/' . $savePath;
                DirFile::createDir(dirname($fullPath));
                
                file_put_contents($fullPath, $imageData);

                // 压缩图片
                ImageCompressor::sourceFile($fullPath)->toFile($fullPath);

                // 返回图片URL
                $avatarUrl = '/uploads/' . $savePath;
                
                return $this->success('头像上传成功', ['avatar_url' => $avatarUrl]);
                
            } catch (\Exception $e) {
                return $this->error('头像上传失败：' . $e->getMessage());
            }
        }
        
        return $this->error('非法请求');
    }
}