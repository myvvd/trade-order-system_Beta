<?php

namespace app\service;

use think\facade\Db;
use app\model\Message;
use app\model\AdminUser;
use app\model\AdminRole;
use app\model\Order;
use app\model\OrderDispatch;
use app\model\Supplier;

/**
 * 站内信消息服务
 */
class MessageService
{
    /**
     * 发送消息给指定用户
     */
    public function send(array $data): Message
    {
        $msg = new Message();
        $msg->save([
            'title'         => $data['title'] ?? '',
            'content'       => $data['content'] ?? '',
            'category'      => $data['category'] ?? Message::CATEGORY_SYSTEM,
            'sender_type'   => $data['sender_type'] ?? Message::SENDER_SYSTEM,
            'sender_id'     => $data['sender_id'] ?? null,
            'receiver_type' => $data['receiver_type'] ?? Message::RECEIVER_ADMIN,
            'receiver_id'   => $data['receiver_id'],
            'order_id'      => $data['order_id'] ?? null,
            'dispatch_id'   => $data['dispatch_id'] ?? null,
            'is_read'       => 0,
        ]);
        return $msg;
    }

    /**
     * 批量发送消息给多个用户
     */
    public function sendBatch(array $baseData, array $receiverIds, string $receiverType = Message::RECEIVER_ADMIN): void
    {
        foreach ($receiverIds as $receiverId) {
            $this->send(array_merge($baseData, [
                'receiver_type' => $receiverType,
                'receiver_id'   => $receiverId,
            ]));
        }
    }

    /**
     * 发送消息给有指定权限的所有管理员
     * @param array $data 消息数据
     * @param string $permission 权限标识，如 'order.list'
     * @param int|null $excludeAdminId 要排除的管理员ID（通常是操作者自己）
     */
    public function sendToAdminsWithPermission(array $data, string $permission, ?int $excludeAdminId = null): void
    {
        $adminIds = $this->getAdminIdsByPermission($permission, $excludeAdminId);
        if (!empty($adminIds)) {
            $this->sendBatch($data, $adminIds, Message::RECEIVER_ADMIN);
        }
    }

    /**
     * 发送消息给订单关联的供应商
     */
    public function sendToOrderSupplier(array $data, int $orderId): void
    {
        $supplierIds = OrderDispatch::where('order_id', $orderId)
            ->where('status', '<>', OrderDispatch::STATUS_REJECTED)
            ->group('supplier_id')
            ->column('supplier_id');

        if (!empty($supplierIds)) {
            $this->sendBatch($data, $supplierIds, Message::RECEIVER_SUPPLIER);
        }
    }

    /**
     * 发送消息给指定派单的供应商
     */
    public function sendToDispatchSupplier(array $data, int $dispatchId): void
    {
        $dispatch = OrderDispatch::find($dispatchId);
        if ($dispatch && $dispatch->supplier_id) {
            $this->send(array_merge($data, [
                'receiver_type' => Message::RECEIVER_SUPPLIER,
                'receiver_id'   => $dispatch->supplier_id,
            ]));
        }
    }

    /**
     * 获取用户的消息列表
     */
    public function getList(string $receiverType, int $receiverId, array $params = []): array
    {
        $page     = (int)($params['page'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 15);
        $category = $params['category'] ?? '';
        $isRead   = $params['is_read'] ?? '';
        $isSuper  = $params['is_super'] ?? true;

        $query = Message::where('receiver_type', $receiverType)
            ->where('receiver_id', $receiverId);

        // 非超级管理员只显示订单创建和订单修改类型 且 关联订单是自己创建的
        if (!$isSuper) {
            $allowedCategories = [
                Message::CATEGORY_ORDER_CREATE,
                Message::CATEGORY_ORDER_EDIT,
            ];
            if ($category !== '' && in_array($category, $allowedCategories)) {
                $query->where('category', $category);
            } elseif ($category !== '' && !in_array($category, $allowedCategories)) {
                return [
                    'list'       => [],
                    'total'      => 0,
                    'page'       => $page,
                    'page_size'  => $pageSize,
                    'page_total' => 0,
                ];
            } else {
                $query->whereIn('category', $allowedCategories);
            }
            // 只显示自己创建的订单相关消息
            $query->where(function ($q) use ($receiverId) {
                $q->whereNull('order_id')
                  ->whereOr('order_id', 'in', function ($sub) use ($receiverId) {
                      $sub->name('order')->field('id')->where('creator_id', $receiverId);
                  });
            });
        } elseif ($category !== '') {
            $query->where('category', $category);
        }

        if ($isRead !== '') {
            $query->where('is_read', (int)$isRead);
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 补充分类标签
        foreach ($list as &$item) {
            $item['category_label'] = Message::getCategoryLabel($item['category']);
        }

        return [
            'list'       => $list,
            'total'      => $total,
            'page'       => $page,
            'page_size'  => $pageSize,
            'page_total' => ceil($total / $pageSize),
        ];
    }

    /**
     * 获取单条消息详情
     */
    public function getDetail(int $id, string $receiverType, int $receiverId): ?array
    {
        $msg = Message::where('id', $id)
            ->where('receiver_type', $receiverType)
            ->where('receiver_id', $receiverId)
            ->find();

        if (!$msg) {
            return null;
        }

        // 标记已读
        if (!$msg->is_read) {
            $msg->is_read = 1;
            $msg->read_time = date('Y-m-d H:i:s');
            $msg->save();
        }

        $data = $msg->toArray();
        $data['category_label'] = Message::getCategoryLabel($data['category']);

        return $data;
    }

    /**
     * 获取未读消息数
     */
    public function getUnreadCount(string $receiverType, int $receiverId, bool $isSuper = true): int
    {
        $query = Message::where('receiver_type', $receiverType)
            ->where('receiver_id', $receiverId)
            ->where('is_read', 0);

        if (!$isSuper) {
            $query->whereIn('category', [
                Message::CATEGORY_ORDER_CREATE,
                Message::CATEGORY_ORDER_EDIT,
            ]);
            $query->where(function ($q) use ($receiverId) {
                $q->whereNull('order_id')
                  ->whereOr('order_id', 'in', function ($sub) use ($receiverId) {
                      $sub->name('order')->field('id')->where('creator_id', $receiverId);
                  });
            });
        }

        return (int)$query->count();
    }

    /**
     * 获取最新消息（header 铃铛 popover 用）
     */
    public function getLatest(string $receiverType, int $receiverId, int $limit = 5, bool $isSuper = true): array
    {
        $query = Message::where('receiver_type', $receiverType)
            ->where('receiver_id', $receiverId);

        if (!$isSuper) {
            $query->whereIn('category', [
                Message::CATEGORY_ORDER_CREATE,
                Message::CATEGORY_ORDER_EDIT,
            ]);
            $query->where(function ($q) use ($receiverId) {
                $q->whereNull('order_id')
                  ->whereOr('order_id', 'in', function ($sub) use ($receiverId) {
                      $sub->name('order')->field('id')->where('creator_id', $receiverId);
                  });
            });
        }

        $list = $query->order('is_read asc, id desc')
            ->limit($limit)
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['category_label'] = Message::getCategoryLabel($item['category']);
        }

        return $list;
    }

    /**
     * 标记消息已读
     */
    public function markRead(int $id, string $receiverType, int $receiverId): bool
    {
        $msg = Message::where('id', $id)
            ->where('receiver_type', $receiverType)
            ->where('receiver_id', $receiverId)
            ->find();

        if (!$msg) return false;

        $msg->is_read = 1;
        $msg->read_time = date('Y-m-d H:i:s');
        $msg->save();

        return true;
    }

    /**
     * 全部标记已读
     */
    public function markAllRead(string $receiverType, int $receiverId): int
    {
        return Message::where('receiver_type', $receiverType)
            ->where('receiver_id', $receiverId)
            ->where('is_read', 0)
            ->update([
                'is_read'   => 1,
                'read_time' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * 获取上一条/下一条消息（用于详情页导航）
     */
    public function getPrevNext(int $id, string $receiverType, int $receiverId): array
    {
        // 上一条（id 更大的）：优先未读
        $prevUnread = Message::where('receiver_type', $receiverType)
            ->where('receiver_id', $receiverId)
            ->where('id', '>', $id)
            ->where('is_read', 0)
            ->order('id', 'asc')
            ->field('id, title, is_read, category')
            ->find();

        $prev = $prevUnread ?: Message::where('receiver_type', $receiverType)
            ->where('receiver_id', $receiverId)
            ->where('id', '>', $id)
            ->order('id', 'asc')
            ->field('id, title, is_read, category')
            ->find();

        // 下一条（id 更小的）：优先未读
        $nextUnread = Message::where('receiver_type', $receiverType)
            ->where('receiver_id', $receiverId)
            ->where('id', '<', $id)
            ->where('is_read', 0)
            ->order('id', 'desc')
            ->field('id, title, is_read, category')
            ->find();

        $next = $nextUnread ?: Message::where('receiver_type', $receiverType)
            ->where('receiver_id', $receiverId)
            ->where('id', '<', $id)
            ->order('id', 'desc')
            ->field('id, title, is_read, category')
            ->find();

        return [
            'prev' => $prev ? $prev->toArray() : null,
            'next' => $next ? $next->toArray() : null,
        ];
    }

    // ============================================================
    // 订单流程消息发送方法
    // ============================================================

    /**
     * 订单创建通知
     */
    public function notifyOrderCreated(Order $order, ?int $adminId = null): void
    {
        $orderNo = $order->order_no;
        $data = [
            'title'       => "新订单 #{$orderNo} 已创建",
            'content'     => "新订单 #{$orderNo} 已创建，当前状态为待派单，请及时处理。",
            'category'    => Message::CATEGORY_ORDER_CREATE,
            'sender_type' => Message::SENDER_SYSTEM,
            'sender_id'   => $adminId,
            'order_id'    => $order->id,
        ];
        // 通知所有有订单权限的管理员（排除创建者自己）
        $this->sendToAdminsWithPermission($data, 'order', $adminId);
    }

    /**
     * 订单修改通知
     */
    public function notifyOrderUpdated(Order $order, ?int $adminId = null): void
    {
        $orderNo = $order->order_no;
        $adminName = $adminId ? (AdminUser::where('id', $adminId)->value('real_name') ?: '管理员') : '系统';
        $data = [
            'title'       => "订单 #{$orderNo} 已被修改",
            'content'     => "{$adminName} 修改了订单 #{$orderNo} 的信息，请注意查看最新内容。",
            'category'    => Message::CATEGORY_ORDER_EDIT,
            'sender_type' => Message::SENDER_ADMIN,
            'sender_id'   => $adminId,
            'order_id'    => $order->id,
        ];
        // 通知有订单权限的管理员
        $this->sendToAdminsWithPermission($data, 'order', $adminId);
        // 如果有关联供应商，也通知
        $this->sendToOrderSupplier($data, $order->id);
    }

    /**
     * 订单取消通知
     */
    public function notifyOrderCanceled(Order $order, ?int $adminId = null): void
    {
        $orderNo = $order->order_no;
        $data = [
            'title'       => "订单 #{$orderNo} 已取消",
            'content'     => "订单 #{$orderNo} 已被取消。",
            'category'    => Message::CATEGORY_ORDER_CANCEL,
            'sender_type' => Message::SENDER_ADMIN,
            'sender_id'   => $adminId,
            'order_id'    => $order->id,
        ];
        $this->sendToAdminsWithPermission($data, 'order', $adminId);
        $this->sendToOrderSupplier($data, $order->id);
    }

    /**
     * 派单创建通知 — 通知供应商有新派单
     */
    public function notifyDispatchCreated(OrderDispatch $dispatch, ?int $adminId = null): void
    {
        $order = Order::find($dispatch->order_id);
        $orderNo = $order ? $order->order_no : $dispatch->order_id;
        $supplier = Supplier::find($dispatch->supplier_id);
        $supplierName = $supplier ? $supplier->name : '供应商';

        // 通知被派单的供应商
        $this->send([
            'title'         => "您有新的派单 - 订单 #{$orderNo}",
            'content'       => "您收到一条新的派单，关联订单 #{$orderNo}，请尽快确认接单。",
            'category'      => Message::CATEGORY_ORDER_DISPATCH,
            'sender_type'   => Message::SENDER_ADMIN,
            'sender_id'     => $adminId,
            'receiver_type' => Message::RECEIVER_SUPPLIER,
            'receiver_id'   => $dispatch->supplier_id,
            'order_id'      => $dispatch->order_id,
            'dispatch_id'   => $dispatch->id,
        ]);

        // 通知有订单权限的管理员
        $data = [
            'title'       => "订单 #{$orderNo} 已派单给 {$supplierName}",
            'content'     => "订单 #{$orderNo} 已派单给供应商「{$supplierName}」，等待供应商确认。",
            'category'    => Message::CATEGORY_ORDER_DISPATCH,
            'sender_type' => Message::SENDER_ADMIN,
            'sender_id'   => $adminId,
            'order_id'    => $dispatch->order_id,
            'dispatch_id' => $dispatch->id,
        ];
        $this->sendToAdminsWithPermission($data, 'order', $adminId);
    }

    /**
     * 供应商确认接单通知
     */
    public function notifyDispatchConfirmed(OrderDispatch $dispatch, ?int $operatorId = null): void
    {
        $order = Order::find($dispatch->order_id);
        $orderNo = $order ? $order->order_no : $dispatch->order_id;
        $supplier = Supplier::find($dispatch->supplier_id);
        $supplierName = $supplier ? $supplier->name : '供应商';

        $data = [
            'title'       => "供应商「{$supplierName}」已确认订单 #{$orderNo}",
            'content'     => "供应商「{$supplierName}」已确认接单，订单 #{$orderNo} 进入制作中状态。",
            'category'    => Message::CATEGORY_ORDER_CONFIRM,
            'sender_type' => Message::SENDER_SUPPLIER,
            'sender_id'   => $dispatch->supplier_id,
            'order_id'    => $dispatch->order_id,
            'dispatch_id' => $dispatch->id,
        ];
        $this->sendToAdminsWithPermission($data, 'order');
    }

    /**
     * 供应商拒绝接单通知
     */
    public function notifyDispatchRejected(OrderDispatch $dispatch, ?int $operatorId = null): void
    {
        $order = Order::find($dispatch->order_id);
        $orderNo = $order ? $order->order_no : $dispatch->order_id;
        $supplier = Supplier::find($dispatch->supplier_id);
        $supplierName = $supplier ? $supplier->name : '供应商';

        $data = [
            'title'       => "供应商「{$supplierName}」拒绝了订单 #{$orderNo}",
            'content'     => "供应商「{$supplierName}」拒绝了订单 #{$orderNo} 的派单，请重新安排供应商。",
            'category'    => Message::CATEGORY_ORDER_REJECT,
            'sender_type' => Message::SENDER_SUPPLIER,
            'sender_id'   => $dispatch->supplier_id,
            'order_id'    => $dispatch->order_id,
            'dispatch_id' => $dispatch->id,
        ];
        $this->sendToAdminsWithPermission($data, 'order');
    }

    /**
     * 供应商发货通知
     */
    public function notifyDispatchShipped(OrderDispatch $dispatch, ?int $operatorId = null): void
    {
        $order = Order::find($dispatch->order_id);
        $orderNo = $order ? $order->order_no : $dispatch->order_id;
        $supplier = Supplier::find($dispatch->supplier_id);
        $supplierName = $supplier ? $supplier->name : '供应商';

        $data = [
            'title'       => "供应商「{$supplierName}」已发货 - 订单 #{$orderNo}",
            'content'     => "供应商「{$supplierName}」已完成订单 #{$orderNo} 的制作并发货，请安排收货。",
            'category'    => Message::CATEGORY_ORDER_SHIP,
            'sender_type' => Message::SENDER_SUPPLIER,
            'sender_id'   => $dispatch->supplier_id,
            'order_id'    => $dispatch->order_id,
            'dispatch_id' => $dispatch->id,
        ];
        $this->sendToAdminsWithPermission($data, 'order');
    }

    /**
     * 收货/入库通知
     */
    public function notifyDispatchReceived(OrderDispatch $dispatch, ?int $adminId = null): void
    {
        $order = Order::find($dispatch->order_id);
        $orderNo = $order ? $order->order_no : $dispatch->order_id;

        // 通知供应商
        $this->send([
            'title'         => "订单 #{$orderNo} 已确认收货入库",
            'content'       => "您的派单（订单 #{$orderNo}）已被确认收货入库，感谢您的配合。",
            'category'      => Message::CATEGORY_ORDER_RECEIVE,
            'sender_type'   => Message::SENDER_ADMIN,
            'sender_id'     => $adminId,
            'receiver_type' => Message::RECEIVER_SUPPLIER,
            'receiver_id'   => $dispatch->supplier_id,
            'order_id'      => $dispatch->order_id,
            'dispatch_id'   => $dispatch->id,
        ]);

        // 通知管理员
        $data = [
            'title'       => "订单 #{$orderNo} 派单已入库",
            'content'     => "订单 #{$orderNo} 的派单货物已入库。",
            'category'    => Message::CATEGORY_ORDER_RECEIVE,
            'sender_type' => Message::SENDER_ADMIN,
            'sender_id'   => $adminId,
            'order_id'    => $dispatch->order_id,
            'dispatch_id' => $dispatch->id,
        ];
        $this->sendToAdminsWithPermission($data, 'order', $adminId);
    }

    /**
     * 出货发货通知
     */
    public function notifyShipped(int $orderId, string $shippingNo, ?int $adminId = null): void
    {
        $order = Order::find($orderId);
        $orderNo = $order ? $order->order_no : $orderId;

        $data = [
            'title'       => "订单 #{$orderNo} 已出货",
            'content'     => "订单 #{$orderNo} 已出货，出货单号：{$shippingNo}。",
            'category'    => Message::CATEGORY_SHIPPING,
            'sender_type' => Message::SENDER_ADMIN,
            'sender_id'   => $adminId,
            'order_id'    => $orderId,
        ];
        $this->sendToAdminsWithPermission($data, 'order', $adminId);
        // 也通知关联供应商
        $this->sendToOrderSupplier($data, $orderId);
    }

    // ============================================================
    // 权限辅助方法
    // ============================================================

    /**
     * 获取拥有指定模块权限的管理员ID列表
     * @param string $module 权限模块名，如 'order'
     * @param int|null $excludeId 要排除的管理员ID
     * @return array
     */
    protected function getAdminIdsByPermission(string $module, ?int $excludeId = null): array
    {
        // 查出所有有效角色
        $roles = AdminRole::where('status', 1)->select();

        $roleIds = [];
        foreach ($roles as $role) {
            // role_id=1 是超级管理员，拥有所有权限
            if ($role->id == 1 || $role->hasModulePermission($module)) {
                $roleIds[] = $role->id;
            }
        }

        if (empty($roleIds)) {
            return [];
        }

        $query = AdminUser::whereIn('role_id', $roleIds)
            ->where('status', 1)
            ->whereNull('delete_time');

        // 只排除非超管操作者，超管始终能收到消息
        if ($excludeId) {
            $excludeUser = AdminUser::find($excludeId);
            if ($excludeUser && $excludeUser->role_id != 1) {
                $query->where('id', '<>', $excludeId);
            }
        }

        return $query->column('id');
    }
}
