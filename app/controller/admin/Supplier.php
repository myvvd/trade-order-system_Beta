<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\Supplier as SupplierModel;
use app\validate\SupplierValidate;
use think\facade\View;
use think\facade\Db;
use think\Response;

/**
 * 供应商管理控制器
 */
class Supplier extends Base
{
    public function list()
    {
        $keyword = input('keyword', '');
        $categoryId = input('category_id/d', 0);
        $status = input('status', '');
        $page = input('page/d', 1);
        $pageSize = input('page_size/d', 15);

        $query = SupplierModel::where('delete_time', null);

        if ($keyword) {
            $query->whereLike('name|contact|phone|login_account', '%' . $keyword . '%');
        }

        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $suppliers = $query->order('id', 'desc')->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);

        // 计算接单数及订单状态统计
        $ids = [];
        foreach ($suppliers as $s) $ids[] = $s->id;
        if (!empty($ids)) {
            // 总派单数
            $counts = Db::name('order_dispatch')
                ->whereIn('supplier_id', $ids)
                ->field(['supplier_id', 'COUNT(*) as cnt'])
                ->group('supplier_id')
                ->select()
                ->toArray();
            $map = [];
            foreach ($counts as $c) $map[$c['supplier_id']] = (int)$c['cnt'];
            
            // 已完成订单数（status >= 100）
            $completedCounts = Db::name('order_dispatch')
                ->alias('od')
                ->join('order o', 'od.order_id = o.id')
                ->whereIn('od.supplier_id', $ids)
                ->where('o.status', '>=', 100)
                ->field(['od.supplier_id', 'COUNT(*) as cnt'])
                ->group('od.supplier_id')
                ->select()
                ->toArray();
            $completedMap = [];
            foreach ($completedCounts as $c) $completedMap[$c['supplier_id']] = (int)$c['cnt'];
            
            // 未完成订单数（status < 100）
            $pendingCounts = Db::name('order_dispatch')
                ->alias('od')
                ->join('order o', 'od.order_id = o.id')
                ->whereIn('od.supplier_id', $ids)
                ->where('o.status', '<', 100)
                ->field(['od.supplier_id', 'COUNT(*) as cnt'])
                ->group('od.supplier_id')
                ->select()
                ->toArray();
            $pendingMap = [];
            foreach ($pendingCounts as $c) $pendingMap[$c['supplier_id']] = (int)$c['cnt'];
            
            foreach ($suppliers as $s) {
                $s->dispatch_count = $map[$s->id] ?? 0;
                $s->completed_count = $completedMap[$s->id] ?? 0;
                $s->pending_count = $pendingMap[$s->id] ?? 0;
            }
        }

        // 在控制器中处理好数据，传给视图
        $suppliersList = [];
        foreach ($suppliers->items() as $item) {
            $suppliersList[] = $item->toArray();
        }

        return View::fetch('admin/supplier/list', [
            'suppliers'              => $suppliers,
            'suppliers_list_json'    => json_encode($suppliersList, JSON_UNESCAPED_UNICODE),
            'supplier_total'         => $suppliers->total(),
            'supplier_current_page'  => $suppliers->currentPage(),
            'keyword'                => $keyword,
            'categoryId'             => $categoryId,
            'status'                 => $status,
            'title'                  => '供应商管理',
            'breadcrumb'             => '供应商管理',
            'current_page'           => 'supplier',
        ]);
    }

    public function create()
    {
        return $this->showForm();
    }

    public function edit($id)
    {
        return $this->showForm($id);
    }

    protected function showForm($id = null)
    {
        $supplier = null;
        if ($id) {
            $supplier = SupplierModel::find($id);
            if (!$supplier) return redirect('admin/supplier/list')->with('error', '供应商不存在');
        }

        $categories = Db::name('supplier_category')->select()->toArray();

        return View::fetch('admin/supplier/form', [
            'supplier' => $supplier,
            'categories'=> $categories,
            'title'    => $id ? '编辑供应商' : '新增供应商',
        ]);
    }

    public function save(): Response
    {
        if (!$this->isPost()) return $this->error('请求方式错误');

        $params = $this->post();
        try {
            validate(SupplierValidate::class)->scene('create')->check($params);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        // 唯一性检查由验证器完成
        try {
            $data = [
                'name' => $params['name'] ?? '',
                'category_id' => $params['category_id'] ?? 0,
                'contact' => $params['contact'] ?? '',
                'phone' => $params['phone'] ?? '',
                'email' => $params['email'] ?? '',
                'address' => $params['address'] ?? '',
                'login_account' => $params['login_account'] ?? '',
                'login_password' => $params['login_password'] ?? '',
                'status' => $params['status'] ?? 1,
                'remark' => $params['remark'] ?? '',
            ];

            $supplier = SupplierModel::create($data);
            return $this->success(['id' => $supplier->id], '保存成功');
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    public function update($id): Response
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $params = $this->post();

        try {
            validate(SupplierValidate::class)->scene('update')->check(array_merge(['id' => $id], $params));
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $supplier = SupplierModel::find($id);
        if (!$supplier) return $this->error('供应商不存在');

        // 检查登录账号唯一
        if (!empty($params['login_account'])) {
            $other = SupplierModel::where('login_account', $params['login_account'])
                ->where('id', '<>', $id)
                ->where('delete_time', null)
                ->find();
            if ($other) return $this->error('登录账号已存在');
        }

        try {
            $data = [
                'name' => $params['name'] ?? $supplier->name,
                'category_id' => $params['category_id'] ?? $supplier->category_id,
                'contact' => $params['contact'] ?? $supplier->contact,
                'phone' => $params['phone'] ?? $supplier->phone,
                'email' => $params['email'] ?? $supplier->email,
                'address' => $params['address'] ?? $supplier->address,
                'login_account' => $params['login_account'] ?? $supplier->login_account,
                'status' => $params['status'] ?? $supplier->status,
                'remark' => $params['remark'] ?? $supplier->remark,
            ];

            $supplier->save($data);
            return $this->success(null, '更新成功');
        } catch (\Exception $e) {
            return $this->error('更新失败：' . $e->getMessage());
        }
    }

    public function delete($id): Response
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $supplier = SupplierModel::find($id);
        if (!$supplier) return $this->error('供应商不存在');

        try {
            $supplier->softDelete();
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败：' . $e->getMessage());
        }
    }

    public function detail($id)
    {
        $supplier = SupplierModel::find($id);
        if (!$supplier) return redirect('admin/supplier/list')->with('error', '供应商不存在');

        $dispatches = Db::name('order_dispatch')->where('supplier_id', $id)->order('id', 'desc')->select()->toArray();
        $total = count($dispatches);
        $accepted = Db::name('order_dispatch')->where('supplier_id', $id)->where('status', '>=', 30)->count();

        return View::fetch('admin/supplier/detail', [
            'supplier' => $supplier,
            'dispatches'=> $dispatches,
            'stats'    => ['total' => $total, 'accepted' => (int)$accepted],
            'title'    => '供应商详情',
        ]);
    }

    /**
     * 供应商下拉搜索（JSON）
     */
    public function search()
    {
        $q = input('q', '');
        $limit = input('limit/d', 20);

        $query = SupplierModel::where('delete_time', null)->where('status', 1);
        if ($q) {
            $query->whereLike('name|contact|phone', '%' . $q . '%');
        }

        $rows = $query->order('id', 'desc')->limit($limit)->select()->toArray();
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'contact' => $r['contact'] ?? '',
                'phone' => $r['phone'] ?? '',
            ];
        }

        return $this->success($data);
    }

    public function resetPassword($id): Response
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $password = $this->post('login_password', '');
        try {
            validate(SupplierValidate::class)->scene('edit_password')->check(['id' => $id, 'login_password' => $password]);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $supplier = SupplierModel::find($id);
        if (!$supplier) return $this->error('供应商不存在');

        try {
            $supplier->save(['login_password' => $password]);
            return $this->success(null, '重置成功');
        } catch (\Exception $e) {
            return $this->error('重置失败：' . $e->getMessage());
        }
    }

    public function toggleStatus($id): Response
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $supplier = SupplierModel::find($id);
        if (!$supplier) return $this->error('供应商不存在');

        $new = $supplier->status == 1 ? 0 : 1;
        try {
            $supplier->save(['status' => $new]);
            return $this->success(['status' => $new], $new == 1 ? '已启用' : '已禁用');
        } catch (\Exception $e) {
            return $this->error('操作失败：' . $e->getMessage());
        }
    }
}
