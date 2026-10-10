<?php
/**
 * 独角鲸 (Narwhal Cloud) 控制面板 - PHP 版
 *
 * 用法:
 *   php -S 127.0.0.1:8080 router.php
 *   # 或 ./start.sh [端口]
 *
 * 然后在浏览器打开 http://127.0.0.1:8080 ，粘贴 API 密钥即可使用。
 *
 * 原理:
 *   浏览器直接跨域调用 api.fuckip.me 会被 CORS 策略拦截，
 *   因此本脚本在同源下提供 /api/* 反向代理，把请求原样转发到
 *   https://api.fuckip.me/api/v1/* (可通过环境变量 NARWHAL_UPSTREAM 覆盖，
 *   仅用于本地联调测试)。
 *
 * 仅依赖 PHP 内置 Web 服务器 + curl 扩展(无 curl 时自动降级为 stream)。
 */

$UPSTREAM = getenv('NARWHAL_UPSTREAM') ?: 'https://api.fuckip.me/api/v1';
$UPSTREAM = rtrim($UPSTREAM, '/');

$uri   = $_SERVER['REQUEST_URI'] ?? '/';
$parts = parse_url($uri);
$path  = $parts['path'] ?? '/';
$query = isset($parts['query']) ? '?' . $parts['query'] : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function json_out($code, $obj) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($obj, JSON_UNESCAPED_UNICODE);
    exit;
}

// 健康检查
if ($path === '/health') {
    json_out(200, ['ok' => true]);
}

// API 反向代理
if (strpos($path, '/api/') === 0) {
    $rest = substr($path, strlen('/api/'));
    // 防路径穿越
    if ($rest === '' || strpos($rest, '..') !== false) {
        json_out(400, ['code' => 400, 'msg' => 'bad path']);
    }
    $target = $UPSTREAM . '/' . $rest . $query;

    // 透传请求头 (去掉 hop-by-hop)
    $skip = ['host', 'content-length', 'connection', 'keep-alive', 'transfer-encoding', 'upgrade', 'te', 'trailer'];
    $headers = [];
    foreach (getallheaders() as $k => $v) {
        if (in_array(strtolower($k), $skip, true)) continue;
        $headers[] = $k . ': ' . $v;
    }
    $body = file_get_contents('php://input');

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $target);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $hline) use (&$respHeaders) {
            $respHeaders[] = trim($hline);
            return strlen($hline);
        });
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        if ($body !== '' && $body !== false && !in_array($method, ['GET', 'HEAD'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $respHeaders = [];
        $respBody = curl_exec($ch);
        if ($respBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            json_out(502, ['code' => 502, 'msg' => '上游请求失败: ' . $err]);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        // 无 curl 扩展时的降级方案
        $ctx = stream_context_create(['http' => [
            'method'  => $method,
            'header'  => implode("\r\n", $headers),
            'content' => ($body !== '' && $body !== false) ? $body : null,
            'timeout' => 60,
            'ignore_errors' => true,
        ]]);
        $respBody = @file_get_contents($target, false, $ctx);
        if ($respBody === false) {
            json_out(502, ['code' => 502, 'msg' => '上游请求失败']);
        }
        $status = 200;
        $respHeaders = $http_response_header ?? [];
        foreach ($respHeaders as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
        }
    }

    http_response_code($status);
    $ctype = 'application/octet-stream';
    foreach ($respHeaders as $h) {
        if (stripos($h, 'content-type:') === 0) {
            $ctype = trim(substr($h, strlen('content-type:')));
        } elseif (stripos($h, 'content-disposition:') === 0) {
            header('Content-Disposition: ' . trim(substr($h, strlen('content-disposition:'))));
        }
    }
    header('Content-Type: ' . $ctype);
    header('Content-Length: ' . strlen($respBody));
    echo $respBody;
    exit;
}

// 静态文件 (仅 public 目录内)
$rel = ($path === '/') ? '/index.html' : $path;
$file = realpath(__DIR__ . '/public' . $rel);
$base = realpath(__DIR__ . '/public');
if ($file === false || strpos($file, $base) !== 0 || !is_file($file)) {
    json_out(404, ['code' => 404, 'msg' => 'not found']);
}
$mimes = [
    'html' => 'text/html; charset=utf-8', 'css' => 'text/css',
    'js' => 'application/javascript', 'json' => 'application/json',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
readfile($file);
