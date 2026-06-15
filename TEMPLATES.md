# JS 输出模板说明

AuToJs.com 在 v1.1+ 起支持 **3 种 JS 输出模板**，由 `template` 参数控制。
模板由 `app\service\TxtToJsService` 实现，与本地 C++ 项目的 1:1 复刻（`press`）保持完全一致。

## 三种模板对照

| template 值 | 名称 | 用途 | 关键特征 |
| --- | --- | --- | --- |
| `press` | 按压 | 默认；与本地 C++ 输出**完全 1:1** 复刻 | 单行函数 + `c4();t4();g4();` + 随机偏移 + 5 悬浮窗控制 |
| `press_new` | 按压新 | gestures 列表实现 + 35ms 短按 + 进度条 | `list=[[keys],pT,interval]`，`gestures.apply`，**seekbar 拖动** |
| `long_press` | 长按 | 延音模式：按压时间 = 间隔时间 | `list=[[keys],interval,0]`，**音量键控制**（+ 停止，- 暂停） |

## API 调用

### 单文件
```
POST /convert/single.html
  file=@test.txt
  template=press|press_new|long_press
```

### 批量 ZIP
```
POST /convert/batch.html
  file=@all.zip
  template=press|press_new|long_press
```

非法 template 值自动回退为 `press`（HTTP 200，不报错）。

## 模板参数对应

- `t0` = 2 × lNoteTime  (lNoteTime = 60000 / bpm)
- `t1` = 1 × lNoteTime
- `t2` = 4 × lNoteTime

C++ 原始 `t1/t2/t4` 在三模板中映射：
- C++ t1 (1x)  ↔ 模板 t1
- C++ t2 (2x)  ↔ 模板 t0
- C++ t4 (4x)  ↔ 模板 t2

## 输出差异（press_new vs long_press）

| 字段 | press_new | long_press |
| --- | --- | --- |
| pressTime | `pT` (35ms 固定) | `t0/t1/t2/t0+t1` 表达式（= 间隔） |
| sleepTime | `interval - pT` | `0` |
| 悬浮窗 | 完整带 seekbar | 无悬浮窗 |
| 控制 | 屏幕按钮 + 进度条 | **音量键**（+停止 / -暂停） |
| 提示 | 无 | "延音模式：按音量键..." |

## 模板渲染逻辑位置

`app\service\TxtToJsService.php` 中：
- `parseToNotes()` — 解析 txt → notes 中间表示（与 C++ 行为一致）
- `renderPressNew()` — 模板 press_new 渲染
- `renderLongPress()` — 模板 long_press 渲染
- `convert()` — press 模板（1:1 复刻 C++ 原始输出）
- `convertWithTemplate($content, $srcName, $template)` — 模板分发入口

## 前端

`app\view\index\index.html` 中：
- 单文件面板：`.template-picker > input[name="template"]`
- 批量面板：`.template-picker > input[name="template-batch"]`

`public\static\js\app.js` 中：
- `getSelectedTemplate()` 读取当前激活面板的选中值
- 单文件/批量 FormData 都附加 `template` 字段

## 数据库

`conversion_log` 表中无 `template` 字段（可选扩展）。
当前通过响应 JSON 的 `data.template` 字段回传给前端。

---

# 模板互转 (.js → .js) 与文件夹批量

v1.2+ 起，转换服务支持 **JS 模板互转**（.js 源 → .js 输出）和 **服务器侧文件夹批量**。

## 支持的源文件类型

| 扩展名 | 解析方式 | 需要 src_template? |
| --- | --- | --- |
| `.txt` | UTF-16 LE → JSON/ABC 谱 → notes | 不需要 |
| `.js` | 反解析三种模板的输出 → notes | **需要** |

## API 端点

### POST /convert/single

单文件上传。

- `file`：必填，`.txt` 或 `.js`
- `template`：`press` / `press_new` / `long_press`，默认 `press`
- `src_template`：当 `file` 是 `.js` 时必填，指定该 `.js` 是哪种模板的输出
- `save_mode[]`：可多选 `web_access` / `local_folder`

### POST /convert/batch

批量压缩包。

- `file`：必填，`.zip`
- ZIP 内可含 `.txt` 与 `.js` 混合（最多 200 个）
- `template` / `src_template` / `save_mode[]` 同上
- `.js` 文件按 `src_template` 反解析

### POST /convert/folder

服务器侧文件夹批量。

- `folder`：必填，服务器本地**绝对路径**（如 `D:\phpstudy_pro\WWW\www.AuToJs.com\test\converted`）
- 系统递归扫描 `folder` 下的 `.txt` / `.js`（最多 500 个）
- `template` / `src_template` / `save_mode[]` 同上
- 禁止扫描系统目录（`C:\Windows` 等）
- 返回 `data.download` 是所有输出文件打包后的 .zip
- 进度通过 `GET /convert/progress?batch_id=xxx` 轮询

## 三种 JS 模板的格式特点（决定反解析策略）

| 模板 | 输出形式 | 间隔毫秒字段 | 按压时间字段 |
| --- | --- | --- | --- |
| `press` | `c4();t1();d4();t2();` 命令流 | t1=212 / t2=424 / t4=848ms | 1ms (press 函数内置) |
| `press_new` | `list = [[keys],pT,interval]` | item[2] = sleepTime | item[1] = pT = 35ms |
| `long_press` | `list = [[keys],tExpr,0]` | pressTime = interval 长度 | 0 |

**反解析关键字段**：
- 从 `var time=212` (press) 或 `let t1 = 212` (press_new / long_press) 提取 `lNoteTime`
- 从 `let t0 = 424, t1 = 212, t2 = 848` 提取 t0/t1/t2 数值
- 配对扫描 `list = [ ... ]` 区块的方括号深度，提取每条 `[[keys], EXPR, EXPR]`
- 区分 press_new（item[2] - item[1] = interval）和 long_press（item[1] = interval）

## 实现位置

`app\service\TxtToJsService.php`：
- `convertWithTemplate($content, $srcName, $template, $srcTemplate=null)` — 新增 `srcTemplate` 参数
- `parseToNotes($content, $srcName, $srcTemplate)` — 改名为支持 .txt/.js 双路径
- `parseJsToNotes($js, $srcTemplate)` — 新增 .js 反解析入口
- `extractNoteTimeFromJs($js)` — 提取 lNoteTime
- `parseJsPress($js, $noteTime)` — press 模板反解析
- `parseJsGestures($js, $noteTime)` — press_new / long_press 反解析（含 list 区块括号配对扫描）
- `evalTExpr($expr, $t0, $t1, $t2)` — 求值 `t0+t1` / `0` / 数字
- `jsKeyToNum($name)` — a4..g6 琴键名 → 1..15
- `renderPressFromNotes($noteTime, $notes)` — 从 notes 中间表示重新渲染 press 输出（用于 .js → .js 路径）
- `containsPTSleep($js)` — 探测 pre 函数签名以区分 press_new / long_press

`app\controller\Convert.php`：
- `single()` / `batch()` / `folder()` — 接受 `src_template`
- `resolveSrcTemplate()` — 解析 src_template 参数
- `scanSources($dir)` — 同时扫 .txt + .js

`route\app.php`：
- `Route::post('convert/folder', 'convert/folder')`

`app\validate\UploadValidate.php`：
- `fileExt` 增加 `js` 允许

## 前端

`app\view\index\index.html`：
- 新增第 3 个 Tab：文件夹批量，输入服务器侧绝对路径
- 单文件 / 批量面板新增"源模板" radio 组（仅 .js 源时显示）
- 单文件 accept=".txt,.js"

`public\static\js\app.js`：
- 提交按钮根据 `currentMode` 决定调 `doSingle` / `doBatch` / `doFolder`
- 文件类型变化时（.txt ↔ .js）显隐"源模板"选择器

`public\static\css\style.css`：
- `.src-template-picker`：橙色虚线框，提示这是 .js 源专用
- `.folder-form`：浅蓝框 + 路径输入框

## 测试场景

1. txt → press（基线）
2. js (press 源) → press_new
3. js (press_new 源) → long_press
4. js (long_press 源) → press（round trip）
5. 文件夹批量：3 个文件（1.txt + 1.js press + 1.js press_new）
6. 非法路径拦截
7. .js 上传但未指定 src_template（默认 press）
