<?php

namespace app\model;

class Stock extends \think\Model
{
    protected $name = 'stock';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    public function goods()
    {
        return $this->belongsTo(Goods::class, 'goods_id');
    }

    public function sku()
    {
        return $this->belongsTo(GoodsSku::class, 'sku_id');
    }
}
