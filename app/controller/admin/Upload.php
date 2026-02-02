<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use think\facade\Filesystem;
use think\facade\Env;
use think\facade\Log;

class Upload extends Base
{
    public function index()
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $file = request()->file('file');
        if (!$file) {
            return $this->error('未找到上传文件');
        }

        try {
            // 基本校验：大小与 MIME 类型
            $max = 5 * 1024 * 1024; // 5MB
            $allowed = [
                'image/jpeg', 'image/png', 'image/gif',
                'application/pdf', 'application/zip',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];

            $size = method_exists($file, 'getSize') ? $file->getSize() : ($file->getInfo('size') ?? 0);
            $mime = method_exists($file, 'getMime') ? $file->getMime() : ($file->getInfo('type') ?? '');

            if ($size > $max) {
                return $this->error('文件过大，最大 5MB');
            }
            if (!in_array($mime, $allowed)) {
                return $this->error('不支持的文件类型');
            }

            $saveto = Filesystem::disk('public')->putFile('uploads', $file);
            $url = '/static/uploads/' . basename($saveto);
            return $this->success(['url' => $url], '上传成功');
        } catch (\Exception $e) {
            // 记录完整错误到后端日志，不向前端泄露内部信息
            Log::error('Upload error: ' . $e->getMessage(), ['file' => $file?->getInfo() ?? null]);
            return $this->error('上传失败，请稍后重试');
        }
    }
}
