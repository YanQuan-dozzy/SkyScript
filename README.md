# SkyScript

一个将 **SkyStudio 乐谱文件（.txt）** 批量转换为 **AutoJS 自动化脚本（.js）** 的本地化 Web 工具，同时支持 JS 模板互转与文件夹批量处理。

> 本仓库基于 [ThinkPHP 6.1](https://www.thinkphp.cn/) 构建，前端为纯静态 HTML / CSS / JS。

## ✨ 功能特性

- 🎵 **单文件转换** — 拖拽 `.txt` / `.js` 即可即时转换
- 📦 **批量 ZIP** — 上传 `.zip` 压缩包一次性转换多个文件（最多 200 个）
- 📁 **文件夹批量** — 指定服务器侧目录递归扫描（最多 500 个）
- 🔁 **JS 模板互转** — 支持 `按压` / `按压新` / `长按` 三种模板互转
- ⬇️ **多种保存方式** — 下载链接（72h 有效）/ Web 可访问 / 本地文件夹
- 🔐 **简易后台** — 内置管理员登录、IP 限流、上传/转换日志
- 📊 **实时统计** — 今日/累计转换数

## 🚀 快速开始

### 环境要求

- PHP **7.2+**（兼容 PHP 8.1）
- Composer
- 推荐：phpStudy / Laragon / XAMPP 等集成环境

### 安装步骤

```bash
# 1. 克隆仓库
git clone git@github.com:YanQuan-dozzy/SkyScript.git
cd SkyScript

# 2. 安装依赖
composer install

# 3. 复制环境配置
cp .example.env .env
# Windows:
# copy .example.env .env

# 4. 导入数据库（可选，仅在使用管理后台时需要）
# 在 MySQL 中执行 sql/install.sql

# 5. 配置 Web 服务器，将站点根目录指向 public/
```

### 启动内置开发服务器

```bash
php think run
# 或
php -S 127.0.0.1:8000 -t public/
```

浏览器访问 <http://127.0.0.1:8000>。

## 🧩 转换模板

| 模板        | 特点                                           |
|-------------|------------------------------------------------|
| `press`     | 单行函数 + 悬浮窗控制                          |
| `press_new` | gestures 列表 + 35ms 短按 + 进度条             |
| `long_press`| gestures 列表 + 按压 = 间隔（延音）+ 音量键    |

JS 模板之间可以互相转换：上传一个已生成的 `.js`，选择源模板与目标模板即可重新渲染。

## 📁 目录结构

```
.
├── app/                # ThinkPHP 应用代码
│   ├── controller/     # 控制器
│   ├── service/        # 业务服务（TxtToJsService 核心转换）
│   ├── model/          # 数据模型
│   ├── validate/       # 验证器
│   ├── middleware/     # 中间件（鉴权、限流）
│   └── view/           # 视图模板
├── public/             # Web 入口
│   ├── index.php
│   └── static/         # 静态资源
├── config/             # 配置
├── route/              # 路由
├── runtime/            # 运行时（自动生成，已 gitignore）
├── converted/          # 本地转换产物（已 gitignore）
├── downloads/          # Web 可访问产物（已 gitignore）
├── SkyStudio2Autojs-main/  # C++ 版转换器（命令行）
├── sql/                # 数据库脚本
├── test/               # 测试样本
└── think               # 命令行入口
```

## 🛠️ 部署

详见 [DEPLOY.md](DEPLOY.md)。

## 📜 许可

本项目基于 **Apache License 2.0** 开源发布 — 详见 [LICENSE.txt](LICENSE.txt)。

## 🙏 致谢

- [ThinkPHP](https://www.thinkphp.cn/) — 优秀的国产 PHP 框架
- [SkyStudio](https://github.com/) — 乐谱文件来源
- [AutoJS](https://github.com/hyb1996/Auto.js) — Android 自动化脚本引擎
