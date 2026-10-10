<?php
/**
 * 独角鲸控制面板 - 核心逻辑 (前后端共用)
 *
 * 被 public/index.php (宝塔/1Panel 的 nginx+php-fpm 前端控制器)
 * 和 router.php (php -S 本地开发) 共同引用。
 * 本文件只定义函数, 不产生任何输出, 可被 CLI 直接单元测试。
 */

/** 上游 API 地址: 环境变量 > config.php > 默认 */
function panel_upstream() {
    $u = getenv('NARWHAL_UPSTREAM');
    $cfgFile = dirname(__DIR__) . '/config.php';   // 与 public/ 同级, 在 web 根目录之外
    if (is_file($cfgFile)) {
        $cfg = include $cfgFile;
        if (is_array($cfg) && !empty($cfg['upstream'])) {
            $u = $cfg['upstream'];
        }
    }
    if (!$u) {
        $u = 'https://api.fuckip.me/api/v1';
    }
    return rtrim($u, '/');
}

/**
 * 取全部请求头。
 * getallheaders() 只在 Apache / php 内置服务器下可用,
 * 宝塔/1Panel 的 nginx+php-fpm 环境没有它, 此时从 $_SERVER 还原。
 */
function get_request_headers() {
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if (is_array($h)) {
            return $h;
        }
    }
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
            $headers[$name] = $v;
        } elseif ($k === 'CONTENT_TYPE' || $k === 'CONTENT_LENGTH' || $k === 'CONTENT_LENGTH') {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $k))));
            $headers[$name] = $v;
        }
    }
    return $headers;
}

function panel_json($code, $obj) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($obj, JSON_UNESCAPED_UNICODE);
}

/** 反向代理 /api/* 到上游, 原样透传方法/请求体/请求头(含 Authorization) */
function panel_proxy($upstream, $path, $query) {
    $rest = substr($path, strlen('/api/'));
    if ($rest === '' || strpos($rest, '..') !== false) {
        panel_json(400, ['code' => 400, 'msg' => 'bad path']);
        return;
    }
    $target = $upstream . '/' . $rest . $query;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $skip = ['host', 'content-length', 'connection', 'keep-alive', 'transfer-encoding', 'upgrade', 'te', 'trailer'];
    $headers = [];
    foreach (get_request_headers() as $k => $v) {
        if (in_array(strtolower($k), $skip, true)) {
            continue;
        }
        $headers[] = $k . ': ' . $v;
    }
    $body = file_get_contents('php://input');
    $hasBody = ($body !== '' && $body !== false && !in_array($method, ['GET', 'HEAD'], true));

    if (function_exists('curl_init')) {
        $ch = curl_init();
        $respHeaders = [];
        curl_setopt($ch, CURLOPT_URL, $target);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $hline) use (&$respHeaders) {
            $respHeaders[] = trim($hline);
            return strlen($hline);
        });
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        if ($hasBody) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $respBody = curl_exec($ch);
        if ($respBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            panel_json(502, ['code' => 502, 'msg' => '上游请求失败: ' . $err]);
            return;
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $hasBody ? $body : null,
            'timeout' => 60,
            'ignore_errors' => true,
        ]]);
        $respBody = @file_get_contents($target, false, $ctx);
        if ($respBody === false) {
            panel_json(502, ['code' => 502, 'msg' => '上游请求失败']);
            return;
        }
        $status = 200;
        $respHeaders = $http_response_header ?? [];
        foreach ($respHeaders as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int)$m[1];
            }
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
}

/** 前端控制器分发: /health 与 /api/* 走代理, 其余返回前端页面 */
function panel_dispatch($upstream, $publicDir) {
    $uri   = $_SERVER['REQUEST_URI'] ?? '/';
    $parts = parse_url($uri);
    $path  = $parts['path'] ?? '/';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';

    if ($path === '/health') {
        panel_json(200, ['ok' => true]);
        return;
    }
    if (strpos($path, '/api/') === 0) {
        panel_proxy($upstream, $path, $query);
        return;
    }
    $file = $publicDir . '/index.html';
    if (!is_file($file)) {
        panel_json(404, ['code' => 404, 'msg' => 'not found']);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Length: ' . filesize($file));
    readfile($file);
}
