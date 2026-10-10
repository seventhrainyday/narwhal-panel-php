<?php
/**
 * 独角鲸控制面板 - PHP 内置服务器路由 (本地开发用)
 *
 * 用法: php -S 127.0.0.1:8080 router.php   (或 ./start.sh)
 *
 * /health 与 /api/* 走 public/index.php 的前端控制器逻辑,
 * 其余静态文件由内置服务器直接提供。
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health' || strpos($path, '/api/') === 0 || $path === '/' || $path === '/index.html') {
    require __DIR__ . '/public/index.php';
} else {
    return false; // 内置服务器直接提供静态文件
}
