<?php

namespace app\model;

use think\facade\Db;

/**
 * 货品模型
 */
class Goods extends \think\Model
{
    // 表名
    protected $name = 'goods';

    // 主键
    protected $pk = 'id';

    // 自动写入时间戳
    protected $autoWriteTimestamp = true;

    // 隐藏字段（软删除）
    protected $hidden = ['delete_time'];

    // 字段类型转换
    protected $type = [
        'category_id' => 'integer',
        'cost_price'  => 'float',
        'sale_price'  => 'float',
        'status'      => 'integer',
    ];

    /**
     * 获取所属分类
     * @return GoodsCategory|null
     */
    public function getCategory()
    {
        return $this->belongsTo(GoodsCategory::class, 'category_id');
    }

    /**
     * 获取 SKU 列表
     * @return \think\Collection
     */
    public function getSkus()
    {
        return $this->hasMany(GoodsSku::class, 'goods_id')->order('id', 'asc');
    }

    /**
     * 获取启用状态的 SKU 列表
     * @return array
     */
    public function getActiveSkus(): array
    {
        return GoodsSku::where('goods_id', $this->id)
            ->where('status', 1)
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 获取库存信息（汇总）
     * @return array
     */
    public function getStockSummary(): array
    {
        $result = Db::name('stock')
            ->field([
                'SUM(quantity) as total_quantity',
                'COUNT(*) as sku_count'
            ])
            ->where('goods_id', $this->id)
            ->find();

        return [
            'total_quantity' => (int)($result['total_quantity'] ?? 0),
            'sku_count'      => (int)($result['sku_count'] ?? 0),
        ];
    }

    /**
     * 获取所有 SKU 的库存明细
     * @return array
     */
    public function getSkuStockDetails(): array
    {
        $skus = GoodsSku::where('goods_id', $this->id)
            ->where('status', 1)
            ->select()
            ->toArray();

        $stockInfo = Db::name('stock')
            ->where('goods_id', $this->id)
            ->column('quantity', 'sku_id');

        foreach ($skus as &$sku) {
            $sku['stock_quantity'] = $stockInfo[$sku['id']] ?? 0;
        }

        return $skus;
    }

    /**
     * 获取货品图片完整路径
     * @return string
     */
    public function getImageUrl(): string
    {
        if (!$this->image) {
            return '/static/images/no-image.svg';
        }

        // 如果是相对路径，添加 /static/
        if (!str_starts_with($this->image, 'http') && !str_starts_with($this->image, '/')) {
            return '/' . $this->image;
        }

        return $this->image;
    }

    /**
     * 获取分类名称（含父级）
     * @return string
     */
    public function getCategoryPath(): string
    {
        $category = $this->getCategory();
        if (!$category) return '';

        return $category->getFullPath();
    }

    /**
     * 根据货品编号获取
     * @param string $goodsNo
     * @return Goods|null
     */
    public static function getByGoodsNo(string $goodsNo): ?Goods
    {
        return self::where('goods_no', $goodsNo)
            ->where('delete_time', null)
            ->find();
    }

    /**
     * 检查是否可以删除
     * @return array [可删除, 原因]
     */
    public function canDelete(): array
    {
        // 检查是否有未完成的订单关联
        $orderItemCount = Db::name('order_item')
            ->alias('oi')
            ->join('order o', 'o.id = oi.order_id')
            ->where('oi.goods_id', $this->id)
            ->whereNotIn('o.status', [99]) // 排除已取消的订单
            ->count();

        if ($orderItemCount > 0) {
            return [false, '该货品还有 ' . $orderItemCount . ' 条未完成的订单记录，无法删除'];
        }

        // 检查是否有库存
        $stockSummary = $this->getStockSummary();
        if ($stockSummary['total_quantity'] > 0) {
            return [false, '该货品还有库存，无法删除'];
        }

        return [true, ''];
    }

    /**
     * 软删除
     * @return bool
     */
    public function softDelete(): bool
    {
        return $this->save(['delete_time' => date('Y-m-d H:i:s')]);
    }
}
