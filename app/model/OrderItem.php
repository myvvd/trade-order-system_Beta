<?php

namespace app\model;

class OrderItem extends \think\Model
{
    protected $name = 'order_item';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function goods()
    {
        return $this->belongsTo(Goods::class, 'goods_id');
    }

    public function sku()
    {
        return $this->belongsTo(GoodsSku::class, 'sku_id');
    }
}
