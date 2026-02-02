<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\Customer as CustomerModel;
use app\validate\CustomerValidate;
use app\service\CustomerService;
use think\facade\View;
use think\Response;

/**
 * 客户管理控制器
 */
class Customer extends Base
{
    /**
     * 客户列表
     * @return string
     */
    public function list()
    {
        $keyword = input('keyword', '');
        $country = input('country', '');
        $level = input('level', '');
        $status = input('status', '');
        $page = input('page/d', 1);
        $pageSize = input('page_size/d', 15);

        $query = CustomerModel::where('delete_time', null);

        if ($keyword) {
            $query->whereLike('company_name|contact_name', '%' . $keyword . '%');
        }

        if ($country) {
            $query->where('country', $country);
        }

        if ($level !== '') {
            $query->where('level', $level);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $customers = $query->order('id', 'desc')->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);

        // 附加订单计数到每个客户
        $ids = [];
        foreach ($customers as $c) {
            $ids[] = $c->id;
        }
        if (!empty($ids)) {
            $orderCounts = \think\facade\Db::name('order')
                ->whereIn('customer_id', $ids)
                ->field(['customer_id', 'COUNT(*) as cnt'])
                ->group('customer_id')
                ->select()
                ->toArray();

            $countMap = [];
            foreach ($orderCounts as $oc) {
                $countMap[$oc['customer_id']] = (int)$oc['cnt'];
            }

            foreach ($customers as $c) {
                $c->order_count = $countMap[$c->id] ?? 0;
            }
        }

        return View::fetch('admin/customer/list', [
            'customers'   => $customers,
            'keyword'     => $keyword,
            'country'     => $country,
            'level'       => $level,
            'status'      => $status,
            'title'       => '客户管理',
            'breadcrumb'  => '客户管理',
        ]);
    }

    /**
     * 创建表单
     * @return string
     */
    public function create()
    {
        return $this->showForm();
    }

    /**
     * 编辑表单
     * @param int $id
     * @return string|\think\response\Redirect
     */
    public function edit($id)
    {
        return $this->showForm($id);
    }

    protected function showForm($id = null)
    {
        $customer = null;
        if ($id) {
            $customer = CustomerModel::find($id);
            if (!$customer) {
                return redirect('admin/customer/list')->with('error', '客户不存在');
            }
        }

        // 简单国家列表，可按需扩展
        $countries = [
            'China', 'United States', 'United Kingdom', 'Germany', 'France', 'Other'
        ];

        return View::fetch('admin/customer/form', [
            'customer'    => $customer,
            'countries'   => $countries,
            'title'       => $id ? '编辑客户' : '新增客户',
            'breadcrumb'  => $id ? '编辑客户' : '新增客户',
        ]);
    }

    /**
     * 保存
     * @return Response
     */
    public function save(): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $params = $this->post();

        // 验证
        try {
            validate(CustomerValidate::class)->scene('create')->check($params);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        // 唯一性检查
        $existing = CustomerModel::getByCompanyName($params['company_name'] ?? '');
        if ($existing) {
            return $this->error('公司名称已存在');
        }

        try {
            $data = [
                'company_name' => $params['company_name'] ?? '',
                'country'      => $params['country'] ?? '',
                'contact_name' => $params['contact_name'] ?? '',
                'phone'        => $params['phone'] ?? '',
                'email'        => $params['email'] ?? '',
                'address'      => $params['address'] ?? '',
                'level'        => $params['level'] ?? 1,
                'remark'       => $params['remark'] ?? '',
                'status'       => $params['status'] ?? 1,
            ];

            $customer = CustomerModel::create($data);
            return $this->success(['id' => $customer->id], '保存成功');
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    /**
     * 更新
     * @param int $id
     * @return Response
     */
    public function update($id): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $params = $this->post();

        try {
            validate(CustomerValidate::class)->scene('update')->check(array_merge(['id' => $id], $params));
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $customer = CustomerModel::find($id);
        if (!$customer) {
            return $this->error('客户不存在');
        }

        // 检查公司名称唯一
        $other = CustomerModel::where('company_name', $params['company_name'] ?? $customer->company_name)
            ->where('id', '<>', $id)
            ->where('delete_time', null)
            ->find();
        if ($other) {
            return $this->error('公司名称已存在');
        }

        try {
            $data = [
                'company_name' => $params['company_name'] ?? $customer->company_name,
                'country'      => $params['country'] ?? $customer->country,
                'contact_name' => $params['contact_name'] ?? $customer->contact_name,
                'phone'        => $params['phone'] ?? $customer->phone,
                'email'        => $params['email'] ?? $customer->email,
                'address'      => $params['address'] ?? $customer->address,
                'level'        => $params['level'] ?? $customer->level,
                'remark'       => $params['remark'] ?? $customer->remark,
                'status'       => $params['status'] ?? $customer->status,
            ];

            $customer->save($data);
            return $this->success(null, '更新成功');
        } catch (\Exception $e) {
            return $this->error('更新失败：' . $e->getMessage());
        }
    }

    /**
     * 删除
     * @param int $id
     * @return Response
     */
    public function delete($id): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $customer = CustomerModel::find($id);
        if (!$customer) {
            return $this->error('客户不存在');
        }

        try {
            $customer->softDelete();
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败：' . $e->getMessage());
        }
    }

    /**
     * 详情（含历史订单统计）
     * @param int $id
     * @return string|\think\response\Redirect
     */
    public function detail($id)
    {
        $customer = CustomerModel::find($id);
        if (!$customer) {
            return redirect('admin/customer/list')->with('error', '客户不存在');
        }

        $orders = $customer->getOrders();
        $stats = $customer->getOrderStats();

        return View::fetch('admin/customer/detail', [
            'customer' => $customer,
            'orders'   => $orders,
            'stats'    => $stats,
            'title'    => '客户详情',
            'breadcrumb'=> '客户详情',
        ]);
    }

    /**
     * 导出 Excel（CSV 格式以兼容 Excel）
     * @return void
     */
    public function export()
    {
        $params = $this->get();
        $result = CustomerService::exportXlsx($params);

        // 如果返回的是 Spreadsheet 对象，输出 xlsx
        if (is_object($result) && class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet') && $result instanceof \PhpOffice\PhpSpreadsheet\Spreadsheet) {
            $filename = 'customers_' . date('Ymd_His') . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($result);
            $writer->save('php://output');
            exit;
        }

        // 回退为 CSV 文本
        $csv = is_string($result) ? $result : CustomerService::exportCsv($params);
        $filename = 'customers_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        echo $csv;
        exit;
    }
}
