<?php

namespace app\validate;

use think\Validate;

/**
 * 管理员验证器
 */
class AdminUserValidate extends Validate
{
    // 验证规则
    protected $rule = [
        'id'          => 'require|integer',
        'username'     => 'require|length:3,50|alphaNum',
        'password'     => 'require|length:6,50',
        'real_name'    => 'chsAlphaNum|length:1,50',
        'phone'        => 'mobile|number',
        'email'        => 'email',
        'role_id'      => 'integer',
        'status'       => 'in:0,1',
    ];

    // 验证消息
    protected $message = [
        'id.require'           => '用户ID不能为空',
        'id.integer'          => '用户ID格式错误',
        'username.require'      => '用户名不能为空',
        'username.length'       => '用户名长度为3-50个字符',
        'username.alphaNum'    => '用户名只能为字母和数字',
        'password.require'     => '密码不能为空',
        'password.length'      => '密码长度为6-50个字符',
        'real_name.chsAlphaNum' => '真实姓名只能为中文、字母和数字',
        'real_name.length'     => '真实姓名长度为1-50个字符',
        'phone.mobile'         => '手机号格式错误',
        'phone.number'         => '手机号只能为数字',
        'email.email'          => '邮箱格式错误',
        'role_id.integer'      => '角色ID格式错误',
        'status.in'            => '状态值错误',
    ];

    // 验证场景
    protected $scene = [
        'login'  => ['username', 'password'],
        'create' => ['username', 'password', 'real_name', 'phone', 'email', 'role_id'],
        'update' => ['id', 'real_name', 'phone', 'email', 'role_id', 'status'],
        'edit_password' => ['id', 'password'],
    ];

    /**
     * 自定义验证 - 检查用户名是否已存在
     * @param mixed $value
     * @param mixed $rule
     * @param array $data
     * @param string $field
     * @return bool|string
     */
    public function checkUsernameUnique($value, $rule, $data = [], $field = '')
    {
        $id = $data['id'] ?? 0;
        $count = \app\model\AdminUser::where('username', $value)
            ->where('id', '<>', $id)
            ->where('delete_time', null)
            ->count();

        if ($count > 0) {
            return '用户名已存在';
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
        $user = \app\model\AdminUser::find($id);

        if (!$user) {
            return '用户不存在';
        }

        if (!$user->checkPassword($value)) {
            return '旧密码错误';
        }

        return true;
    }
}
