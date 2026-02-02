<?php

namespace app\middleware;

use think\facade\Session;
use think\Request;
use think\Response;

/**
 * 供应商端登录验证中间件
 */
class SupplierAuth
{
    /**
     * 不需要登录验证的路由
     * @var array
     */
    protected $except = [
        'supplier/login',
        'supplier/login/dologin',
        'supplier/logout',
        'supplier/captcha',
    ];

    /**
     * 处理请求
     * @param Request $request
     * @param \Closure $next
     * @return Response
     */
    public function handle(Request $request, \Closure $next)
    {
        // 获取当前路由
        $route = $request->pathinfo();

        // 检查是否在排除列表中
        if ($this->isExcept($route)) {
            return $next($request);
        }

        // 检查登录状态
        $userId = Session::get('supplier_user_id');
        $userInfo = Session::get('supplier_user_info');

        if (!$userId || !$userInfo) {
            // 未登录，跳转到登录页
            if ($request->isAjax()) {
                return json([
                    'code'    => 401,
                    'msg'     => '请先登录',
                    'success'  => false,
                ]);
            }
            return redirect('/supplier/login')->with('error', '请先登录');
        }

        // 检查供应商账号状态
        if (isset($userInfo['status']) && $userInfo['status'] != 1) {
            // 账号已被禁用，清除 session 并跳转
            Session::delete('supplier_user_id');
            Session::delete('supplier_user_info');
            return redirect('/supplier/login')->with('error', '账号已被禁用，请联系管理员');
        }

        // 已登录，将供应商信息写入 request
        $request->supplierUserId = $userId;
        $request->supplierId = $userInfo['supplier_id'] ?? null;
        $request->supplierUserInfo = $userInfo;

        return $next($request);
    }

    /**
     * 检查路由是否在排除列表中
     * @param string $route
     * @return bool
     */
    protected function isExcept(string $route): bool
    {
        // 标准化路由（移除首尾斜杠）
        $route = trim($route, '/');

        foreach ($this->except as $except) {
            $except = trim($except, '/');
            // 精确匹配
            if ($route === $except) {
                return true;
            }
            // 前缀匹配（如 supplier/login/*）
            if (str_ends_with($except, '*') && str_starts_with($route, rtrim($except, '*'))) {
                return true;
            }
        }

        return false;
    }
}
