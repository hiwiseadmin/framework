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
    public function index()
    {
        $adminId = CurrentAdmin::getAdminId();
        $adminData = AdminModel::where('id', $adminId)->findOrEmpty();
        
        return $this->assign('admin', $adminData)->fetch();
    }
    
    #[OperationLog(template: '更新个人资料', level: 'INFO')]
    public function update()
    {
        $adminId = CurrentAdmin::getAdminId();

        $allow = ['nickname', 'email', 'mobile', 'avatar', 'ext_fields'];
        $data  = array_intersect_key($this->request->post(), array_flip($allow));

        $admin = AdminModel::find($adminId);
        if (!$admin) {
            return $this->error('管理员不存在');
        }

        if (!$admin->validateSave($data, [], 'edit')) {
            return $this->error($admin->getValidateError());
        }

        $admin = CurrentAdmin::getInfo(true); 

        session('admin_username', $admin['username']);
        session('admin_nickname', $admin['nickname']);
        
        return $this->success('个人资料更新成功', $data);
    }
    
    #[OperationLog(template: '修改密码', level: 'WARNING')]
    public function changePassword()
    {
        $data = $this->request->post();

        $admin = AdminModel::find(CurrentAdmin::getAdminId());
        if (!$admin) {
            return $this->error('管理员不存在');
        }

        try {
            $admin->validateOrFail($data, [], 'change_password');
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getMessage());
        }

        if (!password_verify($data['old_password'], (string) $admin->password)) {
            return $this->error('当前密码错误');
        }

        $admin->password = $data['new_password'];
        $admin->save();
        
        return $this->success('密码修改成功');
    }
    
    public function getAdminData()
    {
        $adminId = CurrentAdmin::getAdminId();
        $adminData = AdminModel::where('id', $adminId)->findOrEmpty();
        
        if($adminData->isEmpty()){
            return $this->error('管理员不存在');
        }
        return $this->success('获取管理员数据成功', $adminData);
    }

    public function getAvatars()
    {
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

    #[OperationLog(template: '上传头像', level: 'INFO')]
    public function uploadCroppedAvatar()
    {
        if ($this->request->isPost()) {
            try {
                $data = $this->request->post();
                
                $imageData = $data['avatar_data'];
                
                if (strpos($imageData, 'base64,') !== false) {
                    $imageData = explode('base64,', $imageData)[1];
                }
                
                $imageData = base64_decode($imageData);
                
                if ($imageData === false) {
                    throw new \Exception('图片数据格式错误');
                }
                
                $filename = substr(md5(CurrentAdmin::getAdminId()), 0, 10) . '.png';
                $savePath = 'avatar/admin/' . date('Ymd') . '/' . $filename;
                
                $fullPath = public_path() . 'uploads/' . $savePath;
                DirFile::createDir(dirname($fullPath));
                
                file_put_contents($fullPath, $imageData);

                ImageCompressor::sourceFile($fullPath)->toFile($fullPath);

                $avatarUrl = '/uploads/' . $savePath;
                
                return $this->success('头像上传成功', ['avatar_url' => $avatarUrl]);
                
            } catch (\Exception $e) {
                return $this->error('头像上传失败：' . $e->getMessage());
            }
        }
        
        return $this->error('非法请求');
    }
}