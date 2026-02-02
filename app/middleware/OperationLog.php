<?php

namespace app\middleware;

use think\facade\Db;
use think\Request;
use think\Response;
use app\model\OperationLog as OperationLogModel;

/**
 * 操作日志记录中间件
 */
class OperationLog
{
    /**
     * 不需要记录日志的路由
     * @var array
     */
    protected $except = [
        'admin/login',
        'admin/logout',
        'supplier/login',
        'supplier/logout',
        // 静态资源
        '/static/',
        '/assets/',
    ];

    /**
     * 需要记录的请求方法
     * @var array
     */
    protected $logMethods = ['POST', 'PUT', 'DELETE'];

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

        // 检查请求方法
        $method = $request->method();
        if (!in_array($method, $this->logMethods)) {
            return $next($request);
        }

        // 执行请求并记录
        $startTime = microtime(true);
        $response = $next($request);
        $endTime = microtime(true);

        // 异步记录日志（不阻塞响应）
        $this->recordLog($request, $response, $route, $method, $endTime - $startTime);

        return $response;
    }

    /**
     * 记录操作日志
     * @param Request $request
     * @param Response $response
     * @param string $route
     * @param string $method
     * @param float $duration
     */
    protected function recordLog(Request $request, Response $response, string $route, string $method, float $duration)
    {
        try {
            // 获取用户信息
            $userId = null;
            $userType = '';

            if (isset($request->adminUserId)) {
                $userId = $request->adminUserId;
                $userType = 'admin';
            } elseif (isset($request->supplierUserId)) {
                $userId = $request->supplierUserId;
                $userType = 'supplier';
            }

            // 解析模块和操作
            $path = explode('/', trim($route, '/'));
            $module = $path[0] ?? '';
            $action = $path[1] ?? '';

            // 处理请求参数（过滤敏感信息）
            $params = $request->param();
            $params = $this->filterParams($params);

            // 记录日志
            Db::name('operation_log')->insert([
                'user_id'     => $userId,
                'user_type'    => $userType,
                'module'       => $module,
                'action'       => $action,
                'method'       => $method,
                'url'          => $request->url(true),
                'params'       => json_encode($params, JSON_UNESCAPED_UNICODE),
                'ip'           => $request->ip(),
                'user_agent'    => $request->header('user-agent', ''),
                'create_time'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // 日志记录失败不影响业务
            // 实际项目中可以写入文件日志
        }
    }

    /**
     * 过滤敏感参数
     * @param array $params
     * @return array
     */
    protected function filterParams(array $params): array
    {
        $sensitiveKeys = [
            'password', 'pwd', 'old_password', 'new_password', 'confirm_password',
            'card_number', 'card_no', 'cvv', 'ssn', 'email', 'authorization', 'token', 'api_key', 'access_token'
        ];

        $maxLen = 200; // 对过长的参数进行截断

        foreach ($params as $key => $value) {
            $lk = strtolower((string)$key);
            if (in_array($lk, $sensitiveKeys, true)) {
                $params[$key] = '******';
                continue;
            }

            // 如果是数组或对象，尝试简化展现并截断
            if (is_array($value) || is_object($value)) {
                $json = json_encode($value, JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    $params[$key] = 'json_error';
                } else {
                    $params[$key] = mb_strlen($json) > $maxLen ? mb_substr($json, 0, $maxLen) . '...[truncated]' : $json;
                }
                continue;
            }

            // 普通字符串/标量，截断非常长的值以减少日志泄露
            if (is_string($value) && mb_strlen($value) > $maxLen) {
                $params[$key] = mb_substr($value, 0, $maxLen) . '...[truncated]';
            }
        }

        return $params;
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
            // 精确匹配
            if ($route === $except) {
                return true;
            }
            // 前缀匹配
            if (str_ends_with($except, '*') && str_starts_with($route, rtrim($except, '*'))) {
                return true;
            }
            // 静态资源匹配
            if (str_contains($route, $except)) {
                return true;
            }
        }

        return false;
    }
}
