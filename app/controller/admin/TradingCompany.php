<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\TradingCompany as TradingCompanyModel;
use app\model\Salesman as SalesmanModel;
use think\facade\View;
use think\facade\Db;

/**
 * 贸易公司管理控制器
 */
class TradingCompany extends Base
{
    /**
     * 列表
     */
    public function list()
    {
        $keyword  = input('keyword', '');
        $status   = input('status', '');
        $page     = input('page/d', 1);
        $pageSize = input('page_size/d', 15);

        $query = TradingCompanyModel::where('delete_time', null);
        if ($keyword) {
            $query->whereLike('name|name_en|country', '%' . $keyword . '%');
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        $list = $query->order('id', 'desc')->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);

        // 统计订单数和业务员数
        $ids = array_column($list->items(), 'id');
        $names = array_column($list->items(), 'name');
        $orderCountMap = [];
        $salesmanCountMap = [];
        if (!empty($ids)) {
            // 按公司名称统计订单数
            if (!empty($names)) {
                $rows = Db::name('order')
                    ->whereIn('trading_company', $names)
                    ->field(['trading_company', 'COUNT(*) as cnt'])
                    ->group('trading_company')
                    ->select()->toArray();
                foreach ($rows as $r) {
                    $orderCountMap[$r['trading_company']] = (int)$r['cnt'];
                }
            }
            // 统计业务员数
            $smRows = Db::name('salesman')
                ->whereIn('trading_company_id', $ids)
                ->where('delete_time', null)
                ->field(['trading_company_id', 'COUNT(*) as cnt'])
                ->group('trading_company_id')
                ->select()->toArray();
            foreach ($smRows as $r) {
                $salesmanCountMap[$r['trading_company_id']] = (int)$r['cnt'];
            }
        }
        foreach ($list as $item) {
            $item->order_count   = $orderCountMap[$item->name] ?? 0;
            $item->salesman_count = $salesmanCountMap[$item->id] ?? 0;
        }

        return View::fetch('admin/trading_company/list', [
            'list'         => $list,
            'keyword'      => $keyword,
            'status'       => $status,
            'title'        => '贸易公司管理',
            'current_page' => 'trading_company',
        ]);
    }

    /**
     * 新建表单
     */
    public function create()
    {
        return View::fetch('admin/trading_company/form', [
            'item'         => null,
            'title'        => '新增贸易公司',
            'current_page' => 'trading_company',
        ]);
    }

    /**
     * 保存新建
     */
    public function save()
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }
        $data = $this->post();
        if (empty($data['name'])) {
            return $this->error('公司名称不能为空');
        }
        $tc = new TradingCompanyModel();
        $tc->name    = trim($data['name']);
        $tc->name_en = trim($data['name_en'] ?? '');
        $tc->country = trim($data['country'] ?? '');
        $tc->address = trim($data['address'] ?? '');
        $tc->remark  = trim($data['remark'] ?? '');
        $tc->status  = isset($data['status']) ? (int)$data['status'] : 1;
        $tc->save();
        return $this->success(['id' => $tc->id], '保存成功');
    }

    /**
     * 编辑表单
     */
    public function edit()
    {
        $id   = input('id/d', 0);
        $item = TradingCompanyModel::where('delete_time', null)->find($id);
        if (!$item) {
            return redirect('/admin/trading_company/list');
        }
        return View::fetch('admin/trading_company/form', [
            'item'         => $item->toArray(),
            'title'        => '编辑贸易公司',
            'current_page' => 'trading_company',
        ]);
    }

    /**
     * 更新
     */
    public function update()
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }
        $id   = input('id/d', 0);
        $data = $this->post();
        $item = TradingCompanyModel::where('delete_time', null)->find($id);
        if (!$item) {
            return $this->error('记录不存在');
        }
        if (empty($data['name'])) {
            return $this->error('公司名称不能为空');
        }
        $item->name    = trim($data['name']);
        $item->name_en = trim($data['name_en'] ?? '');
        $item->country = trim($data['country'] ?? '');
        $item->address = trim($data['address'] ?? '');
        $item->remark  = trim($data['remark'] ?? '');
        $item->status  = isset($data['status']) ? (int)$data['status'] : 1;
        $item->save();
        return $this->success([], '更新成功');
    }

    /**
     * 删除（软删除）
     */
    public function delete()
    {
        $id   = input('id/d', 0);
        $item = TradingCompanyModel::where('delete_time', null)->find($id);
        if (!$item) {
            return $this->error('记录不存在');
        }
        $item->delete_time = time();
        $item->save();
        return $this->success([], '删除成功');
    }

    /**
     * 切换状态
     */
    public function toggle()
    {
        $id   = input('id/d', 0);
        $item = TradingCompanyModel::where('delete_time', null)->find($id);
        if (!$item) {
            return $this->error('记录不存在');
        }
        $item->status = $item->status == 1 ? 0 : 1;
        $item->save();
        return $this->success(['status' => $item->status], '操作成功');
    }

    /**
     * API：返回启用的贸易公司列表（供下拉使用）
     */
    public function apiList()
    {
        $list = TradingCompanyModel::where('delete_time', null)
            ->where('status', 1)
            ->field('id,name,name_en')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        return $this->success($list);
    }
}
