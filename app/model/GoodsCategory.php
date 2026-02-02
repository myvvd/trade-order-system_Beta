<?php

namespace app\model;

use think\facade\Db;

/**
 * 货品分类模型
 */
class GoodsCategory extends \think\Model
{
    // 表名
    protected $name = 'goods_category';

    // 主键
    protected $pk = 'id';

    // 自动写入时间戳
    protected $autoWriteTimestamp = true;

    // 字段类型转换
    protected $type = [
        'parent_id' => 'integer',
        'sort'      => 'integer',
    ];

    /**
     * 获取子分类列表
     * @return \think\Collection
     */
    public function getChildren()
    {
        return $this->hasMany(GoodsCategory::class, 'parent_id')->order('sort', 'asc')->order('id', 'asc');
    }

    /**
     * 获取父级分类
     * @return GoodsCategory|null
     */
    public function getParent()
    {
        return $this->belongsTo(GoodsCategory::class, 'parent_id');
    }

    /**
     * 获取关联的货品数量
     * @return int
     */
    public function getGoodsCount(): int
    {
        return Db::name('goods')
            ->where('category_id', $this->id)
            ->where('delete_time', null)
            ->count();
    }

    /**
     * 获取所有顶级分类
     * @return array
     */
    public static function getTopCategories(): array
    {
        return self::where('parent_id', 0)->order('sort', 'asc')->select()->toArray();
    }

    /**
     * 获取树形结构
     * @param int $parentId 父级ID，0表示顶级
     * @return array
     */
    public static function getTree(int $parentId = 0): array
    {
        $categories = self::where('parent_id', $parentId)->order('sort', 'asc')->select()->toArray();

        foreach ($categories as &$category) {
            $category['children'] = self::getTree($category['id']);
            $category['goods_count'] = (new self($category))->getGoodsCount();
        }

        return $categories;
    }

    /**
     * 获取完整路径（如：青铜器 > 摆件 > 仿古摆件）
     * @return string
     */
    public function getFullPath(): string
    {
        $path = [];
        $current = $this;

        while ($current) {
            array_unshift($path, $current->name);
            if ($current->parent_id == 0) break;
            $current = $current->getParent();
        }

        return implode(' > ', $path);
    }

    /**
     * 检查是否可以删除
     * @return array [可删除, 原因]
     */
    public function canDelete(): array
    {
        // 检查是否有子分类
        $childrenCount = self::where('parent_id', $this->id)->count();
        if ($childrenCount > 0) {
            return [false, '该分类下还有 ' . $childrenCount . ' 个子分类，无法删除'];
        }

        // 检查是否有货品
        $goodsCount = $this->getGoodsCount();
        if ($goodsCount > 0) {
            return [false, '该分类下还有 ' . $goodsCount . ' 个货品，无法删除'];
        }

        return [true, ''];
    }
}
