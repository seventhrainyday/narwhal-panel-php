<?php
/**
 * 独角鲸控制面板 - 前端控制器 (宝塔 / 1Panel 部署用)
 *
 * 部署方式: 把网站运行目录指向 public/, PHP 7.4+,
 * nginx 加一条规则:  try_files $uri $uri/ /index.php?$query_string;
 * (Apache 可用自带的 public/.htaccess)
 */
require __DIR__ . '/../src/panel.php';

panel_dispatch(panel_upstream(), __DIR__);
