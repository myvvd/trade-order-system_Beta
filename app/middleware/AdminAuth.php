<?php

namespace app\middleware;

use think\facade\Session;
use think\Request;
use think\Response;

/**
 * 管理端登录验证中间件
 */
class AdminAuth
{
    /**
     * 不需要登录验证的路由
     * @var array
     */
    protected $except = [
//        'admin/login',
//        'admin/dologin',
//        'admin/logout',
//        'admin/captcha',
        'login/index',
        'login/login',
        'login/logout',
    ];

    /**
     * 处理请求
     * @param Request $request
     * @param \Closure $next
     * @return Response
     */
    public function handle(Request $request, \Closure $next)
    {
        // 获取当前路由（小写，用于匹配）
        $route = strtolower($request->pathinfo());

        // 检查是否在排除列表中
        if ($this->isExcept($route)) {
            return $next($request);
        }

        // 检查登录状态
        $userId = Session::get('admin_user_id');
        $userInfo = Session::get('admin_user_info');

        if (!$userId || !$userInfo) {
            // 未登录，跳转到登录页
            if ($request->isAjax()) {
                return json([
                    'code'    => 401,
                    'msg'     => '请先登录',
                    'success'  => false,
                ]);
            }
            return redirect('/admin/login')->with('error', '请先登录');
        }

        // 已登录，将用户信息写入 request
        $request->adminUserId = $userId;
        $request->adminUserInfo = $userInfo;

        return $next($request);
    }

    /**
     * 检查路由是否在排除列表中
     * @param string $route
     * @return bool
     */
    protected function isExcept(string $route): bool
    {
        $route = trim($route, '/');

        foreach ($this->except as $except) {
            $except = trim($except, '/');

            // 精确匹配（如 admin/login == admin/login）
            if ($route === $except) {
                return true;
            }

            // 前缀匹配（如 admin/login/* 匹配 admin/login）
            if (str_ends_with($except, '*') && str_starts_with($route, rtrim($except, '*'))) {
                return true;
            }
        }

        return false;
    }
}
