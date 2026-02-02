<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\AdminUser;
use app\validate\AdminUserValidate;
use think\facade\Session;
use think\Response;

/**
 * 管理端登录控制器
 */
class Login extends Base
{
    /**
     * 显示登录页面
     * @return string
     */

    public function index()
    {
        // 已登录则跳转到首页
        if ($this->isLogin()) {
            return redirect('/admin');
        }

        return view('admin/login/index');
    }

    /**
     * 处理登录请求
     * @return Response
     */
    public function login(): Response
    {


        // 仅允许 POST 请求
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        // 获取登录参数
        $params = $this->post();
        $username = $params['username'] ?? '';
        $password = $params['password'] ?? '';

        // 验证参数
        try {
            validate([
                'username' => 'require',
                'password' => 'require',
            ], [
                'username.require' => '请输入用户名',
                'password.require' => '请输入密码',
            ])->check($params);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        // 查找用户
        $user = AdminUser::getByUsername($username);

        if (!$user) {
            return $this->error('用户名或密码错误');
        }

        // 验证密码
        if (!$user->checkPassword($password)) {

            return $this->error('用户名或密码错误');
        }

        // 检查账号状态
        if (!$user->isActive()) {
            return $this->error('账号已被禁用，请联系管理员');
        }

        // 记录登录信息
        $user->recordLogin($this->request->ip());

        // 写入 session
        Session::set('admin_user_id', $user->id);
        Session::set('admin_user_info', $user->toArray());

        // 缓存权限
        $permissions = $user->getPermissions();
        Session::set('user_permissions_' . $user->id, $permissions);

        return $this->success([
            'user_id'   => $user->id,
            'username'   => $user->username,
            'real_name' => $user->real_name,
            'role_id'   => $user->role_id,
        ], '登录成功');
    }

    /**
     * 退出登录
     * @return Response
     */
    public function logout()
    {
        // 清除 session
        Session::delete('admin_user_id');
        Session::delete('admin_user_info');
        Session::delete('user_permissions_' . ($this->userId ?? 0));

        // 清除所有 session
        Session::clear();

        // 跳转到登录页
        return redirect('/admin/login')->with('success', '已安全退出');
    }
}
