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
use think\facade\Log;
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
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

class Order extends Base
{
    public function list()
    {
        $keyword = input('keyword', '');
        $delivery_status = input('delivery_status', '');
        $payment_status = input('payment_status', '');
        $date_from = input('date_from', '');
        $date_to = input('date_to', '');
        $page = input('page/d', 1);
        $pageSize = input('page_size/d', 15);

        $query = OrderModel::where('id', '>', 0);

        // 非超级管理员只能看到自己创建的订单
        if (!$this->isSuperAdmin()) {
            $query->where('creator_id', $this->getAdminId());
        }

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('order_no|remark', '%' . $keyword . '%')
                  ->whereOr('customer_id', 'in', function ($sq) use ($keyword) {
                      $sq->name('customer')->field('id')
                         ->whereLike('company_name|contact_name', '%' . $keyword . '%');
                  });
            });
        }

        if ($delivery_status !== '') {
            $query->where('delivery_status', $delivery_status);
        }

        if ($payment_status !== '') {
            $query->where('payment_status', $payment_status);
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

        $deliveryTabs = [
            '' => '全部',
            OrderModel::DELIVERY_NONE => '未送货',
            OrderModel::DELIVERY_PARTIAL => '部分送货',
            OrderModel::DELIVERY_ALL => '全部送货',
        ];

        return View::fetch('admin/order/list', [
            'orders' => $orders,
            'keyword' => $keyword,
            'delivery_status' => $delivery_status,
            'payment_status' => $payment_status,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'deliveryTabs' => $deliveryTabs,
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
        // 订单号格式可能是 YYMMDD-XXX 或 YYMMDD-XXX-唛头文本
        $existing = OrderModel::where('order_no', 'like', $prefix . '%')
            ->column('order_no');
        $maxSeq = 0;
        foreach ($existing as $no) {
            $parts = explode('-', $no, 3); // 最多分 3 段: YYMMDD, XXX, 唛头文本(可选)
            if (count($parts) >= 2 && strlen($parts[1]) === 3 && is_numeric($parts[1])) {
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

        // 关联唛头信息
        $orderArr = $order->toArray();
        if (!empty($orderArr['mark_id'])) {
            $markRecord = \app\model\CustomerMark::find($orderArr['mark_id']);
            $orderArr['mark_info'] = $markRecord ? $markRecord->toArray() : null;
        } else {
            $orderArr['mark_info'] = null;
        }

        return View::fetch('admin/order/form', [
            'order' => $orderArr,
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
                        'photo_urls'        => isset($it['photo_urls']) ? (is_array($it['photo_urls']) ? json_encode($it['photo_urls']) : $it['photo_urls']) : null,
                        'desc_images'       => isset($it['desc_images']) ? (is_array($it['desc_images']) ? json_encode($it['desc_images']) : $it['desc_images']) : null,
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
                $order->mark_id         = $params['mark_id'] ?? null;
                $order->remark          = $params['remark'] ?? $order->remark;

                // 重新构建订单号：基础号 + 唛头文本
                $markText = trim($params['mark_text'] ?? '');
                // 从当前 order_no 中提取基础号（YYMMDD-XXX 部分）
                $currentNo = $order->order_no;
                $noParts = explode('-', $currentNo, 3);
                $baseNo = (count($noParts) >= 2) ? $noParts[0] . '-' . $noParts[1] : $currentNo;
                $newOrderNo = $markText !== '' ? $baseNo . '-' . $markText : $baseNo;

                // 检查订单号唯一性（排除自身）
                if (OrderModel::where('order_no', $newOrderNo)->where('id', '<>', $order->id)->find()) {
                    throw new \Exception('订单号重复，请修改唛头文本或订单号后重试');
                }

                $order->order_no = $newOrderNo;

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
            'dispatches' => function($query) {
                $query->with(['supplier', 'items.orderItem']);
            },
            'statusLogs.admin', 
            'attachments', 
            'customer', 
            'creator'
        ])->find($id);

        if (!$order) {
            return redirect((string)url('admin/order/index'))->with('error', '订单不存在');
        }

        // 关联唛头信息
        if (!empty($order->mark_id)) {
            $markRecord = \app\model\CustomerMark::find($order->mark_id);
            $order->mark_info = $markRecord ? $markRecord->toArray() : null;
        } else {
            $order->mark_info = null;
        }

        return View::fetch('admin/order/detail', [
            'order' => $order,
            'title' => '订单详情',
            'isSuper' => $this->isSuperAdmin(),
            'canManageOrders' => $this->canManageOrders(),
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
        if (!$this->isSuperAdmin()) {
            return $this->error('仅超级管理员可执行派单操作');
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
                'item_status' => (int)($item->item_status ?? 10),
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
     * API: 批量派单保存（简化版：不拆单，直接对原订单创建派单记录）
     * 请求格式: { dispatches: [{ supplier_id: 1, items: [{order_item_id:1, quantity:5}, ...] }, ...] }
     */
    public function dispatchSave($id)
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        if (!$this->isSuperAdmin()) {
            return $this->error('仅超级管理员可执行派单操作');
        }

        $order = OrderModel::with(['items'])->find($id);
        if (!$order) {
            return $this->error('订单不存在');
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

        try {
            $service = new DispatchService();
            $adminId = $this->getAdminId();
            $createdIds = [];

            Db::transaction(function () use ($service, $id, $dispatches, $adminId, &$createdIds) {
                foreach ($dispatches as $d) {
                    $supplierId = (int)($d['supplier_id'] ?? 0);
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

                    // 直接对原订单创建派单，不拆单
                    $dispatch = $service->createDispatch($id, $supplierId, $validItems, $adminId);
                    $createdIds[] = $dispatch->id;
                }

                if (empty($createdIds)) {
                    throw new \RuntimeException('没有有效的派单数据');
                }
            });

            // 派单后同步所有货品状态和订单送货状态
            OrderModel::syncAllItemStatuses($id);

            return $this->success(
                ['dispatch_ids' => $createdIds],
                '派单成功，共创建' . count($createdIds) . '条派单'
            );
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
        // 图片导出很耗内存，临时提高限制
        @ini_set('memory_limit', '512M');

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
        $sheet->getColumnDimension('B')->setWidth(42);  // 款式图片
        $sheet->getColumnDimension('C')->setWidth(42);  // 描述图片
        $sheet->getColumnDimension('D')->setWidth(28);  // 货品名称
        $sheet->getColumnDimension('E')->setWidth(42);  // 产品描述
        $sheet->getColumnDimension('F')->setWidth(10);  // 件数
        $sheet->getColumnDimension('G')->setWidth(10);  // 装箱数
        $sheet->getColumnDimension('H')->setWidth(10);  // 单价
        $sheet->getColumnDimension('I')->setWidth(12);  // 总金额
        $sheet->getColumnDimension('J')->setWidth(10);  // 体积
        $sheet->getColumnDimension('K')->setWidth(12);  // 总体积

        // ==================== 第1行：公司中文名 ====================
        $sheet->mergeCells('A1:K1');
        $sheet->setCellValue('A1', '青 岛 米 薇 儿 假 睫 毛 工 艺 品 有 限 公 司');
        $sheet->getRowDimension(1)->setRowHeight(40);
        $sheet->getStyle('A1')->getFont()->setName($fontName)->setSize(28)->setBold(true);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        // ==================== 第2行：公司英文名 ====================
        $sheet->mergeCells('A2:K2');
        $sheet->setCellValue('A2', 'Qingdao Snow Shirley Eyelashes Factory');
        $sheet->getRowDimension(2)->setRowHeight(24);
        $sheet->getStyle('A2')->getFont()->setName($fontName)->setSize(18);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        // ==================== 第3行：地址+电话 ====================
        $sheet->mergeCells('A3:K3');
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

        // 合并单元格：每行左半 A-D，右半 E-K
        for ($r = 4; $r <= 7; $r++) {
            $sheet->mergeCells('A' . $r . ':D' . $r);
            $sheet->mergeCells('E' . $r . ':K' . $r);
            $sheet->getRowDimension($r)->setRowHeight(28);
            $sheet->getStyle('A' . $r)->getFont()->setName($fontName)->setSize(12);
            $sheet->getStyle('E' . $r)->getFont()->setName($fontName)->setSize(12);
            $sheet->getStyle('A' . $r)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('E' . $r)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }

        // 写入表头数据
        $sheet->setCellValue('A4', '采购方(Buyer)：' . $customerName);
        // 通过 mark_id 关联读取唛头内容
        $markText = '';
        $markImagePath = '';
        if (!empty($order->mark_id)) {
            $markRecord = \app\model\CustomerMark::find($order->mark_id);
            if ($markRecord) {
                $markText = $markRecord->mark_text ?: '';
                $markImagePath = $markRecord->mark_image ?: '';
            }
        }
        $sheet->setCellValue('E4', '客户唛头(Mark)：' . $markText);
        $sheet->setCellValue('A5', '送货地址(Delivery Add)：');
        $sheet->setCellValueExplicit('E5', '订单号(Order No.)：' . ($order->order_no ?: ''), DataType::TYPE_STRING);
        $sheet->setCellValue('A6', '联系人电话(TEL)：' . ($order->contact_phone ?: ''));
        $sheet->setCellValue('E6', '交易方式(Payment)：' . ($order->payment_method ?: ''));
        $sheet->setCellValue('A7', '订货日期(Order date)：' . ($order->order_date ?: ''));
        $sheet->setCellValue('E7', '交货日期(Delivery date)：' . ($order->delivery_date ?: ''));

        // 第4~7行边框
        $sheet->getStyle('A4:K7')->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('999999');

        // ==================== 第8行：货品明细标题行 ====================
        $headerCols = [
            'A8' => '货号', 'B8' => '款式图片', 'C8' => '描述图片', 'D8' => '货品名称', 'E8' => '产品描述',
            'F8' => '件数', 'G8' => '装箱数', 'H8' => '单价',
            'I8' => '总金额', 'J8' => '体积', 'K8' => '总体积',
        ];
        foreach ($headerCols as $cell => $label) {
            $sheet->setCellValue($cell, $label);
        }
        $sheet->getRowDimension(8)->setRowHeight(28);
        $sheet->getStyle('A8:K8')->getFont()->setName($fontName)->setSize(12)->setBold(true);
        $sheet->getStyle('A8:K8')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A8:K8')->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('999999');
        $sheet->getStyle('A8:K8')->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F7FA');

        // ==================== 辅助：解析图片URL列表 ====================
        $parseImageUrls = function($item) {
            $urls = [];
            // 优先 photo_urls JSON
            $photoUrlsRaw = $item['photo_urls'] ?? '';
            if ($photoUrlsRaw) {
                if (is_string($photoUrlsRaw)) {
                    $decoded = json_decode($photoUrlsRaw, true);
                    if (is_array($decoded)) $urls = $decoded;
                } elseif (is_array($photoUrlsRaw)) {
                    $urls = $photoUrlsRaw;
                }
            }
            // 兼容：如果 photo_urls 为空但 photo_url 有值
            if (empty($urls) && !empty($item['photo_url'])) {
                $urls = [$item['photo_url']];
            }
            return array_filter($urls);
        };

        $parseDescImages = function($item) {
            $urls = [];
            $raw = $item['desc_images'] ?? '';
            if ($raw) {
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) $urls = $decoded;
                } elseif (is_array($raw)) {
                    $urls = $raw;
                }
            }
            return array_filter($urls);
        };

        // ==================== 辅助：解析单个图片URL为本地路径 ====================
        $resolveImagePath = function($photoUrl) use (&$tempFiles) {
            if (!$photoUrl) return '';
            $photoUrlNorm = '/' . ltrim($photoUrl, '/');
            $publicDir = app()->getRootPath() . 'public';

            // 尝试多个可能的本地路径
            $candidatePaths = [
                $publicDir . $photoUrlNorm,
                $publicDir . str_replace('/uploads/cache/', '/uploads/goods/', $photoUrlNorm),
            ];
            if (strpos($photoUrlNorm, '/uploads/cache/') !== false) {
                $baseName = basename($photoUrlNorm);
                for ($m = 0; $m <= 3; $m++) {
                    $monthDir = date('Ym', strtotime("-{$m} months"));
                    $candidatePaths[] = $publicDir . '/uploads/goods/' . $monthDir . '/' . $baseName;
                }
            }

            foreach ($candidatePaths as $cp) {
                if (file_exists($cp) && is_readable($cp)) {
                    return $cp;
                }
            }

            // 本地找不到，HTTP下载
            $fullUrl = request()->domain() . $photoUrlNorm;
            $imgContent = null;
            if (function_exists('curl_init')) {
                try {
                    $ch = curl_init($fullUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 15,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => 0,
                        CURLOPT_USERAGENT      => 'ExcelExport/1.0',
                    ]);
                    $imgContent = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($httpCode !== 200 || $imgContent === false || strlen($imgContent) < 100) {
                        $imgContent = null;
                    }
                } catch (\Throwable $e) {
                    $imgContent = null;
                }
            }
            if (!$imgContent && ini_get('allow_url_fopen')) {
                try {
                    $ctx = stream_context_create([
                        'http' => ['timeout' => 10],
                        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
                    ]);
                    $imgContent = @file_get_contents($fullUrl, false, $ctx);
                    if ($imgContent === false || strlen($imgContent) < 100) $imgContent = null;
                } catch (\Throwable $e) { $imgContent = null; }
            }

            if ($imgContent) {
                $ext = pathinfo($photoUrl, PATHINFO_EXTENSION) ?: 'jpg';
                $tempDir = runtime_path() . 'temp';
                if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
                $tempPath = $tempDir . '/' . md5($photoUrl . microtime()) . '.' . $ext;
                file_put_contents($tempPath, $imgContent);
                $tempFiles[] = $tempPath;
                return $tempPath;
            }

            return '';
        };

        // ==================== 辅助：在单元格中插入多张图片（上下排列） ====================
        // 检测 mime_content_type 是否可用（Drawing::setPath 内部依赖该函数）
        $canUseDrawing = function_exists('mime_content_type');

        $insertMultiImages = function($imageUrls, $column, $row, $sheet, $idx) use ($resolveImagePath, $canUseDrawing) {
            $imgW = 100;  // 每张图宽度
            $imgH = 130;  // 每张图高度
            $gap = 5;     // 图片间距
            $offsetX = 5;
            $inserted = 0;

            foreach ($imageUrls as $imgIdx => $url) {
                $photoPath = $resolveImagePath($url);
                $offsetY = 5 + $imgIdx * ($imgH + $gap);

                if ($photoPath) {
                    // 优先 Drawing（需要 mime_content_type 函数支持）
                    if ($canUseDrawing) {
                        try {
                            $drawing = new Drawing();
                            $drawing->setName('Img_' . $column . '_' . $idx . '_' . $imgIdx);
                            $drawing->setPath($photoPath);
                            $drawing->setCoordinates($column . $row);
                            $drawing->setWidth($imgW);
                            $drawing->setHeight($imgH);
                            $drawing->setOffsetX($offsetX);
                            $drawing->setOffsetY($offsetY);
                            $drawing->setWorksheet($sheet);
                            $inserted++;
                            continue;
                        } catch (\Throwable $e) {
                            Log::error('Excel导出图片: Drawing失败 - ' . $e->getMessage());
                        }
                    }

                    // 降级 MemoryDrawing（GD方式，不依赖 fileinfo 扩展）
                    if (function_exists('imagecreatefromstring')) {
                        try {
                            $imageData = file_get_contents($photoPath);
                            $srcImage = @imagecreatefromstring($imageData);
                            unset($imageData); // 立即释放原始数据
                            if ($srcImage) {
                                // 缩放到目标尺寸，大幅减少内存占用
                                $thumbW = $imgW * 2; // 2x 保证清晰度
                                $thumbH = $imgH * 2;
                                $thumb = imagecreatetruecolor($thumbW, $thumbH);
                                imagecopyresampled($thumb, $srcImage, 0, 0, 0, 0, $thumbW, $thumbH, imagesx($srcImage), imagesy($srcImage));
                                imagedestroy($srcImage); // 释放原大图

                                $memDrawing = new MemoryDrawing();
                                $memDrawing->setName('Img_' . $column . '_' . $idx . '_' . $imgIdx);
                                $memDrawing->setImageResource($thumb);
                                $memDrawing->setRenderingFunction(MemoryDrawing::RENDERING_JPEG);
                                $memDrawing->setMimeType(MemoryDrawing::MIMETYPE_DEFAULT);
                                $memDrawing->setCoordinates($column . $row);
                                $memDrawing->setWidth($imgW);
                                $memDrawing->setHeight($imgH);
                                $memDrawing->setOffsetX($offsetX);
                                $memDrawing->setOffsetY($offsetY);
                                $memDrawing->setWorksheet($sheet);
                                $inserted++;
                                continue;
                            }
                        } catch (\Throwable $e) {
                            Log::error('Excel导出图片: MemoryDrawing失败 - ' . $e->getMessage());
                        }
                    }
                }
            }
            return $inserted;
        };

        // ==================== 唛头图片（如果有） ====================
        if ($markImagePath) {
            $resolvedMarkPath = $resolveImagePath($markImagePath);
            if ($resolvedMarkPath) {
                $markImgW = 60;
                $markImgH = 60;
                // 增大第4行行高以容纳图片
                $sheet->getRowDimension(4)->setRowHeight(70);
                $sheet->getStyle('E4')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

                if ($canUseDrawing) {
                    try {
                        $markDrawing = new Drawing();
                        $markDrawing->setName('MarkImage');
                        $markDrawing->setPath($resolvedMarkPath);
                        $markDrawing->setCoordinates('K4');
                        $markDrawing->setWidth($markImgW);
                        $markDrawing->setHeight($markImgH);
                        $markDrawing->setOffsetX(5);
                        $markDrawing->setOffsetY(5);
                        $markDrawing->setWorksheet($sheet);
                    } catch (\Throwable $e) {
                        Log::error('Excel唛头图片Drawing失败: ' . $e->getMessage());
                    }
                } elseif (function_exists('imagecreatefromstring')) {
                    try {
                        $imgData = file_get_contents($resolvedMarkPath);
                        $srcImg = @imagecreatefromstring($imgData);
                        unset($imgData);
                        if ($srcImg) {
                            $thumbW = $markImgW * 2;
                            $thumbH = $markImgH * 2;
                            $thumb = imagecreatetruecolor($thumbW, $thumbH);
                            imagecopyresampled($thumb, $srcImg, 0, 0, 0, 0, $thumbW, $thumbH, imagesx($srcImg), imagesy($srcImg));
                            imagedestroy($srcImg);
                            $memDraw = new MemoryDrawing();
                            $memDraw->setName('MarkImage');
                            $memDraw->setImageResource($thumb);
                            $memDraw->setRenderingFunction(MemoryDrawing::RENDERING_JPEG);
                            $memDraw->setMimeType(MemoryDrawing::MIMETYPE_DEFAULT);
                            $memDraw->setCoordinates('K4');
                            $memDraw->setWidth($markImgW);
                            $memDraw->setHeight($markImgH);
                            $memDraw->setOffsetX(5);
                            $memDraw->setOffsetY(5);
                            $memDraw->setWorksheet($sheet);
                        }
                    } catch (\Throwable $e) {
                        Log::error('Excel唛头图片MemoryDrawing失败: ' . $e->getMessage());
                    }
                }
            }
        }

        // ==================== 第9行起：货品明细数据 ====================
        $itemStartRow = 9;
        $row = $itemStartRow;
        $totalPieces = 0;
        $totalVolume = 0.0;
        $totalAmount = 0.0;
        $tempFiles = [];

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

            // 解析多图URL
            $photoUrls  = $parseImageUrls($item);
            $descImages = $parseDescImages($item);
            $maxImages  = max(count($photoUrls), count($descImages), 1);

            // 动态行高：单张160磅，多张 = 张数 * 135 + 25
            $rowHeight = $maxImages <= 1 ? 160 : ($maxImages * 135 + 25);
            $sheet->getRowDimension($row)->setRowHeight($rowHeight);

            // A = 货号
            $sheet->setCellValueExplicit('A' . $row, $item['customer_goods_no'] ?? '', DataType::TYPE_STRING);

            // B = 款式图片（多图上下排列）
            if (!empty($photoUrls)) {
                $inserted = $insertMultiImages($photoUrls, 'B', $row, $sheet, $idx);
                if ($inserted === 0) {
                    $sheet->setCellValue('B' . $row, '[图片] ' . implode(', ', $photoUrls));
                }
            }

            // C = 描述图片（多图上下排列）
            if (!empty($descImages)) {
                $inserted = $insertMultiImages($descImages, 'C', $row, $sheet, $idx);
                if ($inserted === 0) {
                    $sheet->setCellValue('C' . $row, '[图片] ' . implode(', ', $descImages));
                }
            }

            // D = 货品名称
            $sheet->setCellValue('D' . $row, $item['goods_name'] ?? '');
            $sheet->getStyle('D' . $row)->getAlignment()->setWrapText(true);

            // E = 产品描述
            $desc = trim(($item['goods_name'] ?? '') . ($item['remark'] ? "\n" . $item['remark'] : ''));
            $sheet->setCellValue('E' . $row, $desc);
            $sheet->getStyle('E' . $row)->getAlignment()->setWrapText(true);

            // F~K = 件数、装箱数、单价、总金额、体积、总体积
            $sheet->setCellValue('F' . $row, $pieces ?: '');
            $sheet->setCellValue('G' . $row, $perQty ?: '');
            $sheet->setCellValue('H' . $row, $price ?: '');
            $sheet->setCellValue('I' . $row, $rowAmt > 0 ? round($rowAmt, 2) : '');
            $sheet->setCellValue('J' . $row, $volume ?: '');
            $sheet->setCellValue('K' . $row, $rowVol > 0 ? round($rowVol, 2) : '');

            // 每行字体
            $sheet->getStyle('A' . $row . ':K' . $row)->getFont()->setName($fontName)->setSize(12);
            $sheet->getStyle('A' . $row . ':K' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

            $row++;
        }

        // 货品区边框
        $lastItemRow = $row - 1;
        if ($lastItemRow >= $itemStartRow) {
            $sheet->getStyle('A' . $itemStartRow . ':K' . $lastItemRow)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('999999');
        }

        // ==================== 汇总行 ====================
        $sumRow = $row;
        $sheet->mergeCells('A' . $sumRow . ':K' . $sumRow);
        $sheet->setCellValue('A' . $sumRow, '总件数：' . $totalPieces . '    总金额：¥' . number_format($totalAmount, 2) . '    总体积：' . round($totalVolume, 2) . ' m³');
        $sheet->getRowDimension($sumRow)->setRowHeight(36);
        $sheet->getStyle('A' . $sumRow . ':K' . $sumRow)->getFont()->setName($fontName)->setSize(12)->setBold(true);
        $sheet->getStyle('A' . $sumRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);

        // ==================== 录单信息 ====================
        $noteRow = $sumRow + 1;
        $sheet->mergeCells('A' . $noteRow . ':K' . $noteRow);
        $sheet->setCellValue('A' . $noteRow, '录单人：' . $creatorName . '    录单时间：' . ($order->create_time ?? ''));
        $sheet->getStyle('A' . $noteRow . ':K' . $noteRow)->getFont()->setName($fontName)->setSize(11);
        $sheet->getStyle('A' . $noteRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);

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

        // 清理临时下载的图片文件
        foreach ($tempFiles as $tf) {
            @unlink($tf);
        }
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
        $publicDir = app()->getRootPath() . 'public';

        // 辅助：解析 JSON 图片数组
        $parseImgArr = function($raw) {
            if (!$raw) return [];
            if (is_string($raw)) {
                $d = json_decode($raw, true);
                return is_array($d) ? array_filter($d) : [];
            }
            return is_array($raw) ? array_filter($raw) : [];
        };

        // 辅助：生成多图 HTML（上下排列）
        $buildImgHtml = function($urls) use ($publicDir) {
            if (empty($urls)) return '<span style="color:#999;">-</span>';
            $parts = [];
            foreach ($urls as $url) {
                $photoPath = $publicDir . '/' . ltrim($url, '/');
                if (file_exists($photoPath)) {
                    $parts[] = '<img src="' . $photoPath . '" width="60" height="75" />';
                }
            }
            return $parts ? implode('<br>', $parts) : '<span style="color:#999;">-</span>';
        };

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

            // 款式图片（多图）
            $photoUrls = $parseImgArr($item['photo_urls'] ?? '');
            if (empty($photoUrls) && !empty($item['photo_url'])) {
                $photoUrls = [$item['photo_url']];
            }
            $photoHtml = $buildImgHtml($photoUrls);

            // 描述图片（多图）
            $descImages = $parseImgArr($item['desc_images'] ?? '');
            $descImgHtml = $buildImgHtml($descImages);

            $goodsName = htmlspecialchars($item['goods_name'] ?? '');
            $remark = htmlspecialchars($item['remark'] ?? '');
            $desc = $goodsName . ($remark ? '<br>' . $remark : '');

            $bgColor = ($idx % 2 === 1) ? ' style="background-color:#f8f9fc;"' : '';
            $itemsHtml .= '<tr' . $bgColor . '>';
            $itemsHtml .= '<td align="center" width="25">' . ($idx + 1) . '</td>';
            $itemsHtml .= '<td width="55">' . htmlspecialchars($item['customer_goods_no'] ?? '-') . '</td>';
            $itemsHtml .= '<td align="center" width="62">' . $photoHtml . '</td>';
            $itemsHtml .= '<td align="center" width="62">' . $descImgHtml . '</td>';
            $itemsHtml .= '<td width="100">' . $desc . '</td>';
            $itemsHtml .= '<td align="center" width="30">' . ($pieces ?: '-') . '</td>';
            $itemsHtml .= '<td align="center" width="35">' . ($perQty ?: '-') . '</td>';
            $itemsHtml .= '<td align="right" width="40">' . ($price ? number_format($price, 2) : '-') . '</td>';
            $itemsHtml .= '<td align="right" width="48">' . ($rowAmt > 0 ? number_format($rowAmt, 2) : '-') . '</td>';
            $itemsHtml .= '<td align="center" width="35">' . ($volume ?: '-') . '</td>';
            $itemsHtml .= '<td align="right" width="45">' . ($rowVol > 0 ? round($rowVol, 2) : '-') . '</td>';
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
    <td width="50%">客户唛头(Mark)：' . (function() use ($order) {
        $mt = ''; $mi = '';
        if (!empty($order->mark_id)) {
            $mk = \app\model\CustomerMark::find($order->mark_id);
            if ($mk) { $mt = $mk->mark_text ?: ''; $mi = $mk->mark_image ?: ''; }
        }
        return htmlspecialchars($mt) . ($mi ? ' <img src="' . htmlspecialchars($mi) . '" width="40" height="40" />' : '');
    })() . '</td>
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
        <td align="center" width="25">序号</td>
        <td width="55">货号</td>
        <td align="center" width="62">款式图片</td>
        <td align="center" width="62">描述图片</td>
        <td width="100">产品描述</td>
        <td align="center" width="30">件数</td>
        <td align="center" width="35">装箱数</td>
        <td align="right" width="40">单价</td>
        <td align="right" width="48">总金额</td>
        <td align="center" width="35">体积</td>
        <td align="right" width="45">总体积</td>
    </tr>
</thead>
' . $itemsHtml . '
</table>
<br>

<table cellpadding="0" cellspacing="0" border="0" width="100%">
<tr><td style="font-size:11px;font-weight:bold;">
总件数：' . $totalPieces . ' &nbsp;&nbsp; 总金额：¥' . number_format($totalAmount, 2) . ' &nbsp;&nbsp; 总体积：' . round($totalVolume, 2) . ' m³
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

    /**
     * API: 更新订单明细项
     */
    public function updateItem($id)
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        if (!$this->canManageOrders()) {
            return $this->error('暂无操作权限');
        }

        $item = \app\model\OrderItem::find($id);
        if (!$item) {
            return $this->error('明细项不存在');
        }

        $order = OrderModel::find($item->order_id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input)) {
            return $this->error('参数错误');
        }

        try {
            // 允许修改的字段
            $allowFields = [
                'customer_goods_no', 'goods_name', 'remark', 'packing_material',
                'pieces', 'per_piece_qty', 'unit_price', 'volume', 'label', 'photo_url',
                'photo_urls', 'desc_images'
            ];

            // photo_urls 和 desc_images 需要 JSON 编码
            if (array_key_exists('photo_urls', $input) && is_array($input['photo_urls'])) {
                $input['photo_urls'] = json_encode($input['photo_urls']);
            }
            if (array_key_exists('desc_images', $input) && is_array($input['desc_images'])) {
                $input['desc_images'] = json_encode($input['desc_images']);
            }

            foreach ($allowFields as $field) {
                if (array_key_exists($field, $input)) {
                    $item->$field = $input[$field];
                }
            }

            // 自动计算 quantity 和 amount
            $pieces = (int)($item->pieces ?: 0);
            $perPieceQty = (int)($item->per_piece_qty ?: 0);
            $item->quantity = $pieces * $perPieceQty;
            $item->amount = $item->quantity * (float)$item->unit_price;

            $item->save();

            return $this->success($item->toArray(), '更新成功');
        } catch (\Exception $e) {
            return $this->error('更新失败：' . $e->getMessage());
        }
    }

    /**
     * API: 逻辑删除订单明细项
     */
    public function deleteItem($id)
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        if (!$this->canManageOrders()) {
            return $this->error('暂无操作权限');
        }

        $item = \app\model\OrderItem::find($id);
        if (!$item) {
            return $this->error('明细项不存在');
        }

        try {
            $item->is_deleted = 1;
            $item->save();
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败：' . $e->getMessage());
        }
    }

    /**
     * 更新订单的已送货数量/已收款金额，并自动同步送货/收款状态
     */
    public function updateDeliveryPayment($id)
    {
        if (!$this->canManageOrders()) {
            return $this->error('暂无操作权限');
        }

        $order = OrderModel::with(['items'])->find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        $field = input('post.field');
        $value = input('post.value');

        if (!in_array($field, ['delivered_qty', 'paid_amount'])) {
            return $this->error('无效的字段');
        }

        $value = floatval($value);
        if ($value < 0) {
            return $this->error('数值不能小于0');
        }

        // 计算总件数
        $totalQty = 0;
        foreach ($order->items as $item) {
            $totalQty += (int)$item->quantity;
        }

        try {
            if ($field === 'delivered_qty') {
                $intVal = (int)$value;
                if ($intVal > $totalQty) {
                    return $this->error('已送货数量不能超过总件数(' . $totalQty . ')');
                }
                $order->delivered_qty = $intVal;
                // 自动同步送货状态
                if ($intVal == 0) {
                    $order->delivery_status = OrderModel::DELIVERY_NONE;
                } elseif ($intVal >= $totalQty) {
                    $order->delivery_status = OrderModel::DELIVERY_ALL;
                } else {
                    $order->delivery_status = OrderModel::DELIVERY_PARTIAL;
                }
            } else {
                // paid_amount
                $totalAmount = floatval($order->total_amount);
                if ($value > $totalAmount) {
                    return $this->error('已收款金额不能超过总金额(¥' . number_format($totalAmount, 2) . ')');
                }
                $order->paid_amount = $value;
                // 自动同步收款状态
                if ($value == 0) {
                    $order->payment_status = OrderModel::PAYMENT_NONE;
                } elseif ($value >= $totalAmount) {
                    $order->payment_status = OrderModel::PAYMENT_ALL;
                } else {
                    $order->payment_status = OrderModel::PAYMENT_PARTIAL;
                }
            }
            $order->save();

            return $this->success([
                'delivered_qty' => (int)$order->delivered_qty,
                'paid_amount' => number_format(floatval($order->paid_amount), 2, '.', ''),
                'delivery_status' => (int)$order->delivery_status,
                'payment_status' => (int)$order->payment_status,
            ], '更新成功');
        } catch (\Exception $e) {
            return $this->error('更新失败：' . $e->getMessage());
        }
    }
}
