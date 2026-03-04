<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\Goods as GoodsModel;
use app\model\GoodsSku as GoodsSkuModel;
use app\model\GoodsCategory as GoodsCategoryModel;
use app\validate\GoodsValidate;
use think\facade\View;
use think\Response;

/**
 * 货品管理控制器
 */
class Goods extends Base
{
    /**
     * 货品列表
     * @return string
     */
    public function list()
    {
        $keyword = input('keyword', '');
        $categoryId = input('category_id/d', 0);
        $status = input('status', '');
        $page = input('page/d', 1);
        $pageSize = input('page_size/d', 15);

        $query = GoodsModel::where('delete_time', null);

        if ($keyword) {
            $query->whereLike('goods_no|name|description', '%' . $keyword . '%');
        }

        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $goods = $query->order('id', 'desc')->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);

        // 获取分类数据
        $categories = GoodsCategoryModel::select()->toArray();

        // 为每个货品添加分类名称和图片URL
        foreach ($goods as $item) {
            $item->category_name = '-';
            if ($item->category_id) {
                foreach ($categories as $cat) {
                    if ($cat['id'] == $item->category_id) {
                        $item->category_name = $cat['name'];
                        break;
                    }
                }
            }
            $item->image_url = $item->getImageUrl();
        }

        return View::fetch('admin/goods/list', [
            'goods'       => $goods,
            'categories'  => $categories,
            'keyword'     => $keyword,
            'categoryId'  => $categoryId,
            'status'      => $status,
            'title'       => '货品管理',
            'breadcrumb'  => '货品管理',
            'current_page' => 'goods',
        ]);
    }

    /**
     * 显示创建表单
     * @return string
     */
    public function create()
    {
        return $this->showForm(null);
    }

    /**
     * 显示编辑表单
     * @param int $id
     * @return string
     */
    public function edit($id)
    {
        return $this->showForm($id);
    }

    /**
     * 显示表单（新建或编辑）
     * @param int|null $id
     * @return string
     */
    protected function showForm($id = null)
    {
        $goods = null;
        $skus = [];

        if ($id) {
            $goods = GoodsModel::find($id);
            if (!$goods) {
                return redirect('admin/goods/list')->with('error', '货品不存在');
            }
            $skus = $goods->getActiveSkus();
        }

        $categories = GoodsCategoryModel::getTopCategories();
        $imageUrl = $goods ? $goods->getImageUrl() : '/static/images/no-image.png';

        return View::fetch('admin/goods/form', [
            'goods'           => $goods,
            'skus'            => $skus,
            'categories'      => $categories,
            'categories_json' => json_encode($categories, JSON_UNESCAPED_UNICODE),
            'image_url'       => $imageUrl,
            'title'           => $id ? '编辑货品' : '新增货品',
            'breadcrumb'      => $id ? '编辑货品' : '新增货品',
        ]);
    }

    /**
     * 货品详情
     * @param int $id
     * @return string
     */
    public function detail($id)
    {
        $goods = GoodsModel::find($id);
        if (!$goods) {
            return redirect('admin/goods/list')->with('error', '货品不存在');
        }

        $skus = $goods->getSkuStockDetails();
        $stockSummary = $goods->getStockSummary();

        return View::fetch('admin/goods/detail', [
            'goods'        => $goods,
            'skus'         => $skus,
            'stockSummary' => $stockSummary,
            'title'         => '货品详情',
            'breadcrumb'    => '货品详情',
        ]);
    }

    /**
     * 货品图片临时上传（缓存）
     * @return Response
     */
    public function uploadTempImage(): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        try {
            $file = request()->file('image');
            if (!$file) {
                return $this->error('未选择图片');
            }

            $ext = strtolower($file->extension());
            $allowExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowExt, true)) {
                return $this->error('图片格式不支持');
            }

            if ($file->getSize() > 5 * 1024 * 1024) {
                return $this->error('图片大小不能超过 5MB');
            }

            $tempDir = root_path() . 'public/uploads/cache';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $filename = 'goods_' . date('YmdHis') . '_' . uniqid() . '.' . $ext;
            $file->move($tempDir, $filename);

            $tempPath = 'uploads/cache/' . $filename;
            return $this->success([
                'temp_path' => $tempPath,
                'url'       => '/' . $tempPath,
            ], '上传成功');
        } catch (\Throwable $e) {
            return $this->error('上传异常：' . $e->getMessage());
        }
    }

    /**
     * 将临时图片移动到正式目录
     * @param string $tempPath
     * @return string|null
     */
    protected function moveTempImage(string $tempPath): ?string
    {
        $tempPath = ltrim($tempPath, '/');
        $source = root_path() . 'public/' . $tempPath;
        if (!is_file($source)) {
            return null;
        }

        $dateDir = date('Ym');
        $finalDir = root_path() . 'public/uploads/goods/' . $dateDir;
        if (!is_dir($finalDir)) {
            mkdir($finalDir, 0755, true);
        }

        $filename = basename($source);
        $finalRel = 'uploads/goods/' . $dateDir . '/' . $filename;
        $target = root_path() . 'public/' . $finalRel;

        if (!@rename($source, $target)) {
            if (@copy($source, $target)) {
                @unlink($source);
            } else {
                return null;
            }
        }

        return $finalRel;
    }

    /**
     * 保存货品
     * @return Response
     */
    public function save(): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $params = $this->post();

        $tempImage = $params['temp_image'] ?? '';
        if ($tempImage) {
            $finalImage = $this->moveTempImage($tempImage);
            if ($finalImage) {
                $params['image'] = $finalImage;
            }
        }

        // 验证货品基本信息
        try {
            validate([
                'goods_no'    => 'require|length:1,50',
                'name'         => 'require|length:1,200',
                'category_id'  => 'integer',
                'unit'         => 'length:1,20',
                'cost_price'   => 'float|egt:0',
                'sale_price'   => 'float|egt:0',
            ])->check($params);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        // 检查货品编号是否重复
        $goodsNo = $params['goods_no'] ?? '';
        $existingGoods = GoodsModel::getByGoodsNo($goodsNo);
        if ($existingGoods && (!isset($params['id']) || $existingGoods->id != $params['id'])) {
            return $this->error('货品编号已存在');
        }

        try {
            $goodsData = [
                'goods_no'     => $goodsNo,
                'name'          => $params['name'] ?? '',
                'category_id'   => $params['category_id'] ?? 0,
                'unit'          => $params['unit'] ?? '件',
                'cost_price'    => $params['cost_price'] ?? 0,
                'sale_price'    => $params['sale_price'] ?? 0,
                'image'         => $params['image'] ?? '',
                'description'   => $params['description'] ?? '',
                'status'        => 1,
            ];

            if (isset($params['id']) && $params['id']) {
                // 更新
                $goods = GoodsModel::find($params['id']);
                $goods->save($goodsData);
            } else {
                // 创建
                $goods = GoodsModel::create($goodsData);
            }

            return $this->success(['id' => $goods->id], '保存成功');
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    /**
     * 更新货品
     * @return Response
     */
    public function update(): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = $this->post('id/d', 0);
        if (!$id) {
            return $this->error('货品ID不能为空');
        }

        $params = $this->post();

        $tempImage = $params['temp_image'] ?? '';
        if ($tempImage) {
            $finalImage = $this->moveTempImage($tempImage);
            if ($finalImage) {
                $params['image'] = $finalImage;
            }
        }

        try {
            $goods = GoodsModel::find($id);
            if (!$goods) {
                return $this->error('货品不存在');
            }

            $goodsData = [
                'goods_no'     => $params['goods_no'] ?? $goods->goods_no,
                'name'          => $params['name'] ?? $goods->name,
                'category_id'   => $params['category_id'] ?? $goods->category_id,
                'unit'          => $params['unit'] ?? $goods->unit,
                'cost_price'    => $params['cost_price'] ?? $goods->cost_price,
                'sale_price'    => $params['sale_price'] ?? $goods->sale_price,
                'image'         => $params['image'] ?? $goods->image,
                'description'   => $params['description'] ?? $goods->description,
                'status'        => $params['status'] ?? $goods->status,
            ];

            $goods->save($goodsData);

            return $this->success(null, '更新成功');
        } catch (\Exception $e) {
            return $this->error('更新失败：' . $e->getMessage());
        }
    }

    /**
     * 删除货品
     * @return Response
     */
    public function delete(): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = $this->post('id/d', 0);
        if (!$id) {
            return $this->error('货品ID不能为空');
        }

        $goods = GoodsModel::find($id);
        if (!$goods) {
            return $this->error('货品不存在');
        }

        [$canDelete, $errorMsg] = $goods->canDelete();

        if (!$canDelete) {
            return $this->error($errorMsg);
        }

        try {
            $goods->softDelete();
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败：' . $e->getMessage());
        }
    }

    /**
     * 批量删除
     * @return Response
     */
    public function batchDelete(): Response
    {
        $ids = $this->post('ids/a', []);
        if (empty($ids)) {
            return $this->error('请选择要删除的货品');
        }

        $failed = [];
        $success = 0;

        foreach ($ids as $id) {
            $goods = GoodsModel::find($id);
            if ($goods) {
                [$canDelete, ] = $goods->canDelete();
                if ($canDelete) {
                    $goods->softDelete();
                    $success++;
                } else {
                    $failed[] = $goods->goods_no;
                }
            }
        }

        if ($failed) {
            return $this->error([
                'success'      => $success,
                'failed'       => count($failed),
                'failed_items' => $failed,
            ], '部分货品删除失败：' . implode(', ', $failed));
        }

        return $this->success(['count' => $success], '成功删除 ' . $success . ' 个货品');
    }

    // ==================== SKU 管理相关 ====================

    /**
     * 获取货品的 SKU 列表
     * @return Response
     */
    public function skuList(): Response
    {
        $goodsId = input('id/d', 0);
        if (!$goodsId) {
             $goodsId = input('goods_id/d', 0);
        }
        
        $skus = GoodsSkuModel::where('goods_id', $goodsId)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $this->success($skus);
    }

    /**
     * 简易商品搜索用于下拉选择（返回 JSON）
     */
    public function search()
    {
        $q = input('q', '');
        $limit = input('limit/d', 20);

        $query = GoodsModel::where('delete_time', null)->where('status', 1);
        if ($q) {
            $query->whereLike('goods_no|name|description', '%' . $q . '%');
        }

        $rows = $query->order('id', 'desc')->limit($limit)->select();
        $data = [];
        foreach ($rows as $r) {
            $imageUrl = $r->getImageUrl();
            $data[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'goods_no' => $r['goods_no'],
                'sale_price' => $r['sale_price'] ?? 0,
                'image' => $r['image'] ?? '',
                'image_url' => $imageUrl,
            ];
        }

        return $this->success($data);
    }

    /**
     * 保存 SKU
     * @return Response
     */
    public function saveSku(): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $params = $this->post();
        $goodsId = $params['goods_id'] ?? 0;
        $skuNo = $params['sku_no'] ?? '';

        if (!$goodsId) {
            return $this->error('货品ID不能为空');
        }

        if (!$skuNo) {
            return $this->error('SKU编号不能为空');
        }

        // 检查 SKU 编号是否重复
        $skuId = $params['sku_id'] ?? 0;
        if (GoodsSkuModel::skuNoExists($skuNo, $skuId)) {
            return $this->error('SKU编号已存在');
        }

        try {
            $skuData = [
                'goods_id'   => $goodsId,
                'sku_no'     => $skuNo,
                'sku_name'   => $params['sku_name'] ?? '',
                'cost_price' => $params['cost_price'] ?? 0,
                'sale_price' => $params['sale_price'] ?? 0,
                'status'     => 1,
            ];

            if ($skuId) {
                $sku = GoodsSkuModel::find($skuId);
                $sku->save($skuData);
            } else {
                $sku = GoodsSkuModel::create($skuData);
            }

            return $this->success(['id' => $sku->id], '保存成功');
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    /**
     * 删除 SKU
     * @return Response
     */
    public function deleteSku(): Response
    {
        $skuId = $this->post('sku_id/d', 0);
        if (!$skuId) {
            return $this->error('SKU ID不能为空');
        }

        try {
            GoodsSkuModel::destroy($skuId);
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败：' . $e->getMessage());
        }
    }

    /**
     * 切换状态
     * @return Response
     */
    public function toggleStatus(): Response
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = $this->post('id/d', 0);
        if (!$id) {
            return $this->error('货品ID不能为空');
        }

        $goods = GoodsModel::find($id);
        if (!$goods) {
            return $this->error('货品不存在');
        }

        $newStatus = $goods->status == 1 ? 0 : 1;

        try {
            $goods->save(['status' => $newStatus]);
            return $this->success(['status' => $newStatus], $newStatus == 1 ? '已上架' : '已下架');
        } catch (\Exception $e) {
            return $this->error('操作失败：' . $e->getMessage());
        }
    }
}
