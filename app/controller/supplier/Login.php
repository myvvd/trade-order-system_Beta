<?php

namespace app\controller\supplier;

use app\controller\supplier\Base;
use app\model\Supplier;
use app\validate\SupplierValidate;
use think\facade\Session;
use think\Response;

/**
 * 供应商端登录控制器
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
            return redirect('/supplier');
        }

        return view('supplier/login/index');
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
        $account = trim($params['login_account'] ?? '');
        $password = trim($params['login_password'] ?? '');

        // 验证参数
        try {
            validate([
                'login_account' => 'require',
                'login_password' => 'require',
            ], [
                'login_account.require' => '请输入登录账号',
                'login_password.require' => '请输入密码',
            ])->check($params);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        // 查找供应商
        $supplier = Supplier::getByAccount($account);

        if (!$supplier) {
            return $this->error('登录账号或密码错误');
        }

        // 验证密码
        if (!$supplier->checkPassword($password)) {
            return $this->error('登录账号或密码错误');
        }

        // 检查账号状态
        if (!$supplier->isActive()) {
            return $this->error('账号已被禁用，请联系管理员');
        }

        // 记录登录信息
        $supplier->recordLogin($this->request->ip());

        // 准备 session 数据
        $sessionData = $supplier->toArray();
        $sessionData['supplier_id'] = $supplier->id; // 确保 supplier_id 存在

        // 写入 session（使用供应商专用的 session key）
        Session::set('supplier_user_id', $supplier->id);
        Session::set('supplier_user_info', $sessionData);

        return $this->success([
            'supplier_id'   => $supplier->id,
            'name'          => $supplier->name,
            'contact'       => $supplier->contact,
            'login_account' => $supplier->login_account,
        ], '登录成功');
    }

    /**
     * 退出登录
     * @return Response
     */
    public function logout()
    {
        // 清除供应商 session
        Session::delete('supplier_user_id');
        Session::delete('supplier_user_info');

        // 清除所有 session
        Session::clear();

        // 跳转到登录页
        return redirect('/admin/login')->with('success', '已安全退出');
    }
}
