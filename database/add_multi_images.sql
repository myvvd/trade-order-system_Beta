-- 添加多图支持和描述图片列
-- photo_urls: 存储多张款式图片的JSON数组，如 ["/uploads/goods/202603/a.jpg", "/uploads/goods/202603/b.jpg"]
-- desc_images: 存储多张描述图片的JSON数组，格式同上
-- 执行后 photo_url 字段保留作为向后兼容（存储第一张图片），photo_urls 存储完整列表

ALTER TABLE `order_item` ADD COLUMN `photo_urls` TEXT DEFAULT NULL COMMENT '款式图片列表(JSON数组)' AFTER `photo_url`;
ALTER TABLE `order_item` ADD COLUMN `desc_images` TEXT DEFAULT NULL COMMENT '描述图片列表(JSON数组)' AFTER `photo_urls`;

-- 将现有 photo_url 数据迁移到 photo_urls
UPDATE `order_item` SET `photo_urls` = CONCAT('["', photo_url, '"]') WHERE `photo_url` IS NOT NULL AND `photo_url` != '';
