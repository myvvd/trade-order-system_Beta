<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\GoodsCategory as GoodsCategoryModel;
use app\validate\GoodsValidate;
use think\facade\View;
use think\Response;

/**
 * 洧品分类控制器
 */
class GoodsCategory extends Base
{
    /**
     * 分类列表（树形结构）
     * @return string
     */
    public function list()
    {
        $keyword = input('keyword', '');

        $query = GoodsCategoryModel::order('sort', 'asc')->order('id', 'asc');

        if ($keyword) {
            $query->whereLike('name', '%' . $keyword . '%');
        }

        $categories = $query->select()->toArray();

        // 构建树形结构
        $tree = $this->buildTree($categories);

        return View::fetch('admin/goods_category/list', [
            'tree'     => $tree,
            'keyword'  => $keyword,
            'title'     => '货品分类',
            'breadcrumb' => '货品分类',
            'current_page' => 'goods_category',
        ]);
    }

    /**
     * 构建树形结构
     * @param array $categories
     * @param int $parentId
     * @return array
     */
    protected function buildTree(array $categories, int $parentId = 0): array
    {
        $tree = [];
        foreach ($categories as $category) {
            if ($category['parent_id'] == $parentId) {
                $category['children'] = $this->buildTree($categories, $category['id']);
                $category['goods_count'] = (new GoodsCategoryModel($category))->getGoodsCount();
                $tree[] = $category;
            }
        }
        return $tree;
    }

    /**
     * 显示创建/编辑表单
     * @param int|null $id
     * @return string
     */
    public function form($id = null)
    {
        $category = null;

        if ($id) {
            $category = GoodsCategoryModel::find($id);
            if (!$category) {
                return redirect('admin/goods_category/list')->with('error', '分类不存在');
            }
        }

        // 获取所有可用的父级分类（排除自身及其子分类）
        $parentId = $category ? $category->id : 0;
        $excludeIds = $this->getChildrenIds($parentId);
        $excludeIds[] = $parentId;

        $parentOptions = GoodsCategoryModel::whereNotIn('id', $excludeIds)
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        return View::fetch('admin/goods_category/form', [
            'category'      => $category,
            'parentOptions' => $parentOptions,
            'title'         => $id ? '编辑分类' : '创建分类',
            'breadcrumb'    => $id ? '编辑分类' : '创建分类',
        ]);
    }

    /**
     * 获取分类及其所有子分类的 ID
     * @param int $categoryId
     * @return array
     */
    protected function getChildrenIds(int $categoryId): array
    {
        $ids = [$categoryId];
        $children = GoodsCategoryModel::where('parent_id', $categoryId)->select()->toArray();

        foreach ($children as $child) {
            $ids = array_merge($ids, $this->getChildrenIds($child['id']));
        }

        return array_unique($ids);
    }

    /**
     * 创建分类
     * @return Response
     */
    public function create(): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $params = $this->post();
        $name = $params['name'] ?? '';
        $parentId = $params['parent_id'] ?? 0;
        $sort = $params['sort'] ?? 0;

        if (!$name) {
            return $this->error('分类名称不能为空');
        }

        try {
            GoodsCategoryModel::create([
                'name'      => $name,
                'parent_id' => $parentId,
                'sort'      => $sort,
            ]);

            return $this->success(null, '创建成功');
        } catch (\Exception $e) {
            return $this->error('创建失败：' . $e->getMessage());
        }
    }

    /**
     * 编辑分类
     * @return Response
     */
    public function update(): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = $this->post('category_id/d', 0);
        if (!$id) {
            return $this->error('分类ID不能为空');
        }

        $category = GoodsCategoryModel::find($id);
        if (!$category) {
            return $this->error('分类不存在');
        }

        $params = $this->post();
        $name = $params['name'] ?? '';
        $parentId = $params['parent_id'] ?? 0;
        $sort = $params['sort'] ?? 0;

        // 检查父级不能是自身或其子分类
        if (in_array($parentId, $this->getChildrenIds($id))) {
            return $this->error('父级分类选择错误，不能选择自身或子分类');
        }

        try {
            $category->save([
                'name'      => $name,
                'parent_id' => $parentId,
                'sort'      => $sort,
            ]);

            return $this->success(null, '保存成功');
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    /**
     * 删除分类
     * @return Response
     */
    public function delete(): Response
    {
        $id = $this->post('category_id/d', 0);
        if (!$id) {
            return $this->error('分类ID不能为空');
        }

        $category = GoodsCategoryModel::find($id);
        if (!$category) {
            return $this->error('分类不存在');
        }

        [$canDelete, $errorMsg] = $category->canDelete();

        if (!$canDelete) {
            return $this->error($errorMsg);
        }

        try {
            $category->delete();
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败：' . $e->getMessage());
        }
    }
}
