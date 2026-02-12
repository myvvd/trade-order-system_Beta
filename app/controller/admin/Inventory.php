<?php
namespace app\controller\admin;

use think\facade\View;
use think\facade\Db;
use app\model\Inventory as InventoryModel;
use app\model\InventoryItem as InventoryItemModel;
use app\model\Goods as GoodsModel;
use app\service\StockService;

class Inventory extends Base
{
    public function list()
    {
        // 初始化测试数据
        $this->initTestData();
        
        $status = input('status', '');
        $pageSize = input('page_size/d', 15);
        
        $query = InventoryModel::order('id', 'desc');
        if ($status !== '') {
            $query->where('status', $status);
        }
        
        $list = $query->paginate([
            'list_rows' => $pageSize,
            'page'      => input('page/d', 1),
        ]);

        // 处理列表数据，加载关联的items
        $listData = [];
        foreach ($list as $item) {
            $data = $item->toArray();
            // 计算项数
            $data['item_count'] = Db::name('inventory_item')->where('inventory_id', $item->id)->count();
            // 格式化时间
            $data['create_time'] = $data['create_time'] ? date('Y-m-d H:i', strtotime($data['create_time'])) : '-';
            $data['check_time'] = $data['check_time'] ? date('Y-m-d H:i', strtotime($data['check_time'])) : '-';
            $listData[] = $data;
        }
        
        View::assign([
            'title'             => '盘点单列表',
            'list'              => $listData, // 传递处理后的数据数组用于显示
            'paginate'          => $list, // 传递分页对象用于渲染分页
            'list_json'         => json_encode($listData, JSON_UNESCAPED_UNICODE),
            'total'             => $list->total(),
            'status'            => $status,
            'breadcrumb'        => '库存盘点',
            'current_page'      => 'inventory',
        ]);
        return View::fetch('admin/inventory/list');
    }

    public function create()
    {
        View::assign(['title'=>'创建盘点单']);
        return View::fetch('admin/inventory/form');
    }

    public function save()
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $data = $this->post();
        $range = $data['range'] ?? 'all';
        $goods_ids = $data['goods_ids'] ?? [];
        $remark = $data['remark'] ?? '';

        return Db::transaction(function() use($range, $goods_ids, $remark){
            $inv = new InventoryModel();
            $inv->save(['status'=>0,'range'=>$range,'remark'=>$remark,'creator_id'=>$this->admin->id ?? null]);

            // 给定货品或范围处理：若 range == 'goods' 使用 goods_ids; if 'category' 未实现细化，可扩展
            $items = [];
            if ($range === 'goods' && is_array($goods_ids)) {
                foreach ($goods_ids as $gid) {
                    $g = GoodsModel::find($gid);
                    if ($g) {
                        $items[] = ['inventory_id'=>$inv->id,'goods_id'=>$g->id,'sku_id'=>0,'system_qty'=>0];
                    }
                }
            } else {
                // 默认全部：不逐条写入，前端在录入页面可加载 stock 列表
            }

            if (!empty($items)) {
                Db::name('inventory_item')->insertAll($items);
            }

            return $this->success(['id'=>$inv->id], '已创建盘点单');
        });
    }

    public function edit($id)
    {
        $inv = InventoryModel::with(['items.goods','items.sku'])->find($id);
        if (!$inv) return $this->redirectError('/admin/inventory/list','盘点单不存在');
        if ($inv->status != 0) return $this->redirectError('/admin/inventory/list','只能在进行中编辑');

        View::assign(['title'=>'录入盘点数量','inv'=>$inv]);
        return View::fetch('admin/inventory/check');
    }

    public function update($id)
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $items = $this->post('items/a', []);

        foreach ($items as $it) {
            $row = InventoryItemModel::find($it['id']);
            if ($row) {
                $row->counted_qty = (int)($it['counted_qty'] ?? 0);
                $row->save();
            }
        }

        return $this->success(null, '已保存盘点结果');
    }

    public function complete($id)
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $inv = InventoryModel::with('items')->find($id);
        if (!$inv) return $this->error('盘点单不存在');
        if ($inv->status != 0) return $this->error('盘点单已完成或不可操作');

        $stockService = new StockService();

        return Db::transaction(function() use($inv, $stockService){
            $summary = ['plus'=>0,'minus'=>0];
            foreach ($inv->items as $it) {
                $system = (int)($it->system_qty ?? 0);
                $counted = (int)($it->counted_qty ?? 0);
                $diff = $counted - $system;
                if ($diff === 0) continue;

                if ($diff > 0) {
                    $stockService->inStock($it->goods_id, $it->sku_id ?: null, $diff, 'inventory', $inv->id, '盘点调整-盘盈');
                    $summary['plus'] += $diff;
                } else {
                    $stockService->outStock($it->goods_id, $it->sku_id ?: null, -$diff, 'inventory', $inv->id, '盘点调整-盘亏');
                    $summary['minus'] += -$diff;
                }
            }

            $inv->status = 1;
            $inv->completed_time = date('Y-m-d H:i:s');
            $inv->save();

            return $this->success(['summary'=>$summary], '盘点完成');
        });
    }

    public function detail($id)
    {
        $inv = InventoryModel::with(['items.goods','items.sku','creator'])->find($id);
        if (!$inv) return $this->redirectError('/admin/inventory/list','盘点单不存在');
        View::assign(['title'=>'盘点详情','inv'=>$inv]);
        return View::fetch('admin/inventory/detail');
    }
    
    /**
     * 初始化测试数据
     */
    private function initTestData()
    {
        // 检查是否已经有数据
        if (Db::name('inventory')->count() > 0) {
            return;
        }
        
        // 创建测试数据
        $testData = [
            [
                'inventory_no' => 'INV' . date('Ymd') . '001',
                'status' => 2, // 已完成
                'remark' => '年终库存盘点',
                'creator_id' => 1,
                'create_time' => date('Y-m-d H:i:s', strtotime('-7 days')),
                'check_time' => date('Y-m-d H:i:s', strtotime('-5 days')),
            ],
            [
                'inventory_no' => 'INV' . date('Ymd') . '002', 
                'status' => 1, // 进行中
                'remark' => '月度库存检查',
                'creator_id' => 1,
                'create_time' => date('Y-m-d H:i:s', strtotime('-3 days')),
            ],
            [
                'inventory_no' => 'INV' . date('Ymd') . '003',
                'status' => 0, // 草稿
                'remark' => '商品A类专项盘点', 
                'creator_id' => 1,
                'create_time' => date('Y-m-d H:i:s', strtotime('-1 day')),
            ],
            [
                'inventory_no' => 'INV' . date('Ymd') . '004',
                'status' => 2, // 已完成
                'remark' => '新品入库盘点',
                'creator_id' => 1,
                'create_time' => date('Y-m-d H:i:s', strtotime('-10 days')),
                'check_time' => date('Y-m-d H:i:s', strtotime('-8 days')),
            ],
            [
                'inventory_no' => 'INV' . date('Ymd') . '005',
                'status' => 1, // 进行中
                'remark' => '仓库B区盘点',
                'creator_id' => 1,
                'create_time' => date('Y-m-d H:i:s'),
            ],
        ];
        
        foreach ($testData as $data) {
            $id = Db::name('inventory')->insertGetId($data);
            
            // 为每个盘点单添加一些盘点项
            $items = [
                [
                    'inventory_id' => $id,
                    'goods_id' => 1,
                    'sku_id' => null,
                    'system_quantity' => rand(50, 200),
                    'actual_quantity' => rand(45, 205),
                    'diff_quantity' => 0, // 会在保存时计算
                    'remark' => '盘点记录'
                ],
                [
                    'inventory_id' => $id,
                    'goods_id' => 2, 
                    'sku_id' => null,
                    'system_quantity' => rand(30, 150),
                    'actual_quantity' => rand(25, 155),
                    'diff_quantity' => 0,
                    'remark' => '盘点记录'
                ]
            ];
            
            foreach ($items as &$item) {
                $item['diff_quantity'] = $item['actual_quantity'] - $item['system_quantity'];
            }
            
            Db::name('inventory_item')->insertAll($items);
        }
    }

    public function delete($id)
    {
        $inv = InventoryModel::find($id);
        if (!$inv) return $this->error('盘点单不存在');
        if ($inv->status != 0) return $this->error('只能删除未完成的盘点单');
        Db::transaction(function() use($inv){
            Db::name('inventory_item')->where('inventory_id', $inv->id)->delete();
            $inv->delete();
        });
        return $this->success(null, '已删除');
    }}
