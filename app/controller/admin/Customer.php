<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\Customer as CustomerModel;
use app\validate\CustomerValidate;
use app\service\CustomerService;
use think\facade\View;
use think\Response;

/**
 * 采购方管理控制器
 */
class Customer extends Base
{
    /**
     * 采购方列表
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

        // 附加订单计数到每个采购方
        $ids = [];
        foreach ($customers as $c) {
            $ids[] = $c->id;
        }
        if (!empty($ids)) {
            // 获取总订单数
            $orderCounts = \think\facade\Db::name('order')
                ->whereIn('customer_id', $ids)
                ->field(['customer_id', 'COUNT(*) as cnt'])
                ->group('customer_id')
                ->select()
                ->toArray();

            // 获取未完成订单数 (假设 status 不等于 'completed' 或 'finished')
            $pendingCounts = \think\facade\Db::name('order')
                ->whereIn('customer_id', $ids)
                ->whereNotIn('status', ['completed', 'finished', 'delivered', 'cancelled'])
                ->field(['customer_id', 'COUNT(*) as cnt'])
                ->group('customer_id')
                ->select()
                ->toArray();

            $countMap = [];
            $pendingMap = [];
            
            foreach ($orderCounts as $oc) {
                $countMap[$oc['customer_id']] = (int)$oc['cnt'];
            }
            
            foreach ($pendingCounts as $pc) {
                $pendingMap[$pc['customer_id']] = (int)$pc['cnt'];
            }

            foreach ($customers as $c) {
                $c->order_count = $countMap[$c->id] ?? 0;
                $c->pending_order_count = $pendingMap[$c->id] ?? 0;
            }
        }

        return View::fetch('admin/customer/list', [
            'customers'   => $customers,
            'keyword'     => $keyword,
            'country'     => $country,
            'level'       => $level,
            'status'      => $status,
            'title'       => '采购方管理',
            'breadcrumb'  => '采购方管理',
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
    public function edit($id = null)
    {
        $id = $id ?: input('id/d', 0);
        if (!$id) {
            return redirect('/admin/customer/list')->with('error', '参数错误');
        }
        return $this->showForm($id);
    }

    protected function showForm($id = null)
    {
        $customer = null;
        if ($id) {
            $customer = CustomerModel::find($id);
            if (!$customer) {
                return redirect('admin/customer/list')->with('error', '采购方不存在');
            }
        }

        // 简单国家列表，可按需扩展
        $countries = [
            'China', 'United States', 'United Kingdom', 'Germany', 'France', 'Japan', 'South Korea', 'Other'
        ];

        $customerData = $customer ? $customer->toArray() : [];

        return View::fetch('admin/customer/form', [
            'customer'      => $customerData,
            'countries'     => $countries,
            'title'         => $id ? '编辑采购方' : '新增采购方',
            'breadcrumb'    => $id ? '编辑采购方' : '新增采购方',
            'current_page'  => 'customer',
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
    public function update($id = null): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $params = $this->post();
        $id = $id ?: ($params['id'] ?? input('id/d', 0));
        if (!$id) {
            return $this->error('参数错误');
        }

        try {
            validate(CustomerValidate::class)->scene('update')->check(array_merge(['id' => $id], $params));
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $customer = CustomerModel::find($id);
        if (!$customer) {
            return $this->error('采购方不存在');
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
    public function delete($id = null): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = $id ?: input('id/d', 0);
        if (!$id) {
            return $this->error('参数错误');
        }

        $customer = CustomerModel::find($id);
        if (!$customer) {
            return $this->error('采购方不存在');
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
    public function detail($id = null)
    {
        $id = $id ?: input('id/d', 0);
        if (!$id) {
            return redirect('/admin/customer/list')->with('error', '参数错误');
        }
        
        $customer = CustomerModel::find($id);
        if (!$customer) {
            return redirect('/admin/customer/list')->with('error', '采购方不存在');
        }

        $orders = $customer->getOrders();
        $stats = $customer->getOrderStats();

        return View::fetch('admin/customer/detail', [
            'customer' => $customer,
            'orders'   => $orders,
            'stats'    => $stats,
            'title'    => '采购方详情',
            'breadcrumb'=> '采购方详情',
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

    /**
     * 获取采购方的未完成订单列表
     * @return Response
     */
    public function pendingOrders(): Response
    {
        $customerId = input('id/d', 0);
        if (!$customerId) {
            return $this->error('参数错误');
        }

        $customer = CustomerModel::find($customerId);
        if (!$customer) {
            return $this->error('采购方不存在');
        }

        try {
            // 获取未完成的订单（排除已完成、已取消、已交付的状态）
            $orders = \think\facade\Db::name('order')
                ->where('customer_id', $customerId)
                ->whereNotIn('status', ['completed', 'finished', 'delivered', 'cancelled'])
                ->field([
                    'id', 'order_no', 'status', 'total_amount', 
                    'delivery_date', 'create_time', 'update_time'
                ])
                ->order('id', 'desc')
                ->select()
                ->toArray();

            // 格式化数据
            foreach ($orders as &$order) {
                $order['create_time'] = $order['create_time'] ? date('Y-m-d H:i', strtotime($order['create_time'])) : '-';
                $order['delivery_date'] = $order['delivery_date'] ? date('Y-m-d', strtotime($order['delivery_date'])) : '-';
                $order['total_amount'] = number_format((float)$order['total_amount'], 2);
            }

            return $this->success($orders, '获取成功');
        } catch (\Exception $e) {
            return $this->error('获取失败：' . $e->getMessage());
        }
    }
}
