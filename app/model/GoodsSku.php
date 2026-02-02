<?php

namespace app\model;

use think\facade\Db;

/**
 * 货品规格模型
 */
class GoodsSku extends \think\Model
{
    // 表名
    protected $name = 'goods_sku';

    // 主键
    protected $pk = 'id';

    // 自动写入时间戳
    protected $autoWriteTimestamp = true;

    // 字段类型转换
    protected $type = [
        'goods_id'   => 'integer',
        'cost_price' => 'float',
        'sale_price' => 'float',
        'status'     => 'integer',
    ];

    /**
     * 获取关联的货品
     * @return Goods|null
     */
    public function getGoods()
    {
        return $this->belongsTo(Goods::class, 'goods_id');
    }

    /**
     * 获取库存信息
     * @return array|null
     */
    public function getStock(): ?array
    {
        return Db::name('stock')
            ->where('goods_id', $this->goods_id)
            ->where('sku_id', $this->id)
            ->find();
    }

    /**
     * 获取库存数量
     * @return int
     */
    public function getStockQuantity(): int
    {
        $stock = $this->getStock();
        return $stock['quantity'] ?? 0;
    }

    /**
     * 获取规格名称（如果为空则使用货品名称）
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->sku_name ?: $this->goods->name ?? '';
    }

    /**
     * 检查规格编号是否重复（排除自身）
     * @param string $skuNo
     * @param int $excludeId
     * @return bool
     */
    public static function skuNoExists(string $skuNo, int $excludeId = 0): bool
    {
        return self::where('sku_no', $skuNo)
            ->where('id', '<>', $excludeId)
            ->where('status', 1)
            ->count() > 0;
    }

    /**
     * 获取该货品的 SKU 数量
     * @return int
     */
    public function countByGoods(int $goodsId): int
    {
        return self::where('goods_id', $goodsId)->count();
    }
}
