<?php

namespace app\service;

use think\facade\Db;
use app\model\Order;
use app\model\OrderItem;
use app\model\OrderAttachment;
use app\model\OrderStatusLog;
use app\model\OrderDispatch;
use app\model\Goods;
use app\model\GoodsSku;
use app\service\MessageService;

class OrderService
{
    /**
     * 创建订单（包含明细），并写状态日志
     * @param array $data  包含 customer_id, items(array), total_amount, remark, attachments(array)
     * @param int|null $adminId
     * @return Order
     */
    public function createOrder(array $data, ?int $adminId = null): Order
    {
        return Db::transaction(function () use ($data, $adminId) {
            // 构建订单号：基础号 + 唛头文本
            $baseOrderNo = !empty($data['order_no']) ? trim($data['order_no']) : Order::generateOrderNo();
            $markText = trim($data['mark_text'] ?? '');
            $finalOrderNo = $markText !== '' ? $baseOrderNo . '-' . $markText : $baseOrderNo;

            // 检查订单号唯一性
            if (Order::where('order_no', $finalOrderNo)->find()) {
                throw new \Exception('订单号重复，请修改唛头文本或订单号后重试');
            }

            $order = new Order();
            $order->save([
                'order_no'        => $finalOrderNo,
                'customer_id'     => $data['customer_id'] ?? null,
                'status'          => Order::STATUS_PENDING,
                'creator_id'      => $adminId,
                'total_amount'    => $data['total_amount'] ?? 0,
                'delivery_date'   => $data['delivery_date'] ?? null,
                'order_date'      => $data['order_date'] ?? null,
                'trading_company' => $data['trading_company'] ?? null,
                'salesman'        => $data['salesman'] ?? null,
                'contact_phone'   => $data['contact_phone'] ?? null,
                'mark_id'         => $data['mark_id'] ?? null,
                'remark'          => $data['remark'] ?? ''
            ]);

            $items = $data['items'] ?? [];
            foreach ($items as $it) {
                // 获取商品名称和规格名称
                $goodsName = $it['goods_name'] ?? '';
                if (empty($goodsName) && !empty($it['goods_id'])) {
                    $goods = Goods::find($it['goods_id']);
                    $goodsName = $goods ? $goods->name : '未知商品';
                }

                $skuName = $it['sku_name'] ?? '';
                if (empty($skuName) && !empty($it['sku_id'])) {
                    $sku = GoodsSku::find($it['sku_id']);
                    $skuName = $sku ? $sku->sku_name : '';
                }

                $quantity = $it['quantity'] ?? 0;
                $unitPrice = $it['price'] ?? 0;
                $amount = $quantity * $unitPrice;

                $oi = new OrderItem();
                $oi->save([
                    'order_id'         => $order->id,
                    'customer_goods_no'=> $it['customer_goods_no'] ?? null,
                    'pieces'           => $it['pieces'] ?? 1,
                    'per_piece_qty'    => $it['per_piece_qty'] ?? 1,
                    'goods_id'         => $it['goods_id'] ?? null,
                    'goods_name'       => $goodsName,
                    'sku_id'           => $it['sku_id'] ?? null,
                    'sku_name'         => $skuName,
                    'quantity'         => $quantity,
                    'unit_price'       => $unitPrice,
                    'amount'           => $amount,
                    'box_size'         => $it['box_size'] ?? null,
                    'photo_url'        => $it['photo_url'] ?? null,
                    'photo_urls'       => isset($it['photo_urls']) ? (is_array($it['photo_urls']) ? json_encode($it['photo_urls']) : $it['photo_urls']) : null,
                    'desc_images'      => isset($it['desc_images']) ? (is_array($it['desc_images']) ? json_encode($it['desc_images']) : $it['desc_images']) : null,
                    'remark'           => $it['remark'] ?? '',
                    'packing_material' => $it['packing_material'] ?? null,
                    'volume'           => isset($it['volume']) && $it['volume'] !== '' ? $it['volume'] : null,
                    'label'            => $it['label'] ?? null,
                ]);
            }
            
            // 保存附件
            $attachments = $data['attachments'] ?? [];
            foreach ($attachments as $url) {
                if(empty($url)) continue;
                $att = new OrderAttachment();
                $att->save([
                    'order_id' => $order->id,
                    'file_path' => $url,
                    'file_name' => basename($url),
                    'file_size' => 0, // 暂不获大小
                    'file_type' => 'image',
                    'uploader_id' => $adminId
                ]);
            }

            $this->logStatus($order->id, $order->status, '创建订单', $adminId);

            // 发送站内信通知
            try {
                (new MessageService())->notifyOrderCreated($order, $adminId);
            } catch (\Throwable $e) {
                // 消息发送失败不影响主流程
            }

            return $order->refresh();
        });
    }

    /**
     * 复制订单
     */
    public function copyOrder(int $orderId, ?int $adminId = null): Order
    {
        $order = Order::with(['items', 'attachments'])->find($orderId);
        if (!$order) {
            throw new \RuntimeException('原订单不存在');
        }

        $totalAmount = 0;
        $items = [];
        foreach ($order->items as $item) {
            $quantity = (int)$item->quantity;
            $unitPrice = (float)$item->unit_price;
            $totalAmount += $quantity * $unitPrice;

            $items[] = [
                'customer_goods_no' => $item->customer_goods_no,
                'pieces'           => $item->pieces ?? 1,
                'per_piece_qty'    => $item->per_piece_qty ?? 1,
                'goods_id'         => $item->goods_id,
                'goods_name'       => $item->goods_name,
                'sku_id'           => $item->sku_id,
                'sku_name'         => $item->sku_name,
                'quantity'         => $quantity,
                'price'            => $unitPrice,
                'box_size'         => $item->box_size,
                'photo_url'        => $item->photo_url,
                'photo_urls'       => $item->photo_urls,
                'desc_images'      => $item->desc_images,
                'remark'           => $item->remark,
                'packing_material' => $item->packing_material,
                'volume'           => $item->volume,
                'label'            => $item->label,
            ];
        }

        $attachments = [];
        foreach ($order->attachments as $att) {
            if (!empty($att->file_path)) {
                $attachments[] = $att->file_path;
            }
        }

        $data = [
            'customer_id'     => $order->customer_id,
            'delivery_date'   => $order->delivery_date,
            'trading_company' => $order->trading_company,
            'salesman'        => $order->salesman,
            'contact_phone'   => $order->contact_phone,
            'remark'          => $order->remark,
            'total_amount'    => $totalAmount,
            'items'           => $items,
            'attachments'     => $attachments,
        ];

        return $this->createOrder($data, $adminId);
    }

    /**
     * 取消订单
     */
    public function cancelOrder(int $orderId, ?int $adminId = null): bool
    {
        return Db::transaction(function () use ($orderId, $adminId) {
            $order = Order::find($orderId);
            if (!$order) {
                throw new \RuntimeException('订单不存在');
            }
            if ($order->status != Order::STATUS_WAIT_CONFIRM) {
                throw new \RuntimeException('仅待确认订单可取消派单');
            }

            $dispatchIds = Db::name('order_dispatch')
                ->where('order_id', $orderId)
                ->where('status', OrderDispatch::STATUS_WAIT_CONFIRM)
                ->column('id');

            if (!empty($dispatchIds)) {
                Db::name('order_dispatch_item')->whereIn('dispatch_id', $dispatchIds)->delete();
                Db::name('order_dispatch')->whereIn('id', $dispatchIds)->delete();
            }

            $order->status = Order::STATUS_PENDING;
            $order->save();

            $this->logStatus($order->id, $order->status, '取消派单，恢复待派单', $adminId);

            return true;
        });
    }

    /**
     * 写状态日志
     */
    public function logStatus(int $orderId, int $status, string $remark = '', ?int $adminId = null, ?int $dispatchId = null): OrderStatusLog
    {
        $log = new OrderStatusLog();
        $log->save([
            'order_id' => $orderId,
            'dispatch_id' => $dispatchId,
            'to_status' => $status,
            'remark' => $remark,
            'operator_id' => $adminId,
            'operator_type' => 'admin',
        ]);

        return $log->refresh();
    }
}
