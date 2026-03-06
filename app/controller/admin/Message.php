<?php

namespace app\controller\admin;

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

        $adminId = $this->getAdminId();
        $data = $this->messageService->getList('admin', $adminId, [
            'page'      => $page,
            'page_size' => $pageSize,
            'category'  => $category,
            'is_read'   => $isRead,
            'is_super'  => $this->isSuperAdmin(),
        ]);

        View::assign([
            'title'        => '站内信',
            'breadcrumb'   => '站内信',
            'current_page' => 'message',
            'messages_json' => json_encode($data['list'], JSON_UNESCAPED_UNICODE),
            'total'        => $data['total'],
            'page'         => $data['page'],
            'page_size'    => $data['page_size'],
            'page_total'   => $data['page_total'],
            'category'     => $category,
            'is_read'      => $isRead,
            'is_super'     => $this->isSuperAdmin(),
        ]);
        return View::fetch('admin/message/index');
    }

    /**
     * 消息详情页
     */
    public function detail($id = 0)
    {
        $adminId = $this->getAdminId();
        $message = $this->messageService->getDetail((int)$id, 'admin', $adminId);
        $prevNext = $this->messageService->getPrevNext((int)$id, 'admin', $adminId);

        View::assign([
            'title'        => '消息详情',
            'breadcrumb'   => '消息详情',
            'current_page' => 'message',
            'message_json' => json_encode($message, JSON_UNESCAPED_UNICODE),
            'prev_msg_json' => json_encode($prevNext['prev'], JSON_UNESCAPED_UNICODE),
            'next_msg_json' => json_encode($prevNext['next'], JSON_UNESCAPED_UNICODE),
        ]);
        return View::fetch('admin/message/detail');
    }

    /**
     * API: 获取未读消息数
     */
    public function unreadCount()
    {
        $adminId = $this->getAdminId();
        $count = $this->messageService->getUnreadCount('admin', $adminId, $this->isSuperAdmin());
        return $this->success(['count' => $count]);
    }

    /**
     * API: 获取最新消息（header 铃铛用）
     */
    public function latest()
    {
        $adminId = $this->getAdminId();
        $list = $this->messageService->getLatest('admin', $adminId, 5, $this->isSuperAdmin());
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
        $adminId = $this->getAdminId();

        if ($id) {
            $this->messageService->markRead($id, 'admin', $adminId);
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

        $adminId = $this->getAdminId();
        $count = $this->messageService->markAllRead('admin', $adminId);

        return $this->success(['count' => $count], '已全部标记为已读');
    }
}
