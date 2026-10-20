<?php
namespace app\common\service;

/**
 * 上传安全服务类
 * 提供文件上传、下载、删除等安全操作
 */
class UploadSecurityService
{
    /**
     * 验证文件类型
     * @param string $filename 文件名
     * @param array $allowedTypes 允许的类型
     * @return bool
     */
    public static function validateFileType($filename, $allowedTypes)
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $allowedTypes);
    }
    
    /**
     * 验证文件大小
     * @param int $fileSize 文件大小
     * @param int $maxSize 最大允许大小
     * @return bool
     */
    public static function validateFileSize($fileSize, $maxSize)
    {
        return $fileSize <= $maxSize;
    }
    
    /**
     * 验证图片尺寸
     * @param string $filePath 文件路径
     * @param array $sizeConfig 尺寸配置
     * @return bool
     */
    public static function validateImageSize($filePath, $sizeConfig)
    {
        $imageInfo = getimagesize($filePath);
        if (!$imageInfo) {
            return false;
        }
        
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        
        // 检查最大尺寸
        if (isset($sizeConfig['max_width']) && $width > $sizeConfig['max_width']) {
            return false;
        }
        
        if (isset($sizeConfig['max_height']) && $height > $sizeConfig['max_height']) {
            return false;
        }
        
        // 检查最小尺寸
        if (isset($sizeConfig['min_width']) && $width < $sizeConfig['min_width']) {
            return false;
        }
        
        if (isset($sizeConfig['min_height']) && $height < $sizeConfig['min_height']) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 清理文件名，防止路径遍历攻击
     * @param string $filename 文件名
     * @return string
     * 修复：截断255字符可能破坏扩展名，改为先分离扩展名再截断
     */
    public static function sanitizeFilename($filename)
    {
        // 移除路径遍历字符
        $filename = str_replace(['../', './', '/', '\\'], '', $filename);

        // 移除特殊字符
        $filename = preg_replace('/[^\w\-\.]/u', '_', $filename);

        // 限制文件名长度（先分离扩展名再截断，避免破坏扩展名）
        if (strlen($filename) > 255) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $basename = pathinfo($filename, PATHINFO_FILENAME);
            $maxBasenameLen = 255 - strlen($extension) - 1; // -1 for the dot
            if ($maxBasenameLen > 0) {
                $basename = substr($basename, 0, $maxBasenameLen);
                $filename = $basename . '.' . $extension;
            } else {
                $filename = substr($filename, 0, 255);
            }
        }

        return $filename;
    }
    
    /**
     * 验证文件内容（基础MIME类型验证）
     * @param string $filePath 文件路径
     * @param string $expectedExtension 期望的扩展名
     * @return bool
     * 修复：1) 去除重复的 css/js/html MIME类型定义 2) finfo_file 可能返回带字符集的值，改用 strpos 比较
     */
    public static function validateFileContent($filePath, $expectedExtension)
    {
        $mimeTypes = [
            // 图片类型
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'tiff' => 'image/tiff',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            // 视频类型
            'mp4' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'mkv' => 'video/x-matroska',
            'webm' => 'video/webm',
            // 音频类型
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            // 压缩包类型
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            // 文档类型
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            // 编程类型
            'php' => 'text/x-php',
            'java' => 'text/x-java-source',
            'c' => 'text/x-c',
            'cpp' => 'text/x-c++',
            'cs' => 'text/x-csharp',
            'js' => 'text/javascript',
            'css' => 'text/css',
            'html' => 'text/html',
            // 字体类型
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            // 其他类型
            'txt' => 'text/plain',
            'rtf' => 'application/rtf',
            'xml' => 'text/xml',
            'json' => 'application/json',
        ];

        $expectedMime = $mimeTypes[strtolower($expectedExtension)] ?? null;
        if (!$expectedMime) {
            // 如果没有对应的MIME类型，跳过验证
            return true;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actualMime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        // 修复：finfo_file 可能返回带字符集的值如 'text/html; charset=UTF-8'，
        // 使用 strpos 比较而非 === 严格比较
        return strpos($actualMime, $expectedMime) === 0;
    }
    
    /**
     * 生成安全的随机文件名
     * @param string $originalName 原始文件名
     * @return string
     * 修复：uniqid() 不安全（基于时间戳可预测），改用 bin2hex(random_bytes(8))
     */
    public static function generateSafeFilename($originalName)
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);

        // 清理文件名
        $nameWithoutExt = self::sanitizeFilename($nameWithoutExt);

        // 生成安全的唯一ID（使用加密安全的随机数替代 uniqid）
        $uniqueId = bin2hex(random_bytes(8));

        // 组合文件名
        return $nameWithoutExt . '_' . $uniqueId . '.' . $extension;
    }
}