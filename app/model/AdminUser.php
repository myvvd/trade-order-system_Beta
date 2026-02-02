<?php

namespace app\model;

use think\facade\Db;
use think\Model;

/**
 * 管理员用户模型
 */
class AdminUser extends Model
{
    // 表名
    protected $name = 'admin_user';

    // 主键
    protected $pk = 'id';

    // 自动写入时间戳
    protected $autoWriteTimestamp = true;

    // 隐藏字段（JSON输出时自动隐藏）
    protected $hidden = ['password', 'delete_time'];

    // 字段类型转换
    protected $type = [
        'role_id' => 'integer',
        'status'  => 'integer',
    ];

    /**
     * 密码修改器（自动加密）
     * @param mixed $value
     * @return string
     */
    public function setPasswordAttr($value): string
    {
        // 如果已经是 hash 格式，不再加密
        if (password_get_info($value)['algo']) {
            return $value;
        }
        // 使用 password_hash 加密
        return password_hash($value, PASSWORD_DEFAULT);
    }

    /**
     * 验证密码
     * @param string $password 明文密码
     * @return bool
     */
    public function checkPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    /**
     * 根据用户名获取用户
     * @param string $username
     * @return static|null
     */
    public static function getByUsername(string $username): ?AdminUser
    {
        return self::where('username', $username)
            ->where('delete_time', null)
            ->find();
    }

    /**
     * 检查账号状态
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status == 1;
    }

    /**
     * 记录登录信息
     * @param string $ip
     * @return bool
     */
    public function recordLogin(string $ip = ''): bool
    {
        return $this->save([
            'last_login_time' => date('Y-m-d H:i:s'),
            'last_login_ip'   => $ip,
        ]);
    }

    /**
     * 获取用户角色信息
     * @return array|null
     */
    public function getRole(): ?array
    {
        if (!$this->role_id) {
            return null;
        }

        return Db::name('admin_role')->where('id', $this->role_id)->find();
    }

    /**
     * 获取用户权限列表
     * @return array
     */
    public function getPermissions(): array
    {
        $role = $this->getRole();

        if (!$role || empty($role['permissions'])) {
            return [];
        }

        return json_decode($role['permissions'], true) ?? [];
    }

    /**
     * 检查是否为超级管理员
     * @return bool
     */
    public function isSuperAdmin(): bool
    {
        return $this->role_id == 1;
    }

    /**
     * 软删除
     * @return bool
     */
    public function softDelete(): bool
    {
        return $this->save(['delete_time' => date('Y-m-d H:i:s')]);
    }

    /**
     * 搜索器 - 按状态搜索
     * @param \think\query $query
     * @param mixed $value
     * @return void
     */
    public function searchStatusAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', $value);
        }
    }

    /**
     * 搜索器 - 按角色搜索
     * @param \think\query $query
     * @param mixed $value
     * @return void
     */
    public function searchRoleIdAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('role_id', $value);
        }
    }

    /**
     * 搜索器 - 按关键词搜索
     * @param \think\query $query
     * @param mixed $value
     * @return void
     */
    public function searchKeywordAttr($query, $value): void
    {
        if ($value) {
            $query->whereLike('username|real_name|phone|email', '%' . $value . '%');
        }
    }
}
