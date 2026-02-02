<?php

namespace app\controller\admin;

use app\controller\Base as ControllerBase;
use think\facade\Session;
use think\facade\View;

/**
 * 管理端基础控制器
 */
abstract class Base extends ControllerBase
{
    /**
     * 管理端 Session key 前缀
     * @var string
     */
    protected $sessionPrefix = 'admin';

    /**
     * 初始化方法
     */
    protected function initialize()
    {
        parent::initialize();

        // 检查登录状态（可选，中间件已处理）
        // $this->checkLogin();
    }

    /**
     * 获取管理端用户ID
     * @return int|null
     */
    protected function getAdminId(): ?int
    {
        return $this->userId;
    }

    /**
     * 获取管理端用户名
     * @return string
     */
    protected function getAdminName(): string
    {
        return $this->userInfo['username'] ?? '';
    }

    /**
     * 获取管理端用户真实姓名
     * @return string
     */
    protected function getAdminRealName(): string
    {
        return $this->userInfo['real_name'] ?? '';
    }

    /**
     * 获取管理端用户角色ID
     * @return int|null
     */
    protected function getRoleId(): ?int
    {
        return $this->userInfo['role_id'] ?? null;
    }

    /**
     * 检查是否为超级管理员
     * @return bool
     */
    protected function isSuperAdmin(): bool
    {
        return ($this->userInfo['role_id'] ?? 0) === 1;
    }

    /**
     * 检查登录状态
     * @return bool
     */
    protected function isLogin(): bool
    {
        return !empty(Session::get($this->sessionPrefix . '_user_id'));
    }

    /**
     * 获取管理端用户信息
     * @return array|null
     */
    protected function getAdminInfo(): ?array
    {
        return Session::get('admin_user_info');
    }

    /**
     * 赋值管理端视图变量
     */
    protected function assignView($name = '', $value = '')
    {
        // 管理端特有的视图变量
        View::assign([
            'user_name'     => $this->getAdminName(),
            'user_realname' => $this->getAdminRealName(),
            'role_id'       => $this->getRoleId(),
            'is_super_admin'=> $this->isSuperAdmin(),
        ]);

        parent::assignView($name, $value);

        return $this;
    }
}
