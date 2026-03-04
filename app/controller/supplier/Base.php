<?php

namespace app\controller\supplier;

use app\controller\Base as ControllerBase;
use think\facade\View;

/**
 * 供应商端基础控制器
 */
abstract class Base extends ControllerBase
{
    /**
     * 供应商端 Session key 前缀
     * @var string
     */
    protected $sessionPrefix = 'supplier';

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
     * 获取供应商用户ID
     * @return int|null
     */
    protected function getSupplierUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * 获取供应商ID
     * @return int|null
     */
    protected function getSupplierId(): ?int
    {
        return $this->userInfo['supplier_id'] ?? null;
    }

    /**
     * 获取供应商用户名
     * @return string
     */
    protected function getSupplierUserName(): string
    {
        return $this->userInfo['username'] ?? '';
    }

    /**
     * 获取供应商名称
     * @return string
     */
    protected function getSupplierName(): string
    {
        return $this->userInfo['supplier_name'] ?? '';
    }

    /**
     * 获取供应商联系人
     * @return string
     */
    protected function getContactPerson(): string
    {
        return $this->userInfo['contact_person'] ?? '';
    }

    /**
     * 获取供应商联系电话
     * @return string
     */
    protected function getContactPhone(): string
    {
        return $this->userInfo['contact_phone'] ?? '';
    }

    /**
     * 检查供应商账号状态
     * @return bool
     */
    protected function isSupplierActive(): bool
    {
        return ($this->userInfo['status'] ?? 0) === 1;
    }

    /**
     * 赋值供应商端视图变量
     */
    protected function assignView($name = '', $value = '')
    {
        // 供应商端特有的视图变量
        View::assign([
            'supplier_name'    => $this->getSupplierName(),
            'supplier_id'      => $this->getSupplierId(),
            'contact_person'   => $this->getContactPerson(),
            'contact_phone'    => $this->getContactPhone(),
        ]);

        parent::assignView($name, $value);

        return $this;
    }
}
