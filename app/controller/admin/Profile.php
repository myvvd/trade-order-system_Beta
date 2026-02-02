<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\AdminUser as AdminUserModel;
use app\validate\AdminUserValidate;
use think\facade\Session;
use think\facade\View;
use think\Response;

class Profile extends Base
{
    public function index()
    {
        $id = $this->getAdminId();
        $user = AdminUserModel::find($id);
        return View::fetch('admin/profile/index', ['user' => $user, 'title' => '个人设置']);
    }

    public function update(): Response
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $id = $this->getAdminId();
        $params = $this->post();

        try {
            validate(AdminUserValidate::class)->scene('update')->check(array_merge(['id' => $id], $params));
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $user = AdminUserModel::find($id);
        if (!$user) return $this->error('用户不存在');

        try {
            $user->save([
                'real_name' => $params['real_name'] ?? $user->real_name,
                'phone' => $params['phone'] ?? $user->phone,
                'email' => $params['email'] ?? $user->email,
            ]);
            // 更新 session
            Session::set('admin_user_info', $user->toArray());
            return $this->success(null, '更新成功');
        } catch (\Exception $e) {
            return $this->error('更新失败：' . $e->getMessage());
        }
    }

    public function password(): Response
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $id = $this->getAdminId();
        $old = $this->post('old_password', '');
        $new = $this->post('new_password', '');

        // 检查旧密码
        $user = AdminUserModel::find($id);
        if (!$user) return $this->error('用户不存在');
        if (!$user->checkPassword($old)) return $this->error('旧密码错误');

        try {
            validate(AdminUserValidate::class)->scene('edit_password')->check(['id' => $id, 'password' => $new]);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        try {
            $user->save(['password' => $new]);
            return $this->success(null, '密码修改成功');
        } catch (\Exception $e) {
            return $this->error('修改失败：' . $e->getMessage());
        }
    }
}
