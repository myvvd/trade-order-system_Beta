<?php

namespace app\controller\admin;

use think\facade\View;
use think\facade\Db;
use app\model\Stock as StockModel;
use app\model\StockLog as StockLogModel;
use app\model\Goods as GoodsModel;
use app\service\StockService;

class Stock extends Base
{
    /**
     * 库存列表
     */
    public function list()
    {
        $q = input('q', '');
        $category = input('category', '');
        $status = input('status', ''); // normal/low/out
        $pageSize = input('page_size/d', 15);

        $query = StockModel::with(['goods', 'sku']);
        if ($q) {
            $query->where(function($qry) use ($q){
                $qry->where('goods.name', 'like', "%$q%")
                    ->whereOr('goods.goods_no', 'like', "%$q%");
            });
        }
        if ($category) {
            $query->whereHas('goods', function($qr) use ($category){ $qr->where('category_id', $category); });
        }

        $list = $query->order('id', 'desc')->paginate(['list_rows'=>$pageSize]);

        View::assign(['title'=>'库存管理', 'stocks'=>$list, 'q'=>$q, 'category'=>$category, 'status'=>$status]);
        return View::fetch('admin/stock/list');
    }

    /**
     * 异步返回库存列表 JSON
     */
    public function listJson()
    {
        $q = input('q', '');
        $category = input('category', '');
        $status = input('status', '');
        $page = input('page/d', 1);
        $pageSize = input('page_size/d', 15);

        $query = StockModel::with(['goods', 'sku']);
        if ($q) {
            $query->where(function($qry) use ($q){
                $qry->where('goods.name', 'like', "%$q%")
                    ->whereOr('goods.goods_no', 'like', "%$q%");
            });
        }
        if ($category) {
            $query->whereHas('goods', function($qr) use ($category){ $qr->where('category_id', $category); });
        }

        $list = $query->order('id', 'desc')->paginate(['list_rows'=>$pageSize, 'page'=>$page]);

        $data = [];
        foreach ($list->items() as $s) {
            $data[] = [
                'id' => $s->id,
                'goods_id' => $s->goods_id,
                'goods_no' => $s->goods->goods_no ?? '',
                'goods_name' => $s->goods->name ?? '',
                'sku_id' => $s->sku_id,
                'sku_name' => $s->sku->sku_name ?? '',
                'quantity' => (int)$s->quantity,
                'warn_stock' => (int)($s->goods->warn_stock ?? 0),
            ];
        }

        return $this->success(['list'=>$data, 'total'=>$list->total(), 'page'=>$list->currentPage(), 'page_size'=>$pageSize, 'page_total'=>$list->lastPage()]);
    }

    /**
     * 查看库存变动记录
     */
    public function log($goodsId)
    {
        $goods = GoodsModel::find($goodsId);
        if (!$goods) return $this->redirectError('/admin/stock/list', '货品不存在');

        $logs = StockLogModel::with(['operator'])->where('goods_id', $goodsId)->order('id','desc')->paginate(50);
        View::assign(['title'=>'库存变动记录','goods'=>$goods,'logs'=>$logs]);
        return View::fetch('admin/stock/log');
    }

    /**
     * 手动调整库存页面
     */
    public function adjust()
    {
        $goodsId = input('goods_id/d', 0);
        $goods = $goodsId ? GoodsModel::find($goodsId) : null;
        View::assign(['title'=>'手动调整库存','goods'=>$goods]);
        return View::fetch('admin/stock/adjust');
    }

    /**
     * 执行库存调整
     */
    public function doAdjust()
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $goodsId = $this->post('goods_id/d', 0);
        $skuId = $this->post('sku_id/d', 0);
        $type = $this->post('type', 'set'); // in/out/set
        $qty = (int)$this->post('quantity', 0);
        $reason = $this->post('reason', '');

        if (!$goodsId || !$reason) return $this->error('参数不完整');

        try{
            $stockService = new StockService();
            if ($type === 'in') {
                $stockService->inStock($goodsId, $skuId ?: null, $qty, 'manual_adjust', 0, $reason);
            } elseif ($type === 'out') {
                $stockService->outStock($goodsId, $skuId ?: null, $qty, 'manual_adjust', 0, $reason);
            } else {
                // set: reset to qty
                $cur = $stockService->getStock($goodsId, $skuId ?: null);
                $diff = $qty - $cur;
                if ($diff > 0) $stockService->inStock($goodsId, $skuId ?: null, $diff, 'manual_adjust', 0, $reason);
                else if ($diff < 0) $stockService->outStock($goodsId, $skuId ?: null, -$diff, 'manual_adjust', 0, $reason);
            }

            return $this->success(null, '调整成功');
        }catch(\Exception $e){
            return $this->error('操作失败：' . $e->getMessage());
        }
    }

    /**
     * 库存预警
     */
    public function warning()
    {
        // 简单查询：stock.quantity <= goods.warn_stock 为预警
        $rows = Db::name('stock')->alias('s')
            ->join('goods g', 's.goods_id = g.id')
            ->where('s.quantity', '<=', Db::raw('g.warn_stock'))
            ->field('s.*, g.name as goods_name, g.goods_no, g.warn_stock')
            ->select();

        View::assign(['title'=>'库存预警','rows'=>$rows]);
        return View::fetch('admin/stock/warning');
    }
}
