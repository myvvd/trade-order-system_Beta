<?php

namespace app\middleware;

use think\facade\Session;
use think\Request;
use think\Response;

/**
 * 权限验证中间件
 * 超级管理员（role_id=1）：可访问所有页面
 * 其他角色：只能访问订单和消息相关页面
 */
class Permission
{
    /**
     * 不需要权限验证的路由前缀
     * @var array
     */
    protected $except = [
        'admin/login',
        'admin/logout',
        'admin/profile',
    ];

    /**
     * 非超管可访问的路由前缀（订单 + 消息）
     * @var array
     */
    protected $allowedForNonSuper = [
        'admin/order',
        'admin/message',
    ];

    /**
     * 超级管理员角色ID
     * @var int
     */
    const SUPER_ADMIN_ROLE_ID = 1;

    /**
     * 处理请求
     * @param Request $request
     * @param \Closure $next
     * @return Response
     */
    public function handle(Request $request, \Closure $next)
    {
        $route = trim($request->pathinfo(), '/');

        // 登录/登出等无需验证的路由直接放行
        if ($this->isExcept($route)) {
            return $next($request);
        }

        // 获取用户角色信息
        $userInfo = $request->adminUserInfo ?? null;
        $roleId = $userInfo['role_id'] ?? null;

        // 未登录或无角色，交给认证中间件处理
        if (!$userInfo || !$roleId) {
            return $next($request);
        }

        // 超级管理员直接通过
        if ((int)$roleId === self::SUPER_ADMIN_ROLE_ID) {
            return $next($request);
        }

        // 非超管：只允许访问订单和消息相关页面
        // admin 首页也重定向到订单列表
        if ($route === 'admin' || $route === 'admin/' || $route === 'admin/index') {
            return redirect('/admin/order/list');
        }

        if (!$this->isAllowedForNonSuper($route)) {
            if ($request->isAjax()) {
                return json([
                    'code'    => 403,
                    'msg'     => '无权限访问',
                    'success' => false,
                ]);
            }
            return $this->show403();
        }

        return $next($request);
    }

    /**
     * 检查路由是否在排除列表中
     */
    protected function isExcept(string $route): bool
    {
        foreach ($this->except as $except) {
            if ($route === $except || str_starts_with($route, $except . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查非超管是否可访问该路由
     */
    protected function isAllowedForNonSuper(string $route): bool
    {
        foreach ($this->allowedForNonSuper as $prefix) {
            if ($route === $prefix || str_starts_with($route, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * 显示 403 页面
     */
    protected function show403(): Response
    {
        return response('
            <!DOCTYPE html>
            <html>
            <head><title>403 无权限</title></head>
            <body style="display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:Arial;">
                <div style="text-align:center;">
                    <h1 style="font-size:72px;color:#f53f3f;margin:0;">403</h1>
                    <p style="font-size:18px;color:#86909c;margin:10px 0;">抱歉，您没有权限访问此页面</p>
                    <a href="/admin/order/list" style="color:#165DFF;text-decoration:none;">返回订单列表</a>
                </div>
            </body>
            </html>
        ', 403);
    }
}
