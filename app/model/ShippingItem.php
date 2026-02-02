<?php
namespace app\model;

use think\Model;

class ShippingItem extends Model
{
    protected $name = 'shipping_item';
    protected $pk = 'id';

    public function shipping()
    {
        return $this->belongsTo(Shipping::class, 'shipping_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
