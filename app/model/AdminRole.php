<?php

namespace app\model;

use think\facade\Db;

/**
 * 管理员角色模型
 */
class AdminRole extends \think\Model
{
    // 表名
    protected $name = 'admin_role';

    // 主键
    protected $pk = 'id';

    // 自动写入时间戳
    protected $autoWriteTimestamp = true;

    // 字段类型转换
    protected $type = [
        'status' => 'integer',
    ];

    // JSON 字段
    protected $json = ['permissions'];

    /**
     * 检查角色是否拥有指定权限
     * @param string $permission 权限标识，如 'order.list'
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->getPermissionsList();

        if (empty($permissions)) {
            return false;
        }

        // 超级管理员拥有所有权限
        if ($this->id == 1) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    /**
     * 检查角色是否拥有模块权限（任意一个动作）
     * @param string $module 模块名，如 'order'
     * @return bool
     */
    public function hasModulePermission(string $module): bool
    {
        $permissions = $this->getPermissionsList();

        if (empty($permissions)) {
            return false;
        }

        // 超级管理员拥有所有权限
        if ($this->id == 1) {
            return true;
        }

        // 检查是否有该模块的任意权限
        foreach ($permissions as $perm) {
            if (str_starts_with($perm, $module . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取权限列表数组
     * @return array
     */
    public function getPermissionsList(): array
    {
        if (is_array($this->permissions)) {
            return $this->permissions;
        }

        $decoded = json_decode($this->permissions, true);
        return $decoded ?? [];
    }

    /**
     * 设置权限
     * @param array $permissions 权限数组
     * @return bool
     */
    public function setPermissions(array $permissions): bool
    {
        return $this->save([
            'permissions' => json_encode($permissions, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 添加权限
     * @param string|array $permissions 权限或权限数组
     * @return bool
     */
    public function addPermission($permissions): bool
    {
        $current = $this->getPermissionsList();

        if (is_string($permissions)) {
            $permissions = [$permissions];
        }

        $new = array_unique(array_merge($current, $permissions));

        return $this->setPermissions($new);
    }

    /**
     * 移除权限
     * @param string|array $permissions 权限或权限数组
     * @return bool
     */
    public function removePermission($permissions): bool
    {
        $current = $this->getPermissionsList();

        if (is_string($permissions)) {
            $permissions = [$permissions];
        }

        $new = array_values(array_diff($current, $permissions));

        return $this->setPermissions($new);
    }

    /**
     * 获取该角色的用户数量
     * @return int
     */
    public function getUserCount(): int
    {
        return Db::name('admin_user')
            ->where('role_id', $this->id)
            ->where('delete_time', null)
            ->count();
    }

    /**
     * 检查是否可以删除
     * @return array [可删除, 原因]
     */
    public function canDelete(): array
    {
        // 超级管理员不能删除
        if ($this->id == 1) {
            return [false, '超级管理员角色不能删除'];
        }

        // 有用户的角色不能删除
        $userCount = $this->getUserCount();
        if ($userCount > 0) {
            return [false, '该角色下还有 ' . $userCount . ' 个用户，无法删除'];
        }

        return [true, ''];
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
     * 搜索器 - 按关键词搜索
     * @param \think\query $query
     * @param mixed $value
     * @return void
     */
    public function searchKeywordAttr($query, $value): void
    {
        if ($value) {
            $query->whereLike('name|description', '%' . $value . '%');
        }
    }
}
