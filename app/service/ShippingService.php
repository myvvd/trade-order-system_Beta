<?php
namespace app\service;

use think\facade\Db;
use app\model\Shipping as ShippingModel;
use app\model\ShippingItem as ShippingItemModel;
use app\service\StockService;
use app\model\Order as OrderModel;
use app\model\OrderStatusLog as OrderStatusLogModel;
use app\service\MessageService;

class ShippingService
{
    // 创建出货单（简化）：$data 包含 customer_id, items[...] 等
    public function create(array $data)
    {
        return Db::transaction(function() use($data){
            $ship = new ShippingModel();
            $ship->save([
                'shipping_no' => $data['shipping_no'] ?? 'S'.time(),
                'customer_id' => $data['customer_id'] ?? null,
                'logistics_company' => $data['logistics_company'] ?? '',
                'logistics_no' => $data['logistics_no'] ?? '',
                'ship_date' => $data['ship_date'] ?? date('Y-m-d'),
                'remark' => $data['remark'] ?? '',
                'status' => 0,
                'creator_id' => $data['creator_id'] ?? null,
            ]);

            if (!empty($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $it) {
                    $itRow = new ShippingItemModel();
                    $itRow->save([
                        'shipping_id' => $ship->id,
                        'order_id' => $it['order_id'] ?? 0,
                        'order_item_id' => $it['order_item_id'] ?? 0,
                        'goods_id' => $it['goods_id'] ?? 0,
                        'sku_id' => $it['sku_id'] ?? 0,
                        'quantity' => (int)($it['quantity'] ?? 0),
                    ]);
                }
            }

            return $ship;
        });
    }

    // 确认发货：扣减库存、更新订单状态、写日志
    public function ship(int $shippingId, int $operatorId = 0)
    {
        $ship = ShippingModel::with('items')->find($shippingId);
        if (!$ship) throw new \Exception('出货单不存在');
        if ($ship->status == 1) throw new \Exception('已发货');

        $stockService = new StockService();

        return Db::transaction(function() use($ship, $stockService, $operatorId){
            foreach ($ship->items as $it) {
                $qty = (int)$it->quantity;
                if ($qty <= 0) continue;
                // 扣减库存
                $stockService->outStock($it->goods_id, $it->sku_id ?: null, $qty, 'shipping', $ship->id, '出货发货');

                // 更新关联订单项的已发货数量（若存在字段）
                if ($it->order_item_id) {
                    Db::name('order_item')->where('id', $it->order_item_id)->inc('shipped_quantity', $qty)->update();
                }

                // 更新订单主表状态为 已发货(60)
                if ($it->order_id) {
                    $order = OrderModel::find($it->order_id);
                    if ($order) {
                        $order->status = 60;
                        $order->save();

                        // 写入订单状态日志
                        $log = new OrderStatusLogModel();
                        $log->save([ 'order_id'=>$order->id, 'status'=>60, 'remark'=>'出货确认', 'operator_id'=>$operatorId ]);
                    }
                }
            }

            $ship->status = 1;
            $ship->shipped_at = date('Y-m-d H:i:s');
            $ship->operator_id = $operatorId;
            $ship->save();

            // 发送站内信通知
            try {
                $orderIds = array_unique(array_filter(array_column($ship->items->toArray(), 'order_id')));
                $msgService = new MessageService();
                foreach ($orderIds as $oid) {
                    $msgService->notifyShipped($oid, $ship->shipping_no, $operatorId);
                }
            } catch (\Throwable $e) {}

            return true;
        });
    }
}
