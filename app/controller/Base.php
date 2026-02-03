<?php

namespace app\controller;

use think\facade\Session;
use think\facade\View;
use think\exception\HttpException;
use think\Response;
use app\BaseController;

/**
 * 通用基础控制器
 */
abstract class Base extends \app\BaseController
{
    /**
     * 中间件配置
     * @var array
     */
    protected $middleware = [];

    /**
     * 当前登录用户ID
     * @var int|null
     */
    protected $userId = null;

    /**
     * 当前登录用户信息
     * @var array|null
     */
    protected $userInfo = null;

    /**
     * Session key 前缀（子类覆盖）
     * @var string
     */
    protected $sessionPrefix = '';

    /**
     * 初始化方法
     */
    protected function initialize()
    {
        parent::initialize();

        // 获取当前登录用户信息
        $this->getLoginUser();

        // 将用户信息赋值给视图变量
        $this->assignView();
    }

    /**
     * 获取当前登录用户信息
     */
    protected function getLoginUser()
    {
        $userIdKey = $this->sessionPrefix . '_user_id';
        $userInfoKey = $this->sessionPrefix . '_user_info';

        $this->userId = Session::get($userIdKey);
        $this->userInfo = Session::get($userInfoKey);
    }

    /**
     * 检查是否已登录
     * @return bool
     */
    protected function isLogin(): bool
    {
        return !empty($this->userId) && !empty($this->userInfo);
    }

    /**
     * 视图渲染辅助方法 - 赋值变量到视图
     * @param array|string $name 变量名或变量数组
     * @param mixed $value 变量值
     * @return $this
     */
    protected function assignView($name = '', $value = '')
    {
        // 将用户信息自动传递到视图
        View::assign([
            'user_id'   => $this->userId,
            'user_info' => $this->userInfo,
        ]);

        if (is_array($name)) {
            View::assign($name);
        } elseif ($name !== '') {
            View::assign($name, $value);
        }

        return $this;
    }

    /**
     * 返回成功 JSON
     * @param mixed $data 返回数据
     * @param string $msg 提示信息
     * @param int $code 状态码
     * @return Response
     */
    protected function success($data = null, string $msg = '操作成功', int $code = 200): Response
    {
        $result = [
            'code'    => $code,
            'msg'     => $msg,
            'success'  => true,
        ];

        if ($data !== null) {
            $result['data'] = $data;
        }

        return json($result);
    }

    /**
     * 返回错误 JSON
     * @param string $msg 错误信息
     * @param int $code 状态码
     * @param mixed $data 额外数据
     * @return Response
     */
    protected function error(string $msg = '操作失败', int $code = 400, $data = null): Response
    {
        $result = [
            'code'    => $code,
            'msg'     => $msg,
            'success'  => false,
        ];

        if ($data !== null) {
            $result['data'] = $data;
        }

        return json($result);
    }

    /**
     * 通用分页处理
     * @param \think\Model|\think\db\Query $query 查询对象
     * @param int $pageSize 每页条数
     * @param array $config 额外配置
     * @return array 分页数据
     */
    protected function paginate($query, int $pageSize = 15, array $config = []): array
    {
        $page = input('page/d', 1);
        $list = $query->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
            'path'      => '',
        ]);

        return [
            'list'      => $list->items(),
            'total'     => $list->total(),
            'page'      => $page,
            'page_size' => $pageSize,
            'page_total'=> $list->lastPage(),
        ];
    }

    /**
     * 获取分页参数
     * @return array [page, pageSize]
     */
    protected function getPageParams(): array
    {
        return [
            input('page/d', 1),
            input('page_size/d', 15),
        ];
    }

    /**
     * 抛出异常
     * @param string $msg 错误信息
     * @param int $code 状态码
     * @throws HttpException
     */
    protected function abort(string $msg, int $code = 400): void
    {
        throw new HttpException($code, $msg);
    }

    /**
     * 成功时跳转
     * @param string $url 跳转URL
     * @param string $msg 提示信息
     * @return Response
     */
    protected function redirectSuccess(string $url, string $msg = '操作成功'): Response
    {
        return redirect($url)->with('success', $msg);
    }

    /**
     * 失败时跳转
     * @param string $url 跳转URL
     * @param string $msg 提示信息
     * @return Response
     */
    protected function redirectError(string $url, string $msg = '操作失败'): Response
    {
        return redirect($url)->with('error', $msg);
    }

    /**
     * 获取请求参数
     * @param string $key 参数名
     * @param mixed $default 默认值
     * @param string $filter 过滤方法
     * @return mixed
     */
    protected function param(string $key, $default = null, string $filter = '')
    {
        return input($key, $default, $filter);
    }

    /**
     * 获取POST参数
     * @param string $key 参数名
     * @param mixed $default 默认值
     * @param string $filter 过滤方法
     * @return mixed
     */
    protected function post(string $key = '', $default = null, string $filter = '')
    {
        if ($key === '') {
            return input('post.');
        }
        return input('post.' . $key, $default, $filter);
    }

    /**
     * 获取GET参数
     * @param string $key 参数名
     * @param mixed $default 默认值
     * @param string $filter 过滤方法
     * @return mixed
     */
    protected function get(string $key = '', $default = null, string $filter = '')
    {
        return input('get.' . $key, $default, $filter);
    }

    /**
     * 判断是否为POST请求
     * @return bool
     */
    protected function isPost(): bool
    {
        return $this->request->isPost();
    }

    /**
     * 判断是否为GET请求
     * @return bool
     */
    protected function isGet(): bool
    {
        return $this->request->isGet();
    }

    /**
     * 判断是否为AJAX请求
     * @return bool
     */
    protected function isAjax(): bool
    {
        return $this->request->isAjax();
    }
}
