<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\AdminUser as AdminUserModel;
use app\validate\AdminUserValidate;
use think\facade\View;
use think\facade\Db;
use think\Response;

/**
 * 员工管理控制器
 */
class Employee extends Base
{
    protected function ensureSuper()
    {
        if (!$this->isSuperAdmin()) {
            abort(403, '无权操作');
        }
    }

    public function list()
    {
        $this->ensureSuper();

        $keyword = input('keyword', '');
        $roleId = input('role_id/d', 0);
        $status = input('status', '');
        $page = input('page/d', 1);
        $pageSize = input('page_size/d', 15);

        $query = AdminUserModel::where('delete_time', null);
        if ($keyword) {
            $query->whereLike('username|real_name|phone|email', '%' . $keyword . '%');
        }
        if ($roleId > 0) $query->where('role_id', $roleId);
        if ($status !== '') $query->where('status', $status);

        $users = $query->order('id', 'desc')->paginate(['list_rows' => $pageSize, 'page' => $page]);

        // 获取角色列表
        $roles = Db::name('admin_role')->where('status', 1)->select()->toArray();
        $rolesMap = [];
        foreach ($roles as $role) {
            $rolesMap[$role['id']] = $role;
        }

        // 在控制器中处理好数据，手动关联角色
        $userList = [];
        foreach ($users->items() as $item) {
            $data = $item->toArray();
            $data['role'] = isset($rolesMap[$data['role_id']]) ? $rolesMap[$data['role_id']] : null;
            $userList[] = $data;
        }

        return View::fetch('admin/employee/list', [
            'users'             => $users,
            'users_list_json'   => json_encode($userList, JSON_UNESCAPED_UNICODE),
            'roles_json'        => json_encode($roles, JSON_UNESCAPED_UNICODE),
            'user_total'        => $users->total(),
            'user_current_page' => $users->currentPage(),
            'keyword'           => $keyword,
            'roleId'            => $roleId,
            'status'            => $status,
            'title'             => '员工管理',
            'breadcrumb'        => '员工管理',
            'current_page'      => 'employee',
        ]);
    }

    public function create()
    {
        $this->ensureSuper();
        return $this->showForm();
    }

    public function edit($id)
    {
        $this->ensureSuper();
        return $this->showForm($id);
    }

    protected function showForm($id = null)
    {
        $user = null;
        if ($id) {
            $user = AdminUserModel::find($id);
            if (!$user) return redirect('admin/employee/list')->with('error', '用户不存在');
        }

        $roles = Db::name('admin_role')->where('status',1)->select()->toArray();

        return View::fetch('admin/employee/form', [
            'user' => $user,
            'roles' => $roles,
            'title' => $id ? '编辑员工' : '新增员工',
        ]);
    }

    public function save(): Response
    {
        $this->ensureSuper();
        if (!$this->isPost()) return $this->error('请求方式错误');
        $params = $this->post();

        try {
            validate(AdminUserValidate::class)->scene('create')->check($params);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        // 唯一性检查
        $exists = AdminUserModel::where('username', $params['username'])->where('delete_time', null)->find();
        if ($exists) return $this->error('用户名已存在');

        try {
            $data = [
                'username' => $params['username'],
                'password' => $params['password'],
                'real_name' => $params['real_name'] ?? '',
                'phone' => $params['phone'] ?? '',
                'email' => $params['email'] ?? '',
                'role_id' => $params['role_id'] ?? 0,
                'status' => $params['status'] ?? 1,
            ];
            $user = AdminUserModel::create($data);
            return $this->success(['id' => $user->id], '保存成功');
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    public function update($id): Response
    {
        $this->ensureSuper();
        if (!$this->isPost()) return $this->error('请求方式错误');
        $params = $this->post();

        try {
            validate(AdminUserValidate::class)->scene('update')->check(array_merge(['id' => $id], $params));
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $user = AdminUserModel::find($id);
        if (!$user) return $this->error('用户不存在');

        // 检查用户名唯一（若改动）
        if (!empty($params['username']) && $params['username'] !== $user->username) {
            $other = AdminUserModel::where('username', $params['username'])->where('id','<>',$id)->where('delete_time', null)->find();
            if ($other) return $this->error('用户名已存在');
        }

        try {
            $data = [
                'username' => $params['username'] ?? $user->username,
                'real_name' => $params['real_name'] ?? $user->real_name,
                'phone' => $params['phone'] ?? $user->phone,
                'email' => $params['email'] ?? $user->email,
                'role_id' => $params['role_id'] ?? $user->role_id,
                'status' => $params['status'] ?? $user->status,
            ];
            $user->save($data);
            return $this->success(null, '更新成功');
        } catch (\Exception $e) {
            return $this->error('更新失败：' . $e->getMessage());
        }
    }

    public function delete($id): Response
    {
        $this->ensureSuper();
        if (!$this->isPost()) return $this->error('请求方式错误');
        $current = $this->getAdminId();
        if ($current == $id) return $this->error('不能删除自己');

        $user = AdminUserModel::find($id);
        if (!$user) return $this->error('用户不存在');

        try {
            $user->softDelete();
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败：' . $e->getMessage());
        }
    }

    public function resetPassword($id): Response
    {
        $this->ensureSuper();
        if (!$this->isPost()) return $this->error('请求方式错误');
        $password = $this->post('password', '');

        try {
            validate(AdminUserValidate::class)->scene('edit_password')->check(['id' => $id, 'password' => $password]);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $user = AdminUserModel::find($id);
        if (!$user) return $this->error('用户不存在');

        try {
            $user->save(['password' => $password]);
            return $this->success(null, '重置成功');
        } catch (\Exception $e) {
            return $this->error('重置失败：' . $e->getMessage());
        }
    }

    public function toggleStatus($id): Response
    {
        $this->ensureSuper();
        if (!$this->isPost()) return $this->error('请求方式错误');
        $current = $this->getAdminId();
        if ($current == $id) return $this->error('不能修改自己的状态');

        $user = AdminUserModel::find($id);
        if (!$user) return $this->error('用户不存在');

        $new = $user->status == 1 ? 0 : 1;
        try {
            $user->save(['status' => $new]);
            return $this->success(['status' => $new], $new == 1 ? '已启用' : '已禁用');
        } catch (\Exception $e) {
            return $this->error('操作失败：' . $e->getMessage());
        }
    }
}
