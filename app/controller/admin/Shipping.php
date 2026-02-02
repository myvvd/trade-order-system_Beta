<?php
namespace app\controller\admin;

use think\facade\View;
use think\facade\Db;
use app\model\Shipping as ShippingModel;
use app\model\ShippingItem as ShippingItemModel;
use app\service\ShippingService;
use app\model\Customer as CustomerModel;

class Shipping extends Base
{
    public function list()
    {
        $pageSize = input('page_size/d', 15);
        $query = ShippingModel::with('customer')->order('id','desc');
        $page = $this->paginate($query, $pageSize);
        View::assign(['title'=>'出货单列表','list'=>$page['list'],'total'=>$page['total'],'page'=>$page['page'],'page_size'=>$page['page_size'],'page_total'=>$page['page_total']]);
        return View::fetch('admin/shipping/list');
    }

    public function create()
    {
        // 简单加载客户列表
        $customers = CustomerModel::order('id','desc')->select();
        View::assign(['title'=>'创建出货单','customers'=>$customers]);
        return View::fetch('admin/shipping/form');
    }

    public function save()
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $data = $this->post();
        $data['creator_id'] = $this->admin->id ?? 0;

        try{
            $svc = new ShippingService();
            $ship = $svc->create($data);
            return $this->success($ship->id, '已创建出货单');
        }catch(\Exception $e){
            return $this->error('创建失败：'.$e->getMessage());
        }
    }

    public function edit($id)
    {
        $ship = ShippingModel::with(['items.order','items.orderItem'])->find($id);
        if (!$ship) return $this->redirectError('/admin/shipping/list','出货单不存在');
        View::assign(['title'=>'编辑出货单','ship'=>$ship]);
        return View::fetch('admin/shipping/form');
    }

    public function update($id)
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $data = $this->post();
        $ship = ShippingModel::find($id);
        if (!$ship) return $this->error('出货单不存在');

        Db::transaction(function() use($ship, $data){
            $ship->logistics_company = $data['logistics_company'] ?? $ship->logistics_company;
            $ship->logistics_no = $data['logistics_no'] ?? $ship->logistics_no;
            $ship->ship_date = $data['ship_date'] ?? $ship->ship_date;
            $ship->remark = $data['remark'] ?? $ship->remark;
            $ship->save();

            // items 更新略（可按需实现）
        });

        return $this->success(null, '已更新');
    }

    public function delete($id)
    {
        $ship = ShippingModel::find($id);
        if (!$ship) return $this->error('出货单不存在');
        if ($ship->status == 1) return $this->error('已发货的出货单不可删除');
        Db::transaction(function() use($ship){
            Db::name('shipping_item')->where('shipping_id', $ship->id)->delete();
            $ship->delete();
        });
        return $this->success(null, '已删除');
    }

    public function detail($id)
    {
        $ship = ShippingModel::with(['items.order','items.orderItem','customer','creator'])->find($id);
        if (!$ship) return $this->redirectError('/admin/shipping/list','出货单不存在');
        View::assign(['title'=>'出货单详情','ship'=>$ship]);
        return View::fetch('admin/shipping/detail');
    }

    // 确认发货
    public function ship($id)
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        try{
            $svc = new ShippingService();
            $svc->ship((int)$id, $this->admin->id ?? 0);
            return $this->success(null, '发货成功');
        }catch(\Exception $e){
            return $this->error('发货失败：'.$e->getMessage());
        }
    }
}
