<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\Salesman as SalesmanModel;
use app\model\TradingCompany as TradingCompanyModel;
use think\facade\View;
use think\facade\Db;

/**
 * 业务员管理控制器
 */
class Salesman extends Base
{
    /**
     * 列表
     */
    public function list()
    {
        $keyword            = input('keyword', '');
        $trading_company_id = input('trading_company_id/d', 0);
        $status             = input('status', '');
        $page               = input('page/d', 1);
        $pageSize           = input('page_size/d', 15);

        $query = SalesmanModel::where('delete_time', null);
        if ($keyword) {
            $query->whereLike('name|phone', '%' . $keyword . '%');
        }
        if ($trading_company_id) {
            $query->where('trading_company_id', $trading_company_id);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        $list = $query->order('id', 'desc')->paginate([
            'list_rows' => $pageSize,
            'page'      => $page,
        ]);

        // 统计每位业务员的订单数（按姓名匹配）
        $names = array_column($list->items(), 'name');
        $orderCountMap = [];
        if (!empty($names)) {
            $rows = Db::name('order')
                ->whereIn('salesman', $names)
                ->field(['salesman', 'COUNT(*) as cnt'])
                ->group('salesman')
                ->select()->toArray();
            foreach ($rows as $r) {
                $orderCountMap[$r['salesman']] = (int)$r['cnt'];
            }
        }
        foreach ($list as $item) {
            $item->order_count = $orderCountMap[$item->name] ?? 0;
        }

        // 贸易公司列表（用于筛选下拉）
        $tradingCompanies = TradingCompanyModel::where('delete_time', null)
            ->where('status', 1)
            ->field('id,name')
            ->order('id', 'asc')
            ->select();

        // 为每个业务员附加贸易公司名称
        $tcMap = [];
        foreach ($tradingCompanies as $tc) {
            $tcMap[$tc->id] = $tc->name;
        }
        foreach ($list as $item) {
            $item->trading_company_name = $tcMap[$item->trading_company_id] ?? '-';
        }

        return View::fetch('admin/salesman/list', [
            'list'               => $list,
            'keyword'            => $keyword,
            'trading_company_id' => $trading_company_id,
            'status'             => $status,
            'tradingCompanies'   => $tradingCompanies,
            'title'              => '业务员管理',
            'current_page'       => 'salesman',
        ]);
    }

    /**
     * 新建表单
     */
    public function create()
    {
        $tradingCompanies = TradingCompanyModel::where('delete_time', null)
            ->where('status', 1)
            ->field('id,name')
            ->order('id', 'asc')
            ->select();

        return View::fetch('admin/salesman/form', [
            'item'             => null,
            'tradingCompanies' => $tradingCompanies,
            'title'            => '新增业务员',
            'current_page'     => 'salesman',
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
            return $this->error('姓名不能为空');
        }
        $sm = new SalesmanModel();
        $sm->name               = trim($data['name']);
        $sm->phone              = trim($data['phone'] ?? '');
        $sm->trading_company_id = !empty($data['trading_company_id']) ? (int)$data['trading_company_id'] : null;
        $sm->remark             = trim($data['remark'] ?? '');
        $sm->status             = isset($data['status']) ? (int)$data['status'] : 1;
        $sm->save();
        return $this->success(['id' => $sm->id], '保存成功');
    }

    /**
     * 编辑表单
     */
    public function edit()
    {
        $id   = input('id/d', 0);
        $item = SalesmanModel::where('delete_time', null)->find($id);
        if (!$item) {
            return redirect('/admin/salesman/list');
        }
        $tradingCompanies = TradingCompanyModel::where('delete_time', null)
            ->where('status', 1)
            ->field('id,name')
            ->order('id', 'asc')
            ->select();

        return View::fetch('admin/salesman/form', [
            'item'             => $item->toArray(),
            'tradingCompanies' => $tradingCompanies,
            'title'            => '编辑业务员',
            'current_page'     => 'salesman',
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
        $item = SalesmanModel::where('delete_time', null)->find($id);
        if (!$item) {
            return $this->error('记录不存在');
        }
        if (empty($data['name'])) {
            return $this->error('姓名不能为空');
        }
        $item->name               = trim($data['name']);
        $item->phone              = trim($data['phone'] ?? '');
        $item->trading_company_id = !empty($data['trading_company_id']) ? (int)$data['trading_company_id'] : null;
        $item->remark             = trim($data['remark'] ?? '');
        $item->status             = isset($data['status']) ? (int)$data['status'] : 1;
        $item->save();
        return $this->success([], '更新成功');
    }

    /**
     * 删除（软删除）
     */
    public function delete()
    {
        $id   = input('id/d', 0);
        $item = SalesmanModel::where('delete_time', null)->find($id);
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
        $item = SalesmanModel::where('delete_time', null)->find($id);
        if (!$item) {
            return $this->error('记录不存在');
        }
        $item->status = $item->status == 1 ? 0 : 1;
        $item->save();
        return $this->success(['status' => $item->status], '操作成功');
    }

    /**
     * API：返回启用的业务员列表（供下拉使用）
     */
    public function apiList()
    {
        $tradingCompanyId = input('trading_company_id/d', 0);
        $query = SalesmanModel::where('delete_time', null)->where('status', 1);
        if ($tradingCompanyId) {
            $query->where('trading_company_id', $tradingCompanyId);
        }
        $list = $query->field('id,name,phone,trading_company_id')
            ->order('id', 'asc')
            ->select()->toArray();
        return $this->success($list);
    }
}
