<?php

namespace app\model;

class StockLog extends \think\Model
{
    protected $name = 'stock_log';
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

    public function operator()
    {
        return $this->belongsTo(AdminUser::class, 'operator_id');
    }
}
