#!/bin/bash
# 独角鲸控制面板 (PHP 版) 启动脚本
# 用法: ./start.sh [端口，默认 8080]
PORT=${1:-8080}
echo "独角鲸控制面板(PHP)已启动: http://127.0.0.1:${PORT}"
echo "在页面右上角粘贴 API 密钥后即可使用，按 Ctrl+C 退出。"
exec php -d expose_php=Off -S 127.0.0.1:${PORT} router.php
