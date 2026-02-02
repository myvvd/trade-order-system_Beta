<?php
namespace app\model;

use think\Model;

class InventoryItem extends Model
{
    protected $name = 'inventory_item';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;

    public function inventory()
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
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
