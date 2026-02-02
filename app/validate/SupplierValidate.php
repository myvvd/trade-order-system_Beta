<?php

namespace app\validate;

use think\Validate;

/**
 * 供应商验证器
 */
class SupplierValidate extends Validate
{
    // 验证规则
    protected $rule = [
        'id'               => 'require|integer',
        'name'             => 'require|length:1,200',
        'contact'           => 'chsAlphaNum|length:1,50',
        'phone'             => 'mobile|number',
        'email'             => 'email',
        'login_account'      => 'require|length:3,50|alphaNum|checkAccountUnique',
        'login_password'     => 'require|length:6,50|checkPasswordStrength',
        'address'           => 'max:500',
        'status'            => 'in:0,1',
    ];

    // 验证消息
    protected $message = [
        'id.require'              => '供应商ID不能为空',
        'id.integer'             => '供应商ID格式错误',
        'name.require'            => '供应商名称不能为空',
        'name.length'             => '供应商名称长度为1-200个字符',
        'contact.chsAlphaNum'    => '联系人只能为中文、字母和数字',
        'contact.length'          => '联系人长度为1-50个字符',
        'phone.mobile'            => '手机号格式错误',
        'phone.number'            => '手机号只能为数字',
        'email.email'             => '邮箱格式错误',
        'login_account.require'   => '登录账号不能为空',
        'login_account.length'    => '登录账号长度为3-50个字符',
        'login_account.alphaNum' => '登录账号只能为字母和数字',
        'login_password.require'  => '密码不能为空',
        'login_password.length'   => '密码长度为6-50个字符',
        'address.max'            => '地址长度不能超过500个字符',
        'status.in'              => '状态值错误',
    ];

    // 验证场景
    protected $scene = [
        'login'  => ['login_account', 'login_password'],
        'create' => ['name', 'contact', 'phone', 'email', 'address', 'login_account', 'login_password'],
        'update' => ['id', 'name', 'contact', 'phone', 'email', 'address', 'login_account', 'status'],
        'edit_password' => ['id', 'login_password'],
    ];

    /**
     * 自定义验证 - 检查登录账号是否已存在
     * @param mixed $value
     * @param mixed $rule
     * @param array $data
     * @param string $field
     * @return bool|string
     */
    public function checkAccountUnique($value, $rule, $data = [], $field = '')
    {
        $id = $data['id'] ?? 0;
        $count = \app\model\Supplier::where('login_account', $value)
            ->where('id', '<>', $id)
            ->where('delete_time', null)
            ->count();

        if ($count > 0) {
            return '登录账号已存在';
        }

        return true;
    }

    /**
     * 自定义验证 - 密码强度（至少包含字母和数字）
     */
    public function checkPasswordStrength($value, $rule, $data = [], $field = '')
    {
        // 如果为空（更新场景可能允许为空），视为通过（其他场景有 require 约束）
        if ($value === '' || $value === null) return true;

        // 至少包含一个字母和一个数字
        if (!preg_match('/[A-Za-z]/', $value) || !preg_match('/\d/', $value)) {
            return '密码必须包含字母和数字';
        }

        return true;
    }

    /**
     * 自定义验证 - 检查旧密码是否正确
     * @param mixed $value
     * @param mixed $rule
     * @param array $data
     * @param string $field
     * @return bool|string
     */
    public function checkOldPassword($value, $rule, $data = [], $field = '')
    {
        $id = $data['id'] ?? 0;
        $supplier = \app\model\Supplier::find($id);

        if (!$supplier) {
            return '供应商不存在';
        }

        if (!$supplier->checkPassword($value)) {
            return '旧密码错误';
        }

        return true;
    }
}
