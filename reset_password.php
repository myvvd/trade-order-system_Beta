<?php
// 加载 ThinkPHP
require __DIR__ . '/vendor/autoload.php';

$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "=== 密码重置工具 ===\n\n";
echo "原始密码: {$password}\n";
echo "加密密码: {$hash}\n\n";
echo "请执行以下 SQL:\n";
echo "UPDATE admin_user SET password='{$hash}' WHERE username='admin';\n";