<?php

namespace app\validate;

use think\Validate;

/**
 * 采购方验证器
 */
class CustomerValidate extends Validate
{
    protected $rule = [
        'id'           => 'integer',
        'company_name' => 'max:200',
        'country'      => 'max:100',
        'contact_name' => 'require|max:50',
        'phone'        => 'max:20',
        'wechat'       => 'max:50',
        'email'        => 'email',
        'address'      => 'max:500',
        'level'        => 'in:1,2',
        'status'       => 'in:0,1',
    ];

    protected $message = [
        'company_name.max'     => '公司名称长度不能超过200个字符',
        'contact_name.require' => '联系人不能为空',
        'country.max'          => '国家长度不能超过100个字符',
        'wechat.max'           => '微信号长度不能超过50个字符',
        'contact_name.max'     => '联系人长度不能超过50个字符',
        'phone.max'            => '电话长度不能超过20个字符',
        'email.email'          => '邮箱格式错误',
        'address.max'          => '地址长度不能超过500个字符',
        'level.in'             => '采购方等级错误',
        'status.in'            => '状态值错误',
    ];

    protected $scene = [
        'create' => ['company_name', 'country', 'contact_name', 'phone', 'wechat', 'email', 'address', 'level', 'status'],
        'update' => ['id', 'company_name', 'country', 'contact_name', 'phone', 'wechat', 'email', 'address', 'level', 'status'],
    ];

    /**
     * 自定义唯一性检查（可在需要时使用）
     */
    public function checkCompanyUnique($value, $rule, $data = [], $field = '')
    {
        $id = $data['id'] ?? 0;
        $count = \app\model\Customer::where('company_name', $value)
            ->where('id', '<>', $id)
            ->where('delete_time', null)
            ->count();

        if ($count > 0) {
            return '公司名称已存在';
        }

        return true;
    }
}
