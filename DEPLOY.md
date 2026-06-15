# AuToJs.com 部署教程（phpstudy + Apache 2.4.39 + MySQL 8.0.12）

本文档对应本机环境：

- phpstudy 根目录：`D:\phpstudy_pro`
- Apache：`D:\phpstudy_pro\Extensions\Apache2.4.39`
- MySQL：`D:\phpstudy_pro\Extensions\MySQL8.0.12`
- PHP：`D:\phpstudy_pro\Extensions\php\php8.2.9nts`（NTS, FastCGI）
- 项目目录：`D:\phpstudy_pro\WWW\www.AuToJs.com`
- 访问地址：http://www.autojs.com:8083/

---

## 1. 启动 phpstudy 服务

1. 打开 phpstudy_pro 控制面板，启动 **Apache 2.4.39** 与 **MySQL 8.0.12**。
2. 确认右下角图标显示 "Apache 已启动"、"MySQL 已启动"。
3. 默认 MySQL 8.0 root 密码为 `123456`（本机），若你修改过请同步修改 `.env` 中的 `DATABASE_PASSWORD`。

## 2. 放置项目

将整个项目文件夹 `www.AuToJs.com` 复制到：

```
D:\phpstudy_pro\WWW\www.AuToJs.com
```

最终结构（部分）：

```
D:\phpstudy_pro\WWW\www.AuToJs.com
├─ app/                # 控制器、模型、验证器、中间件、服务、命令
│  ├─ controller/
│  ├─ model/
│  ├─ service/TxtToJsService.php
│  ├─ validate/
│  ├─ middleware/
│  ├─ view/
│  └─ command/Cleanup.php
├─ config/             # 全局配置（app / database / middleware ...）
├─ public/             # Web 入口目录（含 .htaccess）
│  ├─ index.php
│  └─ static/          # 前端 css / js / ico
├─ route/              # 路由
├─ runtime/            # 缓存 / 日志 / 临时文件（runtime/convert/upload, output）
├─ vendor/             # Composer 依赖
├─ sql/install.sql     # 建表脚本
├─ .env                # 数据库等环境配置
├─ .htaccess           # 根 .htaccess，强制重写到 public/
└─ composer.json
```

## 3. 创建数据库 & 导入表

1. 打开 phpstudy 的 "数据库" → 打开 phpMyAdmin（或命令行 `mysql -uroot -p123456`）。
2. 执行 `D:\phpstudy_pro\WWW\www.AuToJs.com\sql\install.sql`：

```sql
-- 该脚本会创建：
--   数据库 autojs
--   表 atj_conversion_log
--   表 atj_admin (默认账号 admin / admin888)
```

> 初始管理员账号：`admin` / `admin888`（登录后请立即改密码）。

## 4. 安装 Composer 依赖

如果 `vendor/` 已经存在可跳过。手动安装：

```bat
cd /d D:\phpstudy_pro\WWW\www.AuToJs.com
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe D:\phpstudy_pro\Extensions\composer2.5.8\composer.phar install --no-dev
```

> 生产环境使用 `--no-dev`；本地调试可省略该参数。

## 5. 配置 .env

编辑项目根目录 `.env`，确认以下几行（默认即配好）：

```ini
APP_DEBUG = true          # 生产环境改为 false
APP_TRACE = false

[DATABASE]
TYPE = mysql
HOSTNAME = 127.0.0.1
DATABASE = autojs
USERNAME = root
PASSWORD = 123456        # ← 改为你自己的 MySQL 密码
HOSTPORT = 3306
CHARSET = utf8mb4
DEBUG = true
PREFIX = atj_

[CACHE]
DRIVER = file

[SESSION]
DRIVER = file
```

## 6. 配置 Apache 虚拟主机

phpstudy 已自带 `www.AuToJs.com:8083` 站点（位于 `D:\phpstudy_pro\Extensions\Apache2.4.39\conf\vhosts\www.AuToJs.com_8083.conf`）：

```apache
Listen 8083

<VirtualHost *:8083>
    DocumentRoot "D:/phpstudy_pro/WWW/www.AuToJs.com"
    ServerName www.AuToJs.com
    FcgidInitialEnv PHPRC "D:/phpstudy_pro/Extensions/php/php8.2.9nts"
    AddHandler fcgid-script .php
    FcgidWrapper "D:/phpstudy_pro/Extensions/php/php8.2.9nts/php-cgi.exe" .php

    <Directory "D:/phpstudy_pro/WWW/www.AuToJs.com">
        Options FollowSymLinks ExecCGI
        AllowOverride All
        Order allow,deny
        Allow from all
        Require all granted
        DirectoryIndex index.php index.html error/index.html
    </Directory>
    # ErrorDocument ...
</VirtualHost>
```

> 关键点：
> - `DocumentRoot` 指向项目根（含 `.htaccess`），由根 `.htaccess` 强制重写到 `public/`。
> - `AllowOverride All` 必填，否则 `.htaccess` 失效。
> - 端口 `8083` 已在 `Listen.conf` 中声明。

修改配置后重启 Apache。

## 7. hosts 绑定

为方便使用域名 `www.autojs.com` 访问本机，在 `C:\Windows\System32\drivers\etc\hosts` 加一行：

```
127.0.0.1   www.autojs.com
```

> 也可直接使用 `http://127.0.0.1:8083/`。

## 8. 验证

浏览器访问：

- 首页（转换页）：http://www.autojs.com:8083/
- 关于：http://www.autojs.com:8083/index/about.html
- 后台登录：http://www.autojs.com:8083/admin/login.html
- 默认账号：`admin` / `admin888`

CLI 自测脚本：

```bat
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe D:\phpstudy_pro\WWW\www.AuToJs.com\e2e_test.php
```

输出全部 `200 OK` 视为部署成功。

## 9. 72 小时自动清理临时文件

项目自带 ThinkPHP 命令 `cleanup`，用法：

```bat
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe D:\phpstudy_pro\WWW\www.AuToJs.com\think cleanup --hours=72 --dry
```

参数：

- `--hours=72` 保留最近 72 小时（默认 72）
- `--dry` 仅统计不删除（首次建议加）

加入 Windows 计划任务（每 6 小时跑一次）：

```bat
:: 文件名 D:\phpstudy_pro\WWW\www.AuToJs.com\scripts\cleanup.bat
@echo off
cd /d D:\phpstudy_pro\WWW\www.AuToJs.com
"D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe" think cleanup --hours=72
```

然后在「任务计划程序」中新建任务：

- 触发器：每天 00:00、06:00、12:00、18:00
- 操作：启动程序 `D:\phpstudy_pro\WWW\www.AuToJs.com\scripts\cleanup.bat`
- "不管用户是否登录都要运行"
- "使用最高权限运行"

或者一行 schtasks 命令：

```bat
schtasks /Create /SC HOURLY /MO 6 /TN "AuToJs-Cleanup" /TR "D:\phpstudy_pro\WWW\www.AuToJs.com\scripts\cleanup.bat" /RU SYSTEM /F
```

## 10. 目录权限

`runtime/` 需要可写权限（PHP-FCGI 进程要能创建子目录与写入文件）。Windows 下：

- 找到 `D:\phpstudy_pro\WWW\www.AuToJs.com\runtime`
- 右键 → 属性 → 安全 → 编辑 → 给 `Everyone` 添加「修改 / 写入」权限
- 同样给 `D:\phpstudy_pro\WWW\www.AuToJs.com\runtime\convert\upload`、`runtime\convert\output` 授权

> 如果嫌麻烦，phpstudy 默认在管理员账户下运行时一般可写。若遇到 500 错误，先看 `runtime\log\` 下的日志。

## 11. 安全说明（已默认配置）

- 根 `.htaccess`：拒绝 `app/`, `config/`, `route/`, `runtime/`, `vendor/`, `.env` 等目录直接访问。
- `runtime/.htaccess`：拒绝所有脚本执行（防止上传的恶意文件被 Apache 解析）。
- `public/.htaccess`：ThinkPHP 默认重写到 `index.php`。
- `UploadValidate` 验证：仅允许 `.txt` / `.zip`，最大 10MB。
- `IpRateLimit` 中间件：单 IP 60 秒最多 30 次请求。
- 临时文件随机重命名（不带原文件名后缀）。
- Zip 防炸弹检查（解压后总大小 ≤ 200MB，单文件压缩比 ≤ 100）。
- 管理员密码使用 `password_hash`（BCRYPT）存储 + 站点自定义 salt。

## 12. 升级 / 重启

```bat
:: 重启 Apache
net stop Apache2.4
net start Apache2.4

:: 清理 ThinkPHP 运行时缓存
D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe D:\phpstudy_pro\WWW\www.AuToJs.com\think clear
```

## 13. 常见问题

| 现象 | 排查 |
| --- | --- |
| 500 错误 | 打开 `.env` 的 `APP_DEBUG=true` 查看错误详情；查 `runtime\log\` |
| 视图 500 (模板不存在) | 确认 `app/view/<controller>/<method>.html` 路径与控制器方法名一致 |
| 数据库连不上 | 检查 `phpstudy` MySQL 是否启动；确认密码正确；检查 `disable_functions` 是否禁用了 `pdo_mysql` |
| 上传超大文件失败 | 改 `php.ini` 的 `upload_max_filesize` 与 `post_max_size`，并改 Apache 的 `LimitRequestBody` |
| 首次访问很慢 | 关闭 `APP_TRACE`、关闭 `APP_DEBUG`，可启用 OPcache |

部署完成 🎉，打开 http://www.autojs.com:8083/ 即可使用。
