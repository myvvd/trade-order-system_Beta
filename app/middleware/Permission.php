<?php

namespace app\middleware;

use think\facade\Session;
use think\Request;
use think\Response;

/**
 * 权限验证中间件
 */
class Permission
{
    /**
     * 不需要权限验证的路由
     * @var array
     */
    protected $except = [
        'admin',
        'admin/index',
        'admin/dashboard',
        'admin/login',
        'admin/logout',
        'admin/profile',
        'supplier',
        'supplier/index',
        'supplier/login',
        'supplier/logout',
        'supplier/settings',
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
        // 获取当前路由
        $route = $request->pathinfo();

        // 检查是否在排除列表中
        if ($this->isExcept($route)) {
            return $next($request);
        }

        // 获取用户角色信息
        $userInfo = null;
        $roleId = null;

        if (isset($request->adminUserInfo)) {
            $userInfo = $request->adminUserInfo;
            $roleId = $userInfo['role_id'] ?? null;
        }

        // 未登录或无角色，交给认证中间件处理
        if (!$userInfo || !$roleId) {
            return $next($request);
        }

        // 超级管理员直接通过
        if ($roleId == self::SUPER_ADMIN_ROLE_ID) {
            return $next($request);
        }

        // 检查权限
        if (!$this->checkPermission($route, $userInfo)) {
            if ($request->isAjax()) {
                return json([
                    'code'    => 403,
                    'msg'     => '无权限访问',
                    'success'  => false,
                ]);
            }
            return $this->show403($request);
        }

        return $next($request);
    }

    /**
     * 检查用户权限
     * @param string $route
     * @param array $userInfo
     * @return bool
     */
    protected function checkPermission(string $route, array $userInfo): bool
    {
        // 获取用户权限列表
        $permissions = $this->getUserPermissions($userInfo);

        // 如果权限为空，说明没有配置，默认拒绝
        if (empty($permissions)) {
            return false;
        }

        // 解析路由对应的权限标识
        $permissionKey = $this->routeToPermission($route);

        // 检查是否在权限列表中
        return in_array($permissionKey, $permissions, true);
    }

    /**
     * 获取用户权限列表
     * @param array $userInfo
     * @return array
     */
    protected function getUserPermissions(array $userInfo): array
    {
        $permissions = [];

        // 从 session 中获取（如果已缓存）
        $cachedPermissions = Session::get('user_permissions_' . ($userInfo['id'] ?? 0));
        if ($cachedPermissions !== null) {
            return $cachedPermissions;
        }

        // 从数据库获取
        try {
            $roleId = $userInfo['role_id'] ?? 0;
            $role = \think\facade\Db::name('admin_role')->where('id', $roleId)->find();

            if ($role && !empty($role['permissions'])) {
                $permissions = json_decode($role['permissions'], true);
                $permissions = $permissions ?? [];

                // 缓存到 session
                Session::set('user_permissions_' . ($userInfo['id'] ?? 0), $permissions);
            }
        } catch (\Exception $e) {
            // 获取失败，返回空数组
        }

        return $permissions;
    }

    /**
     * 将路由转换为权限标识
     * @param string $route
     * @return string
     */
    protected function routeToPermission(string $route): string
    {
        $path = explode('/', trim($route, '/'));
        $module = $path[0] ?? '';
        $controller = $path[1] ?? '';
        $action = $path[2] ?? '';

        // 权限标识格式：模块.控制器.操作
        // 例如：admin.order.create
        return implode('.', array_filter([$module, $controller, $action]));
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
            if ($route === $except) {
                return true;
            }
            if (str_ends_with($except, '*') && str_starts_with($route, rtrim($except, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 显示 403 页面
     * @param Request $request
     * @return Response
     */
    protected function show403(Request $request): Response
    {
        if (str_starts_with($request->pathinfo(), 'admin/')) {
            // 管理端 403 页面
            return response('
                <!DOCTYPE html>
                <html>
                <head><title>403 无权限</title></head>
                <body style="display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:Arial;">
                    <div style="text-align:center;">
                        <h1 style="font-size:72px;color:#f53f3f;margin:0;">403</h1>
                        <p style="font-size:18px;color:#86909c;margin:10px 0;">抱歉，您没有权限访问此页面</p>
                        <a href="/admin" style="color:#165DFF;text-decoration:none;">返回首页</a>
                    </div>
                </body>
                </html>
            ', 403);
        }

        // 供应商端 403 页面
        return response('
            <!DOCTYPE html>
            <html>
            <head><title>403 无权限</title></head>
            <body style="display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:Arial;">
                <div style="text-align:center;">
                    <h1 style="font-size:72px;color:#f53f3f;margin:0;">403</h1>
                    <p style="font-size:18px;color:#86909c;margin:10px 0;">抱歉，您没有权限访问此页面</p>
                    <a href="/supplier" style="color:#165DFF;text-decoration:none;">返回首页</a>
                </div>
            </body>
            </html>
        ', 403);
    }
}
