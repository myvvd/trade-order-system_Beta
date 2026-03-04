<?php

namespace app\controller\supplier;

use think\facade\View;
use app\service\MessageService;

class Message extends Base
{
    protected MessageService $messageService;

    protected function initialize()
    {
        parent::initialize();
        $this->messageService = new MessageService();
    }

    /**
     * 消息列表页
     */
    public function index()
    {
        $category = input('category', '');
        $isRead   = input('is_read', '');
        $page     = input('page/d', 1);
        $pageSize = input('page_size/d', 15);

        $supplierId = $this->getSupplierId();
        $data = $this->messageService->getList('supplier', $supplierId, [
            'page'      => $page,
            'page_size' => $pageSize,
            'category'  => $category,
            'is_read'   => $isRead,
        ]);

        View::assign([
            'title'         => '站内信',
            'current_page'  => 'message',
            'messages'      => $data['list'],
            'total'         => $data['total'],
            'page'          => $data['page'],
            'page_size'     => $data['page_size'],
            'page_total'    => $data['page_total'],
            'category'      => $category,
            'is_read'       => $isRead,
        ]);
        return View::fetch('supplier/message/index');
    }

    /**
     * 消息详情页
     */
    public function detail($id = 0)
    {
        $supplierId = $this->getSupplierId();
        $message = $this->messageService->getDetail((int)$id, 'supplier', $supplierId);
        $prevNext = $this->messageService->getPrevNext((int)$id, 'supplier', $supplierId);

        View::assign([
            'title'         => '消息详情',
            'current_page'  => 'message',
            'message'       => $message,
            'prev_msg'      => $prevNext['prev'],
            'next_msg'      => $prevNext['next'],
        ]);
        return View::fetch('supplier/message/detail');
    }

    /**
     * API: 获取未读消息数
     */
    public function unreadCount()
    {
        $supplierId = $this->getSupplierId();
        $count = $this->messageService->getUnreadCount('supplier', $supplierId);
        return $this->success(['count' => $count]);
    }

    /**
     * API: 获取最新消息
     */
    public function latest()
    {
        $supplierId = $this->getSupplierId();
        $list = $this->messageService->getLatest('supplier', $supplierId, 5);
        return $this->success(['list' => $list]);
    }

    /**
     * API: 标记消息已读
     */
    public function markRead()
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = input('post.id/d', 0);
        $supplierId = $this->getSupplierId();

        if ($id) {
            $this->messageService->markRead($id, 'supplier', $supplierId);
        }

        return $this->success(null, '标记成功');
    }

    /**
     * API: 全部标记已读
     */
    public function markAllRead()
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $supplierId = $this->getSupplierId();
        $count = $this->messageService->markAllRead('supplier', $supplierId);
        return $this->success(['count' => $count], '已全部标记为已读');
    }
}
