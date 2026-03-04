<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\Order as OrderModel;
use app\model\Customer as CustomerModel;
use app\model\Supplier as SupplierModel;
use app\model\OrderDispatch as OrderDispatchModel;
use app\model\TradingCompany as TradingCompanyModel;
use app\model\Salesman as SalesmanModel;
use app\service\OrderService;
use app\service\DispatchService;
use app\service\MessageService;
use think\facade\View;
use think\facade\Db;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class Order extends Base
{
    public function list()
    {
        $keyword = input('keyword', '');
        $status = input('status', '');
        $date_from = input('date_from', '');
        $date_to = input('date_to', '');
        $page = input('page/d', 1);
        $pageSize = input('page_size/d', 15);

        $query = OrderModel::where('id', '>', 0);

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('order_no|remark', '%' . $keyword . '%')
                  ->whereOr('customer_id', 'in', function ($sq) use ($keyword) {
                      $sq->name('customer')->field('id')
                         ->whereLike('company_name|contact_name', '%' . $keyword . '%');
                  });
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($date_from) {
            $query->where('create_time', '>=', $date_from . ' 00:00:00');
        }
        if ($date_to) {
            $query->where('create_time', '<=', $date_to . ' 23:59:59');
        }

        $orders = $query->with(['customer', 'items', 'dispatches', 'dispatches.supplier', 'creator'])->order('id', 'desc')->paginate([
            'list_rows' => $pageSize,
            'page' => $page,
        ]);

        $tabs = [
            '' => '全部',
            OrderModel::STATUS_PENDING => '待派单',
            OrderModel::STATUS_WAIT_CONFIRM => '待确认',
            OrderModel::STATUS_IN_PROGRESS => '制作中',
            OrderModel::STATUS_WAIT_RECEIVE => '待收货',
            OrderModel::STATUS_RECEIVED => '已入库',
            OrderModel::STATUS_SHIPPED => '已发货',
            OrderModel::STATUS_COMPLETED => '已完成',
        ];

        return View::fetch('admin/order/list', [
            'orders' => $orders,
            'keyword' => $keyword,
            'status' => $status,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'tabs' => $tabs,
            'title' => '订单管理',
            'isSuper' => $this->isSuperAdmin(),
            'canManageOrders' => $this->canManageOrders(),
        ]);
    }

    public function getNextOrderNo()
    {
        $today = date('ymd'); // YYMMDD，如 260303
        $prefix = $today . '-';
        // 查询今天已存在的同前缀订单号，取最大序号
        $existing = OrderModel::where('order_no', 'like', $prefix . '%')
            ->column('order_no');
        $maxSeq = 0;
        foreach ($existing as $no) {
            $parts = explode('-', $no);
            if (count($parts) === 2 && strlen($parts[1]) === 3 && is_numeric($parts[1])) {
                $seq = intval($parts[1]);
                if ($seq > $maxSeq) $maxSeq = $seq;
            }
        }
        $nextNo = $prefix . str_pad($maxSeq + 1, 3, '0', STR_PAD_LEFT);
        return $this->success(['order_no' => $nextNo]);
    }

    public function create()
    {
        $customers = CustomerModel::where('delete_time', null)->limit(50)->select();
        $tradingCompanies = OrderModel::whereNotNull('trading_company')->where('trading_company', '<>', '')
            ->column('trading_company');
        $tradingCompanies = array_values(array_unique($tradingCompanies));
        $tcList = TradingCompanyModel::where('delete_time', null)->where('status', 1)
            ->field('id,name')->order('id', 'asc')->select()->toArray();
        $salesmenList = SalesmanModel::where('delete_time', null)->where('status', 1)
            ->field('id,name,phone,trading_company_id')->order('id', 'asc')->select()->toArray();
        return View::fetch('admin/order/form', [
            'order' => null,
            'customers' => $customers,
            'trading_companies' => $tradingCompanies,
            'tc_list' => $tcList,
            'salesmen_list' => $salesmenList,
            'title' => '新增订单',
            'isSuper' => $this->isSuperAdmin(),
            'admin_name' => $this->getAdminRealName() ?: $this->getAdminName(),
        ]);
    }

    public function save()
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $params = $this->post();

        try {
            validate(\app\validate\OrderValidate::class)->scene('create')->check($params);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        try {
            $service = new OrderService();
            $order = $service->createOrder($params, $this->getAdminId());
            return $this->success(['id' => $order->id], '保存成功');
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $order = OrderModel::with(['items', 'attachments'])->find($id);
        if (!$order) {
            return redirect('admin/order/list')->with('error', '订单不存在');
        }
        if ($order->status != OrderModel::STATUS_PENDING) {
            return redirect('admin/order/list')->with('error', '仅待派单订单可编辑');
        }

        $customers = CustomerModel::where('delete_time', null)->limit(50)->select();
        $tradingCompanies = OrderModel::whereNotNull('trading_company')->where('trading_company', '<>', '')
            ->column('trading_company');
        $tradingCompanies = array_values(array_unique($tradingCompanies));
        $tcList = TradingCompanyModel::where('delete_time', null)->where('status', 1)
            ->field('id,name')->order('id', 'asc')->select()->toArray();
        $salesmenList = SalesmanModel::where('delete_time', null)->where('status', 1)
            ->field('id,name,phone,trading_company_id')->order('id', 'asc')->select()->toArray();
        return View::fetch('admin/order/form', [
            'order' => $order->toArray(),
            'customers' => $customers,
            'trading_companies' => $tradingCompanies,
            'tc_list' => $tcList,
            'salesmen_list' => $salesmenList,
            'title' => '编辑订单',
            'isSuper' => $this->isSuperAdmin(),
            'admin_name' => $this->getAdminRealName() ?: $this->getAdminName(),
        ]);
    }

    public function update($id)
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $params = $this->post();

        try {
            validate(\app\validate\OrderValidate::class)->scene('update')->check(array_merge(['id' => $id], $params));
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $order = OrderModel::find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }
        if ($order->status != OrderModel::STATUS_PENDING) {
            return $this->error('仅待派单订单可编辑');
        }

        try {
            // 简单实现：删除原有明细后重建
            \think\facade\Db::transaction(function () use ($order, $params) {
                \think\facade\Db::name('order_item')->where('order_id', $order->id)->delete();
                $items = $params['items'] ?? [];
                foreach ($items as $it) {
                    $goodsName = $it['goods_name'] ?? '';
                    if (empty($goodsName) && !empty($it['goods_id'])) {
                        $goods = \app\model\Goods::find($it['goods_id']);
                        $goodsName = $goods ? $goods->name : '未知商品';
                    }

                    $skuName = $it['sku_name'] ?? '';
                    if (empty($skuName) && !empty($it['sku_id'])) {
                        $sku = \app\model\GoodsSku::find($it['sku_id']);
                        $skuName = $sku ? $sku->sku_name : '';
                    }

                    $quantity = $it['quantity'] ?? 0;
                    $unitPrice = $it['price'] ?? 0;
                    $amount = $quantity * $unitPrice;

                    \think\facade\Db::name('order_item')->insert([
                        'order_id'          => $order->id,
                        'customer_goods_no' => $it['customer_goods_no'] ?? null,
                        'pieces'            => $it['pieces'] ?? 1,
                        'per_piece_qty'     => $it['per_piece_qty'] ?? 1,
                        'goods_id'          => $it['goods_id'] ?? null,
                        'goods_name'        => $goodsName,
                        'sku_id'            => $it['sku_id'] ?? null,
                        'sku_name'          => $skuName,
                        'quantity'          => $quantity,
                        'unit_price'        => $unitPrice,
                        'amount'            => $amount,
                        'box_size'          => $it['box_size'] ?? null,
                        'photo_url'         => $it['photo_url'] ?? null,
                        'remark'            => $it['remark'] ?? '',
                        'packing_material'  => $it['packing_material'] ?? null,
                        'volume'            => isset($it['volume']) && $it['volume'] !== '' ? $it['volume'] : null,
                        'label'             => $it['label'] ?? null,
                    ]);
                }
                
                // 更新附件: 先删除旧的，再插入新的 (简单策略)
                \think\facade\Db::name('order_attachment')->where('order_id', $order->id)->delete();
                $attachments = $params['attachments'] ?? [];
                foreach ($attachments as $url) {
                    if(empty($url)) continue;
                    \think\facade\Db::name('order_attachment')->insert([
                        'order_id' => $order->id,
                        'file_path' => $url,
                        'file_name' => basename($url),
                        'file_type' => 'image',
                        'uploader_id' => $this->getAdminId(),
                        'create_time' => time(),
                        'update_time' => time(),
                    ]);
                }

                $order->total_amount    = $params['total_amount'] ?? $order->total_amount;
                $order->delivery_date   = $params['delivery_date'] ?? null;
                $order->order_date      = $params['order_date'] ?? null;
                $order->trading_company = $params['trading_company'] ?? null;
                $order->salesman        = $params['salesman'] ?? null;
                $order->contact_phone   = $params['contact_phone'] ?? null;
                $order->remark          = $params['remark'] ?? $order->remark;
                $order->save();

                // 发送订单修改站内信通知
                try {
                    (new MessageService())->notifyOrderUpdated($order, $this->getAdminId());
                } catch (\Throwable $e) {}
            });

            return $this->success(null, '更新成功');
        } catch (\Exception $e) {
            return $this->error('更新失败：' . $e->getMessage());
        }
    }

    public function detail($id)
    {
        $order = OrderModel::with([
            'items', 
            'dispatches.supplier', 
            'statusLogs.admin', 
            'attachments', 
            'customer', 
            'creator'
        ])->find($id);

        if (!$order) {
            return redirect((string)url('admin/order/index'))->with('error', '订单不存在');
        }

        return View::fetch('admin/order/detail', [
            'order' => $order,
            'title' => '订单详情',
        ]);
    }

    public function delete($id)
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        if (!$this->canManageOrders()) {
            return $this->error('暂无操作权限');
        }

        $order = OrderModel::find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        try {
            \think\facade\Db::transaction(function () use ($order) {
                \think\facade\Db::name('order_item')->where('order_id', $order->id)->delete();
                \think\facade\Db::name('order_dispatch_item')
                    ->whereIn('dispatch_id', function ($q) use ($order) {
                        $q->name('order_dispatch')->where('order_id', $order->id)->field('id');
                    })->delete();
                \think\facade\Db::name('order_dispatch')->where('order_id', $order->id)->delete();
                \think\facade\Db::name('order_attachment')->where('order_id', $order->id)->delete();
                \think\facade\Db::name('order_status_log')->where('order_id', $order->id)->delete();
                $order->delete();
            });
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败：' . $e->getMessage());
        }
    }

    public function cancel($id)
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        if (!$this->canManageOrders()) {
            return $this->error('暂无操作权限');
        }

        try {
            $service = new OrderService();
            $service->cancelOrder((int)$id, $this->getAdminId());
            return $this->success(null, '取消派单成功');
        } catch (\Exception $e) {
            return $this->error('操作失败：' . $e->getMessage());
        }
    }

    public function copy($id)
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        if (!$this->canManageOrders()) {
            return $this->error('暂无操作权限');
        }

        try {
            $service = new OrderService();
            $newOrder = $service->copyOrder((int)$id, $this->getAdminId());
            $order = OrderModel::with(['customer', 'items'])->find($newOrder->id);
            if (!$order) {
                throw new \RuntimeException('复制后的订单加载失败');
            }

            $payload = $order->toArray();
            $payload['status_text'] = OrderModel::getStatusText($order->status);
            $payload['customer_name'] = $order->customer ? $order->customer->company_name : '-';

            return $this->success(['order' => $payload], '复制成功');
        } catch (\Exception $e) {
            return $this->error('复制失败：' . $e->getMessage());
        }
    }

    /**
     * 导出订单数据
     */
    public function export()
    {
        $keyword = input('keyword', '');
        $status = input('status', '');
        $date_from = input('date_from', '');
        $date_to = input('date_to', '');

        $query = OrderModel::where('id', '>', 0);

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('order_no|remark', '%' . $keyword . '%')
                  ->whereOr('customer_id', 'in', function ($sq) use ($keyword) {
                      $sq->name('customer')->field('id')
                         ->whereLike('company_name|contact_name', '%' . $keyword . '%');
                  });
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($date_from) {
            $query->where('create_time', '>=', $date_from . ' 00:00:00');
        }
        if ($date_to) {
            $query->where('create_time', '<=', $date_to . ' 23:59:59');
        }

        // 不分页，获取所有数据
        // 为确保能够导出跟列表页一致的全部字段(包括产品、明细、供应商等)
        $cursor = $query->with(['customer', 'items', 'dispatches', 'dispatches.supplier'])->order('id', 'desc')->cursor();

        // 使用 PhpSpreadsheet 创建更丰富的 Excel 报表
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // 写入表头并增加样式
        $headers = [
            '序号', '订单号', '采购方', '交货日期', '采购方货号', '货品名称', '件数', '每件数量',
            '总数量', '单价', '总价', '外箱尺寸', '照片', '描述',
            '订单总金额', '供应商', '状态', '创建时间'
        ];

        foreach ($headers as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column . '1', $header);
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $headerStyle = $sheet->getStyle('A1:' . $lastColumn . '1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF2F2F2');

        // 设置关键列宽度
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(25);
        $sheet->getColumnDimension('E')->setWidth(28);
        $sheet->getColumnDimension('F')->setWidth(28);

        $row = 2;
        $serialNumber = 1;

        foreach ($cursor as $order) {
            $customerName = $order->customer ? $order->customer->company_name : '-';
            $statusText = OrderModel::getStatusText($order->status);

            // 组装物品信息
            $goodsNoList = [];
            $goodsNameList = [];
            $piecesList = [];
            $perQtyList = [];
            $qtyList = [];
            $priceList = [];
            $totalPriceList = [];
            $boxSizeList = [];
            $photoList = [];
            $remarkList = [];

            if (!empty($order->items)) {
                foreach ($order->items as $it) {
                    $goodsNoList[] = $it['customer_goods_no'] ?: '-';
                    $goodsNameList[] = $it['goods_name'] ?: '-';
                    $piecesList[] = $it['pieces'] ?? '-';
                    $perQtyList[] = $it['per_piece_qty'] ?? '-';

                    // 总数量 (考虑前端可能有的不同字段命名规则)
                    $totalQty = $it['quantity'];
                    if (isset($it['pieces']) && isset($it['per_piece_qty']) && $it['pieces'] > 0) {
                        $totalQty = $it['pieces'] * $it['per_piece_qty'];
                    }
                    $qtyList[] = $totalQty;

                    // 单价和总价
                    $unitPrice = $it['price'] ?? $it['unit_price'];
                    $priceList[] = '¥' . $unitPrice;

                    // 计算总价
                    $totalPriceList[] = '¥' . $this->decimalMul($unitPrice, $totalQty);

                    $boxSizeList[] = $it['box_size'] ?: '-';
                    $photoList[] = $it['photo_url'] ? (request()->domain() . $it['photo_url']) : '-';
                    $remarkList[] = $it['remark'] ?: '-';
                }
            }

            // 组装供应商信息
            $supplierList = [];
            if (!empty($order->dispatches)) {
                foreach ($order->dispatches as $disp) {
                    if ($disp->supplier) {
                        $supplierList[] = $disp->supplier->name;
                    }
                }
            }

            $sheet->setCellValue('A' . $row, $serialNumber);
            $sheet->setCellValueExplicit('B' . $row, $order->order_no, DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $row, $customerName);
            $sheet->setCellValue('D' . $row, $order->delivery_date ?: '-');
            $sheet->setCellValue('E' . $row, implode("\n", $goodsNoList));
            $sheet->setCellValue('F' . $row, implode("\n", $goodsNameList));
            $sheet->setCellValue('G' . $row, implode("\n", $piecesList));
            $sheet->setCellValue('H' . $row, implode("\n", $perQtyList));
            $sheet->setCellValue('I' . $row, implode("\n", $qtyList));
            $sheet->setCellValue('J' . $row, implode("\n", $priceList));
            $sheet->setCellValue('K' . $row, implode("\n", $totalPriceList));
            $sheet->setCellValue('L' . $row, implode("\n", $boxSizeList));
            $sheet->setCellValue('M' . $row, implode("\n", $photoList));
            $sheet->setCellValue('N' . $row, implode("\n", $remarkList));
            $sheet->setCellValue('O' . $row, $order->total_amount);
            $sheet->setCellValue('P' . $row, implode("\n", $supplierList) ?: '-');
            $sheet->setCellValue('Q' . $row, $statusText);
            $sheet->setCellValue('R' . $row, $order->create_time);

            $sheet->getStyle('E' . $row . ':N' . $row)->getAlignment()->setWrapText(true);
            $sheet->getStyle('P' . $row)->getAlignment()->setWrapText(true);

            $row++;
            $serialNumber++;
        }

        $filenameBase = 'orders_' . date('YmdHis');
        $supportsZip = extension_loaded('zlib') && function_exists('deflate_init');

        if ($supportsZip) {
            $filename = $filenameBase . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $writer = new Xlsx($spreadsheet);
        } else {
            $filename = $filenameBase . '.xls';
            header('Content-Type: application/vnd.ms-excel');
            $writer = new Xls($spreadsheet);
        }

        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit;
    }

    /**
     * API: 获取派单信息（订单子项+已派数量+供应商列表）
     */
    public function dispatchInfo($id)
    {
        if (!$this->canManageOrders()) {
            return $this->error('暂无操作权限');
        }

        $order = OrderModel::with(['items'])->find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        // 计算每个 order_item 已被派出的数量（排除已拒绝的派单）
        $rows = Db::name('order_dispatch_item')
            ->alias('di')
            ->join('order_dispatch d', 'di.dispatch_id = d.id')
            ->where('d.order_id', $id)
            ->where('d.status', '<>', OrderDispatchModel::STATUS_REJECTED)
            ->group('di.order_item_id')
            ->field('di.order_item_id, SUM(di.quantity) as dispatched')
            ->select();

        $dispatchedMap = [];
        foreach ($rows as $r) {
            $dispatchedMap[$r['order_item_id']] = (int)($r['dispatched'] ?? 0);
        }

        // 组装子订单明细
        $items = [];
        foreach ($order->items as $item) {
            $dispatched = $dispatchedMap[$item->id] ?? 0;
            $remaining = max(0, (int)$item->quantity - $dispatched);
            $items[] = [
                'id'          => $item->id,
                'goods_id'    => $item->goods_id,
                'goods_name'  => $item->goods_name,
                'sku_id'      => $item->sku_id,
                'sku_name'    => $item->sku_name,
                'quantity'    => (int)$item->quantity,
                'dispatched'  => $dispatched,
                'remaining'   => $remaining,
                'unit_price'  => $item->unit_price,
                'remark'      => $item->remark,
            ];
        }

        // 获取正常状态的供应商
        $suppliers = SupplierModel::where('status', 1)
            ->where('delete_time', null)
            ->field('id, name, contact, phone')
            ->select()
            ->toArray();

        return $this->success([
            'order_id'  => $order->id,
            'order_no'  => $order->order_no,
            'items'     => $items,
            'suppliers' => $suppliers,
        ]);
    }

    /**
     * API: 批量派单保存
     * 请求格式: { dispatches: [{ supplier_id: 1, items: [{order_item_id:1, quantity:5}, ...] }, ...] }
     * 若派单后仍有未派明细，则将派单部分拆分为新子订单，原订单保留未派部分
     */
    public function dispatchSave($id)
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        if (!$this->canManageOrders()) {
            return $this->error('暂无操作权限');
        }

        $order = OrderModel::with(['items'])->find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }
        if ($order->status != OrderModel::STATUS_PENDING && $order->status != OrderModel::STATUS_WAIT_CONFIRM) {
            return $this->error('当前订单状态不允许派单');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $dispatches = $input['dispatches'] ?? [];
        if (empty($dispatches)) {
            return $this->error('请添加派单信息');
        }

        // 收集本次各 order_item 的总派单量
        $totalQtyMap = [];
        foreach ($dispatches as $d) {
            foreach ($d['items'] ?? [] as $it) {
                $oiId = (int)($it['order_item_id'] ?? 0);
                $qty  = (int)($it['quantity'] ?? 0);
                if ($oiId > 0 && $qty > 0) {
                    $totalQtyMap[$oiId] = ($totalQtyMap[$oiId] ?? 0) + $qty;
                }
            }
        }

        // 查询已派数量（排除被拒绝的派单）
        $rows = Db::name('order_dispatch_item')
            ->alias('di')
            ->join('order_dispatch d', 'di.dispatch_id = d.id')
            ->where('d.order_id', $id)
            ->where('d.status', '<>', OrderDispatchModel::STATUS_REJECTED)
            ->group('di.order_item_id')
            ->field('di.order_item_id, SUM(di.quantity) as dispatched')
            ->select();
        $dispatchedMap = [];
        foreach ($rows as $r) {
            $dispatchedMap[$r['order_item_id']] = (int)($r['dispatched'] ?? 0);
        }

        // 订单明细对象 Map
        $orderItemsMap = [];
        foreach ($order->items as $oi) {
            $orderItemsMap[$oi->id] = $oi;
        }

        // 校验派单数量不超出可派数
        foreach ($totalQtyMap as $oiId => $totalQty) {
            $ordered    = isset($orderItemsMap[$oiId]) ? (int)$orderItemsMap[$oiId]->quantity : 0;
            $dispatched = $dispatchedMap[$oiId] ?? 0;
            $remaining  = $ordered - $dispatched;
            if ($totalQty > $remaining) {
                return $this->error("订单明细(ID:{$oiId})派单数量({$totalQty})超出可派数量({$remaining})");
            }
        }

        // 计算本次派单后各明细剩余量
        $remainingMap = [];
        foreach ($orderItemsMap as $oiId => $oi) {
            $alreadyDispatched = $dispatchedMap[$oiId] ?? 0;
            $nowDispatching    = $totalQtyMap[$oiId] ?? 0;
            $remaining = (int)$oi->quantity - $alreadyDispatched - $nowDispatching;
            if ($remaining > 0) {
                $remainingMap[$oiId] = $remaining;
            }
        }
        $hasRemainder = !empty($remainingMap);

        try {
            $service = new DispatchService();
            $adminId = $this->getAdminId();
            $createdIds = [];

            Db::transaction(function () use (
                $service, $id, $dispatches, $adminId, &$createdIds,
                $order, $orderItemsMap, $hasRemainder, $remainingMap
            ) {
                $usedSuffixes = [];

                foreach ($dispatches as $d) {
                    $supplierId = (int)($d['supplier_id'] ?? 0);

                    // 过滤有效派单项
                    $validItems = [];
                    foreach ($d['items'] ?? [] as $it) {
                        $qty = (int)($it['quantity'] ?? 0);
                        if ($qty > 0) {
                            $validItems[] = [
                                'order_item_id' => (int)($it['order_item_id'] ?? 0),
                                'quantity'      => $qty,
                            ];
                        }
                    }

                    if (!$supplierId || empty($validItems)) {
                        continue;
                    }

                    if ($hasRemainder) {
                        // 拆单：为本次派单的货品创建新子订单
                        $suffix = 1;
                        do {
                            $newOrderNo = $order->order_no . '-' . $suffix;
                            $suffix++;
                        } while (in_array($newOrderNo, $usedSuffixes) || OrderModel::where('order_no', $newOrderNo)->count() > 0);
                        $usedSuffixes[] = $newOrderNo;

                        $newOrder = new OrderModel();
                        $newOrder->save([
                            'order_no'     => $newOrderNo,
                            'customer_id'  => $order->customer_id,
                            'status'       => OrderModel::STATUS_PENDING,
                            'total_amount' => '0.00',
                            'currency'     => $order->currency,
                            'delivery_date'=> $order->delivery_date,
                            'remark'       => $order->remark,
                            'creator_id'   => $order->creator_id,
                        ]);

                        // 为子订单创建新的 order_item
                        $totalAmt = '0.00';
                        $newItemsForDispatch = [];
                        foreach ($validItems as $it) {
                            $origItem = $orderItemsMap[$it['order_item_id']] ?? null;
                            if (!$origItem) continue;

                            $itemAmt = $this->decimalMul($origItem->unit_price, $it['quantity']);
                            $newItem = new \app\model\OrderItem();
                            $newItem->save([
                                'order_id'   => $newOrder->id,
                                'goods_id'   => $origItem->goods_id,
                                'goods_name' => $origItem->goods_name,
                                'sku_id'     => $origItem->sku_id,
                                'sku_name'   => $origItem->sku_name,
                                'quantity'   => $it['quantity'],
                                'unit_price' => $origItem->unit_price,
                                'amount'     => $itemAmt,
                                'remark'     => $origItem->remark,
                            ]);
                            $totalAmt = $this->decimalAdd($totalAmt, $itemAmt);
                            $newItemsForDispatch[] = [
                                'order_item_id' => $newItem->id,
                                'quantity'      => $it['quantity'],
                            ];
                        }

                        $newOrder->total_amount = $totalAmt;
                        $newOrder->save();

                        // 对子订单创建派单
                        $dispatch = $service->createDispatch($newOrder->id, $supplierId, $newItemsForDispatch, $adminId);
                        $createdIds[] = $dispatch->id;
                    } else {
                        // 全部派出，直接对原订单创建派单
                        $dispatch = $service->createDispatch($id, $supplierId, $validItems, $adminId);
                        $createdIds[] = $dispatch->id;
                    }
                }

                if (empty($createdIds)) {
                    throw new \RuntimeException('没有有效的派单数据');
                }

                // 更新原订单：删除已全派明细，减少部分派明细数量
                if ($hasRemainder) {
                    $newTotalAmt = '0.00';
                    foreach ($orderItemsMap as $oiId => $oi) {
                        if (isset($remainingMap[$oiId])) {
                            $newQty = $remainingMap[$oiId];
                            $newAmt = $this->decimalMul($oi->unit_price, $newQty);
                            $oi->quantity = $newQty;
                            $oi->amount   = $newAmt;
                            $oi->save();
                            $newTotalAmt = $this->decimalAdd($newTotalAmt, $newAmt);
                        } else {
                            // 已全部派出，删除该明细
                            $oi->delete();
                        }
                    }
                    $order->total_amount = $newTotalAmt;
                    $order->status = OrderModel::STATUS_PENDING;
                    $order->save();
                }
            });

            $msg = $hasRemainder
                ? '派单成功，已拆分为子订单，原订单保留未派明细'
                : '派单成功，共创建' . count($createdIds) . '条派单';
            return $this->success(['dispatch_ids' => $createdIds], $msg);
        } catch (\Exception $e) {
            return $this->error('派单失败：' . $e->getMessage());
        }
    }

    private function decimalMul($left, $right, $scale = 2)
    {
        if (function_exists('bcmul')) {
            return bcmul((string)$left, (string)$right, $scale);
        }
        return number_format((float)$left * (float)$right, $scale, '.', '');
    }

    private function decimalAdd($left, $right, $scale = 2)
    {
        if (function_exists('bcadd')) {
            return bcadd((string)$left, (string)$right, $scale);
        }
        return number_format((float)$left + (float)$right, $scale, '.', '');
    }

    /**
     * 导出单张订单 Excel（纯代码生成，不依赖模板）
     * 访问地址: /admin/order/exportSingleOrder/{id}
     *
     * 布局说明:
     *   第1行  = 公司中文名（合并A-I），微软雅黑28号，行高32磅
     *   第2行  = 公司英文名（合并A-I），微软雅黑18号，行高21磅
     *   第3行  = 地址+电话（合并A-I），微软雅黑12号，行高50磅，自动换行
     *   第4~7行= 订单表头信息，微软雅黑12号，行高28磅
     *     A4=采购方  D4=订单号  A5=贸易公司  D5=业务员
     *     A6=联系电话 D6=订货日期 A7=交货日期 D7=送货地址
     *   第8行起= 货品明细，行高187磅（容纳图片）
     *     A=货号 B=照片 C=产品描述 D=件数 E=装箱数 F=单价 G=总金额 H=体积 I=总体积
     */
    public function exportSingleOrder($id)
    {
        $order = OrderModel::with(['items', 'customer', 'creator'])->find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        $hasZip = class_exists('\ZipArchive');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('订单');

        // ========== 通用字体名 ==========
        $fontName = '微软雅黑';

        // ========== 列宽设置（近似磅值） ==========
        $sheet->getColumnDimension('A')->setWidth(18);  // 货号
        $sheet->getColumnDimension('B')->setWidth(42);  // 照片
        $sheet->getColumnDimension('C')->setWidth(42);  // 产品描述
        $sheet->getColumnDimension('D')->setWidth(10);  // 件数
        $sheet->getColumnDimension('E')->setWidth(10);  // 装箱数
        $sheet->getColumnDimension('F')->setWidth(10);  // 单价
        $sheet->getColumnDimension('G')->setWidth(12);  // 总金额
        $sheet->getColumnDimension('H')->setWidth(10);  // 体积
        $sheet->getColumnDimension('I')->setWidth(12);  // 总体积

        // ==================== 第1行：公司中文名 ====================
        $sheet->mergeCells('A1:I1');
        $sheet->setCellValue('A1', '青 岛 米 薇 儿 假 睫 毛 工 艺 品 有 限 公 司');
        $sheet->getRowDimension(1)->setRowHeight(40);
        $sheet->getStyle('A1')->getFont()->setName($fontName)->setSize(28)->setBold(true);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        // ==================== 第2行：公司英文名 ====================
        $sheet->mergeCells('A2:I2');
        $sheet->setCellValue('A2', 'Qingdao Snow Shirley Eyelashes Factory');
        $sheet->getRowDimension(2)->setRowHeight(24);
        $sheet->getStyle('A2')->getFont()->setName($fontName)->setSize(18);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        // ==================== 第3行：地址+电话 ====================
        $sheet->mergeCells('A3:I3');
        $addressText = "店面地址：义乌国际商贸城3区51号门3楼4街26702\nNo.26702 SHOP 4th Street 3rd Floor Area 3/H  Gate 51 Yiwu International Trade City\n电话/TEL：6657070980";
        $sheet->setCellValue('A3', $addressText);
        $sheet->getRowDimension(3)->setRowHeight(50);
        $sheet->getStyle('A3')->getFont()->setName($fontName)->setSize(12);
        $sheet->getStyle('A3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);

        // ==================== 第4~7行：订单表头信息 ====================
        $customerName = $order->customer ? $order->customer->company_name : '';
        $creatorName  = $order->creator  ? ($order->creator->real_name ?: $order->creator->username) : '';

        // 合并单元格：每行左半 A-C，右半 D-I（标签+值合在一起）
        for ($r = 4; $r <= 7; $r++) {
            $sheet->mergeCells('A' . $r . ':C' . $r);
            $sheet->mergeCells('D' . $r . ':I' . $r);
            $sheet->getRowDimension($r)->setRowHeight(28);
            $sheet->getStyle('A' . $r)->getFont()->setName($fontName)->setSize(12);
            $sheet->getStyle('D' . $r)->getFont()->setName($fontName)->setSize(12);
            $sheet->getStyle('A' . $r)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('D' . $r)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }

        // 写入表头数据
        $sheet->setCellValue('A4', '采购方(Buyer)：' . $customerName);
        $sheet->setCellValue('D4', '客户唛头(Mark)：' );
         $sheet->setCellValue('A5', '送货地址(Delivery Add)：');
        $sheet->setCellValueExplicit('D5', '订单号(Order No.)：' . ($order->order_no ?: ''), DataType::TYPE_STRING);
        // $sheet->setCellValue('D5', '业务员：' . ($order->salesman ?: ''));       
        $sheet->setCellValue('A6', '联系人电话(TEL)：' . ($order->contact_phone ?: ''));
        $sheet->setCellValue('D6', '交易方式(Payment)：' . ($order->payment_method ?: ''));
        $sheet->setCellValue('A7', '订货日期(Order date)：' . ($order->order_date ?: ''));
        $sheet->setCellValue('D7', '交货日期(Delivery date)：' . ($order->delivery_date ?: ''));
      

        // 第4~7行边框
        $sheet->getStyle('A4:I7')->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('999999');

        // ==================== 第8行：货品明细标题行 ====================
        $headerCols = [
            'A8' => '货号', 'B8' => '款式图片', 'C8' => '产品描述',
            'D8' => '件数', 'E8' => '装箱数', 'F8' => '单价',
            'G8' => '总金额', 'H8' => '体积', 'I8' => '总体积',
        ];
        foreach ($headerCols as $cell => $label) {
            $sheet->setCellValue($cell, $label);
        }
        $sheet->getRowDimension(8)->setRowHeight(28);
        $sheet->getStyle('A8:I8')->getFont()->setName($fontName)->setSize(12)->setBold(true);
        $sheet->getStyle('A8:I8')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A8:I8')->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('999999');
        $sheet->getStyle('A8:I8')->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F7FA');

        // ==================== 第9行起：货品明细数据 ====================
        $itemStartRow = 9;
        $row = $itemStartRow;
        $totalPieces = 0;
        $totalVolume = 0.0;
        $totalAmount = 0.0;

        $items = $order->items ?? [];

        foreach ($items as $idx => $item) {
            $pieces  = (int)($item['pieces'] ?? 0);
            $perQty  = (int)($item['per_piece_qty'] ?? 0);
            $qty     = $pieces * $perQty;
            $price   = (float)($item['unit_price'] ?? $item['price'] ?? 0);
            $rowAmt  = $qty * $price;
            $volume  = (float)($item['volume'] ?? 0);
            $rowVol  = ($pieces > 0 && $volume > 0) ? $pieces * $volume : 0.0;

            $totalPieces += $pieces;
            $totalVolume += $rowVol;
            $totalAmount += $rowAmt;

            // 行高 187 磅（容纳图片）
            $sheet->getRowDimension($row)->setRowHeight(160);

            // A = 货号
            $sheet->setCellValueExplicit('A' . $row, $item['customer_goods_no'] ?? '', DataType::TYPE_STRING);

            // B = 照片（嵌入图片，Drawing 不依赖 ZipArchive）
            $photoInserted = false;
            $photoUrl = $item['photo_url'] ?? '';
            if ($photoUrl) {
                $photoPath = app()->getRootPath() . 'public' . $photoUrl;
                if (file_exists($photoPath)) {
                    try {
                        $drawing = new Drawing();
                        $drawing->setName('Photo_' . ($idx + 1));
                        $drawing->setDescription($item['goods_name'] ?? '');
                        $drawing->setPath($photoPath);
                        $drawing->setCoordinates('B' . $row);
                        $drawing->setWidth(100);
                        $drawing->setHeight(130);
                        $drawing->setOffsetX(5);
                        $drawing->setOffsetY(5);
                        $drawing->setWorksheet($sheet);
                        $photoInserted = true;
                    } catch (\Throwable $e) {
                        // 图片插入失败，回退为文字
                    }
                }
            }
            if (!$photoInserted) {
                $sheet->setCellValue('B' . $row, $item['goods_name'] ?? '');
            }

            // C = 产品描述
            $desc = trim(($item['goods_name'] ?? '') . ($item['remark'] ? "\n" . $item['remark'] : ''));
            $sheet->setCellValue('C' . $row, $desc);
            $sheet->getStyle('C' . $row)->getAlignment()->setWrapText(true);

            // D~I = 件数、装箱数、单价、总金额、体积、总体积
            $sheet->setCellValue('D' . $row, $pieces ?: '');
            $sheet->setCellValue('E' . $row, $perQty ?: '');
            $sheet->setCellValue('F' . $row, $price ?: '');
            $sheet->setCellValue('G' . $row, $rowAmt > 0 ? round($rowAmt, 2) : '');
            $sheet->setCellValue('H' . $row, $volume ?: '');
            $sheet->setCellValue('I' . $row, $rowVol > 0 ? round($rowVol, 4) : '');

            // 每行字体
            $sheet->getStyle('A' . $row . ':I' . $row)->getFont()->setName($fontName)->setSize(12);
            $sheet->getStyle('A' . $row . ':I' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

            $row++;
        }

        // 货品区边框
        $lastItemRow = $row - 1;
        if ($lastItemRow >= $itemStartRow) {
            $sheet->getStyle('A' . $itemStartRow . ':I' . $lastItemRow)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('999999');
        }

        // ==================== 汇总行 ====================
        $sumRow = $row;
        $sheet->mergeCells('A' . $sumRow . ':C' . $sumRow);
        $sheet->setCellValue('A' . $sumRow, '总件数：' . $totalPieces . '    总金额：¥' . number_format($totalAmount, 2) . '    总体积：' . round($totalVolume, 4) . ' m³');
        $sheet->getRowDimension($sumRow)->setRowHeight(24);
        $sheet->getStyle('A' . $sumRow . ':I' . $sumRow)->getFont()->setName($fontName)->setSize(12)->setBold(true);
        $sheet->getStyle('A' . $sumRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // ==================== 录单信息 ====================
        $noteRow = $sumRow + 1;
        $sheet->setCellValue('A' . $noteRow, '录单人：' . $creatorName);
        $sheet->setCellValue('D' . $noteRow, '录单时间：' . ($order->create_time ?? ''));
        $sheet->getStyle('A' . $noteRow . ':I' . $noteRow)->getFont()->setName($fontName)->setSize(11);

        // ==================== 输出下载 ====================
        $filename = '订单_' . $order->order_no . '_' . date('YmdHis');
        if ($hasZip) {
            $filename .= '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $writer = new Xlsx($spreadsheet);
        } else {
            $filename .= '.xls';
            header('Content-Type: application/vnd.ms-excel');
            $writer = new Xls($spreadsheet);
        }
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit;
    }

    /**
     * 导出单张订单 PDF（服务端 TCPDF 生成）
     * 访问地址: /admin/order/exportSingleOrderPdf/{id}
     */
    public function exportSingleOrderPdf($id)
    {
        $order = OrderModel::with(['items', 'customer', 'creator'])->find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        $customerName = $order->customer ? $order->customer->company_name : '-';
        $creatorName  = $order->creator  ? ($order->creator->real_name ?: $order->creator->username) : '-';
        $items = $order->items ?? [];

        // 计算汇总
        $totalPieces = 0;
        $totalAmount  = 0.0;
        $totalVolume  = 0.0;

        $itemsHtml = '';
        foreach ($items as $idx => $item) {
            $pieces  = (int)($item['pieces'] ?? 0);
            $perQty  = (int)($item['per_piece_qty'] ?? 0);
            $qty     = $pieces * $perQty;
            $price   = (float)($item['unit_price'] ?? $item['price'] ?? 0);
            $rowAmt  = $qty * $price;
            $volume  = (float)($item['volume'] ?? 0);
            $rowVol  = ($pieces > 0 && $volume > 0) ? $pieces * $volume : 0.0;

            $totalPieces += $pieces;
            $totalAmount += $rowAmt;
            $totalVolume += $rowVol;

            // 照片
            $photoHtml = '<span style="color:#999;">-</span>';
            $photoUrl = $item['photo_url'] ?? '';
            if ($photoUrl) {
                $photoPath = app()->getRootPath() . 'public' . $photoUrl;
                if (file_exists($photoPath)) {
                    $photoHtml = '<img src="' . $photoPath . '" width="60" height="75" />';
                }
            }

            $goodsName = htmlspecialchars($item['goods_name'] ?? '');
            $remark = htmlspecialchars($item['remark'] ?? '');
            $desc = $goodsName . ($remark ? '<br>' . $remark : '');

            $bgColor = ($idx % 2 === 1) ? ' style="background-color:#f8f9fc;"' : '';
            $itemsHtml .= '<tr' . $bgColor . '>';
            $itemsHtml .= '<td align="center" width="28">' . ($idx + 1) . '</td>';
            $itemsHtml .= '<td width="65">' . htmlspecialchars($item['customer_goods_no'] ?? '-') . '</td>';
            $itemsHtml .= '<td align="center" width="68">' . $photoHtml . '</td>';
            $itemsHtml .= '<td width="115">' . $desc . '</td>';
            $itemsHtml .= '<td align="center" width="35">' . ($pieces ?: '-') . '</td>';
            $itemsHtml .= '<td align="center" width="40">' . ($perQty ?: '-') . '</td>';
            $itemsHtml .= '<td align="right" width="45">' . ($price ? number_format($price, 2) : '-') . '</td>';
            $itemsHtml .= '<td align="right" width="55">' . ($rowAmt > 0 ? number_format($rowAmt, 2) : '-') . '</td>';
            $itemsHtml .= '<td align="center" width="40">' . ($volume ?: '-') . '</td>';
            $itemsHtml .= '<td align="right" width="50">' . ($rowVol > 0 ? round($rowVol, 4) : '-') . '</td>';
            $itemsHtml .= '</tr>';
        }

        // 构建完整 HTML
        $html = '
<table cellpadding="0" cellspacing="0" border="0" width="100%">
<tr><td align="center" style="font-size:20px;font-weight:bold;letter-spacing:3px;padding-bottom:2px;">青 岛 米 薇 儿 假 睫 毛 工 艺 品 有 限 公 司</td></tr>
<tr><td align="center" style="font-size:13px;padding-bottom:2px;color:#333;">Qingdao Snow Shirley Eyelashes Factory</td></tr>
<tr><td align="center" style="font-size:9px;color:#666;line-height:1.6;padding-bottom:8px;">
店面地址：义乌国际商贸城3区51号门3楼4街26702<br>
No.26702 SHOP 4th Street 3rd Floor Area 3/H Gate 51 Yiwu International Trade City<br>
电话/TEL：6657070980
</td></tr>
</table>

<table cellpadding="5" cellspacing="0" border="1" bordercolor="#999" width="100%" style="font-size:10px;">
<tr>
    <td width="50%">采购方(Buyer)：' . htmlspecialchars($customerName) . '</td>
    <td width="50%">客户唛头(Mark)：</td>
</tr>
<tr>
    <td>送货地址(Delivery Add)：</td>
    <td>订单号(Order No.)：' . htmlspecialchars($order->order_no ?: '') . '</td>
</tr>
<tr>
    <td>联系人电话(TEL)：' . htmlspecialchars($order->contact_phone ?: '') . '</td>
    <td>交易方式(Payment)：' . htmlspecialchars($order->payment_method ?: '') . '</td>
</tr>
<tr>
    <td>订货日期(Order date)：' . htmlspecialchars($order->order_date ?: '') . '</td>
    <td>交货日期(Delivery date)：' . htmlspecialchars($order->delivery_date ?: '') . '</td>
</tr>
</table>
<br>

<table cellpadding="4" cellspacing="0" border="1" bordercolor="#999" width="100%" style="font-size:9px;">
<thead>
    <tr style="background-color:#f0f1f5;font-weight:bold;">
        <td align="center" width="28">序号</td>
        <td width="65">货号</td>
        <td align="center" width="68">款式图片</td>
        <td width="115">产品描述</td>
        <td align="center" width="35">件数</td>
        <td align="center" width="40">装箱数</td>
        <td align="right" width="45">单价</td>
        <td align="right" width="55">总金额</td>
        <td align="center" width="40">体积</td>
        <td align="right" width="50">总体积</td>
    </tr>
</thead>
' . $itemsHtml . '
</table>
<br>

<table cellpadding="0" cellspacing="0" border="0" width="100%">
<tr><td style="font-size:11px;font-weight:bold;">
总件数：' . $totalPieces . ' &nbsp;&nbsp; 总金额：¥' . number_format($totalAmount, 2) . ' &nbsp;&nbsp; 总体积：' . round($totalVolume, 4) . ' m³
</td></tr>
<tr><td style="font-size:9px;color:#666;padding-top:6px;">
录单人：' . htmlspecialchars($creatorName) . ' &nbsp;&nbsp; 录单时间：' . ($order->create_time ?? '') . '
</td></tr>
</table>
';

        // 使用 TCPDF 生成 PDF
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('TradeOrderSystem');
        $pdf->SetAuthor($creatorName);
        $pdf->SetTitle('订单 ' . $order->order_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->SetFont('cid0cs', '', 10);  // CJK 中文字体
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        $filename = '订单_' . $order->order_no . '_' . date('YmdHis') . '.pdf';
        $pdf->Output($filename, 'D');
        exit;
    }
}
