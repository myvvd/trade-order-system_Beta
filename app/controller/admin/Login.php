<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\AdminUser;
use app\model\Supplier;
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
        $username = trim($params['username'] ?? '');
        $password = trim($params['password'] ?? '');
        $role = $params['role'] ?? 'admin';

        // 验证参数
        try {
            validate([
                'username' => 'require',
                'password' => 'require',
            ], [
                'username.require' => '请输入用户名',
                'password.require' => '请输入密码',
            ])->check(['username' => $username, 'password' => $password]);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        // 供应商登录
        if ($role === 'supplier') {
            $supplier = Supplier::getByAccount($username);

            if (!$supplier) {
                return $this->error('登录账号或密码错误');
            }

            if (!$supplier->checkPassword($password)) {
                return $this->error('登录账号或密码错误');
            }

            if (!$supplier->isActive()) {
                return $this->error('账号已被禁用，请联系管理员');
            }

            $supplier->recordLogin($this->request->ip());

            $sessionData = $supplier->toArray();
            $sessionData['supplier_id'] = $supplier->id;
            $sessionData['supplier_name'] = $supplier->name ?? '';
            $sessionData['contact_person'] = $supplier->contact ?? '';
            $sessionData['contact_phone'] = $supplier->phone ?? '';

            // 清理管理员会话，写入供应商会话
            Session::delete('admin_user_id');
            Session::delete('admin_user_info');
            Session::set('supplier_user_id', $supplier->id);
            Session::set('supplier_user_info', $sessionData);

            return $this->success([
                'role'        => 'supplier',
                'supplier_id' => $supplier->id,
                'name'        => $supplier->name,
                'login_account' => $supplier->login_account,
            ], '登录成功');
        }

        if ($role !== 'admin') {
            return $this->error('登录角色无效');
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

        // 写入 session（管理员）
        Session::delete('supplier_user_id');
        Session::delete('supplier_user_info');
        Session::set('admin_user_id', $user->id);
        Session::set('admin_user_info', $user->toArray());

        // 缓存权限
        $permissions = $user->getPermissions();
        Session::set('user_permissions_' . $user->id, $permissions);

        return $this->success([
            'role'      => 'admin',
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
