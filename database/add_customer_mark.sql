-- 客户唛头表
CREATE TABLE IF NOT EXISTS `customer_mark` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) unsigned NOT NULL COMMENT '客户ID',
  `mark_text` text DEFAULT NULL COMMENT '唛头文本内容',
  `mark_image` varchar(500) DEFAULT NULL COMMENT '唛头图片URL',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='客户唛头表';

-- 订单表增加唛头关联字段（关联 customer_mark.id）
ALTER TABLE `order` ADD COLUMN `mark_id` int(11) unsigned DEFAULT NULL COMMENT '关联唛头ID' AFTER `remark`;
