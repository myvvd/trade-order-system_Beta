<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\AdminRole;
use app\validate\AdminRoleValidate;
use think\facade\View;
use think\Response;

/**
 * 角色管理控制器
 */
class Role extends Base
{
    /**
     * 角色列表
     * @return string
     */
    public function list()
    {
        $keyword = input('keyword', '');
        $status = input('status', '');

        $query = AdminRole::order('id', 'desc');

        if ($keyword) {
            $query->where('name', 'like', '%' . $keyword . '%');
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $roles = $query->select();

        // 为每个角色添加用户数量
        $rolesData = [];
        foreach ($roles as $role) {
            $data = $role->toArray();
            $data['user_count'] = $role->getUserCount();
            $rolesData[] = $data;
        }

        return View::fetch('admin/role/list', [
            'roles'        => $roles,
            'roles_json'   => json_encode($rolesData, JSON_UNESCAPED_UNICODE),
            'keyword'      => $keyword,
            'status'       => $status,
            'title'        => '角色管理',
            'breadcrumb'   => '角色权限',
            'current_page' => 'role',
        ]);
    }

    /**
     * 显示创建/编辑表单
     * @param int|null $id
     * @return string
     */
    public function form($id = null)
    {
        $role = null;
        $permissions = [];
        $allPermissions = config('permissions');

        if ($id) {
            $role = AdminRole::find($id);
            if (!$role) {
                return redirect('admin/role/list')->with('error', '角色不存在');
            }
            $permissions = $role->getPermissionsList();
        }

        return View::fetch('admin/role/form', [
            'role'          => $role,
            'permissions'    => $permissions,
            'allPermissions' => $allPermissions,
            'title'         => $id ? '编辑角色' : '创建角色',
            'breadcrumb'    => $id ? '编辑角色' : '创建角色',
        ]);
    }

    /**
     * 创建角色
     * @return Response
     */
    public function create(): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $params = $this->post();
        $permissions = $params['permissions'] ?? [];
        $name = $params['name'] ?? '';
        $description = $params['description'] ?? '';

        if (!$name) {
            return $this->error('角色名称不能为空');
        }

        try {
            $role = AdminRole::create([
                'name'        => $name,
                'description' => $description,
                'status'      => 1,
                'permissions'  => json_encode($permissions, JSON_UNESCAPED_UNICODE),
            ]);

            return $this->success(['id' => $role->id], '创建成功');
        } catch (\Exception $e) {
            return $this->error('创建失败：' . $e->getMessage());
        }
    }

    /**
     * 编辑角色
     * @return Response
     */
    public function edit(): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = $this->post('id/d', 0);
        if (!$id) {
            return $this->error('角色ID不能为空');
        }

        $role = AdminRole::find($id);
        if (!$role) {
            return $this->error('角色不存在');
        }

        // 超级管理员不能编辑
        if ($role->id == 1) {
            return $this->error('超级管理员角色不能编辑');
        }

        $params = $this->post();
        $permissions = $params['permissions'] ?? [];
        $name = $params['name'] ?? '';
        $description = $params['description'] ?? '';
        $status = $params['status'] ?? 1;

        if (!$name) {
            return $this->error('角色名称不能为空');
        }

        try {
            $role->save([
                'name'        => $name,
                'description' => $description,
                'status'      => $status,
                'permissions'  => json_encode($permissions, JSON_UNESCAPED_UNICODE),
            ]);

            return $this->success(null, '保存成功');
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    /**
     * 删除角色
     * @return Response
     */
    public function delete(): Response
    {
        $id = $this->post('id/d', 0);
        if (!$id) {
            return $this->error('角色ID不能为空');
        }

        $role = AdminRole::find($id);
        if (!$role) {
            return $this->error('角色不存在');
        }

        // 检查是否可以删除
        [$canDelete, $errorMsg] = $role->canDelete();

        if (!$canDelete) {
            return $this->error($errorMsg);
        }

        try {
            $role->delete();
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败：' . $e->getMessage());
        }
    }

    /**
     * 获取所有可用权限列表
     * @return Response
     */
    public function permissions(): Response
    {
        $allPermissions = config('permissions');
        return $this->success($allPermissions);
    }

    /**
     * 切换角色状态
     * @return Response
     */
    public function toggleStatus(): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = $this->post('id/d', 0);
        if (!$id) {
            return $this->error('角色ID不能为空');
        }

        $role = AdminRole::find($id);
        if (!$role) {
            return $this->error('角色不存在');
        }

        // 超级管理员不能禁用
        if ($role->id == 1) {
            return $this->error('超级管理员角色不能禁用');
        }

        $newStatus = $role->status == 1 ? 0 : 1;

        try {
            $role->save(['status' => $newStatus]);
            return $this->success(['status' => $newStatus], $newStatus == 1 ? '已启用' : '已禁用');
        } catch (\Exception $e) {
            return $this->error('操作失败：' . $e->getMessage());
        }
    }
}
