<?php
declare(strict_types=1);

namespace app\service;

/**
 * TXT 转 AutoJS 核心转换服务
 *
 * 转换逻辑 1:1 复刻本地项目 D:\phpstudy_pro\WWW\www.AuToJs.com\SkyStudio2Autojs-main
 *
 * 参考来源对应关系:
 *  - C++ 源文件: SkyStudio2Autojs.cpp、CodeResource.hpp、FileOperation.cpp、Main.cpp
 *  - JS 代码模板: CodeResource.hpp 中 c8NoteFuncCode / c8TimeFuncCode / c8VarCode / c8BaseCode /
 *                 c8RandomCode / c8NoRandomCode / c8WindowCode / c8NoWindowCode
 *  - ABC 谱解析:   SkyStudio2Autojs.cpp::ABC2NoteKey
 *  - Json 谱解析:  SkyStudio2Autojs.cpp::Json2Autojs
 *  - 文件头判断:   FileOperation.cpp::IsUTF16 / IsABCScore
 *  - 整体控制流:   Main.cpp::main
 */
class TxtToJsService
{
    /** @var int 临时数组大小，对应 Main.h::READSTRSIZE = 32 */
    private const READSTRSIZE = 32;

    /** @var int 每分钟的毫秒数，对应 Main.h::MILLISECONDS_PER_MINUTE */
    private const MILLISECONDS_PER_MINUTE = 60000;

    /** @var string[] 支持的模板 */
    public const TEMPLATES = ['press', 'press_new', 'long_press'];

    /** @var string 不允许的错误 */
    private const ERR_INVALID_FILE       = '无效的文件';
    private const ERR_FILE_TOO_LARGE     = '文件过大';
    private const ERR_READ_FAIL          = '文件读取失败';
    private const ERR_INVALID_UTF16      = '错误的文件格式（仅支持 UTF-16 LE / UTF-8 ABC 谱）';
    private const ERR_INVALID_BPM        = 'BPM 无效';
    private const ERR_EMPTY_OUTPUT       = '未能生成任何代码';
    private const ERR_INVALID_TEMPLATE   = '不支持的模板';

    /**
     * 默认配置，对应 ConverterConfig.cpp::SetDefault
     */
    private array $config = [
        'use_random_offset'      => true,   // USE_RANDOMOFFSET
        'have_window'            => true,   // HAVE_WINDOW
        'ignore_score_begin'     => true,   // IGNORE_SCORE_START_TIME
        'drag_use_target_dir'    => false,  // DRAG_USE_TARGET_DIR
        'random_offset'          => 18,     // RANDOM_OFFSET
        'no_window_start_time'   => 1000,   // NO_WINDOW_START_TIME
    ];

    /**
     * 转换结果
     */
    private string $output = '';
    private string $error  = '';
    private int    $outBytes = 0;
    private bool   $bJsonScore = false;
    private int    $noteTime = 0;

    /**
     * 临时解析缓冲（避免分配过多内存）
     * @var int[]
     */
    private array $wideBuf = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->config, $config);
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getOutputBytes(): int
    {
        return $this->outBytes;
    }

    public function isJsonScore(): bool
    {
        return $this->bJsonScore;
    }

    public function getNoteTime(): int
    {
        return $this->noteTime;
    }

    /**
     * 主入口：转换一个 txt 文本
     *
     * @param string $content  原始字节流
     * @param string $srcName  源文件名（用于记录 / 调试，不影响逻辑）
     * @return bool
     */
    public function convert(string $content, string $srcName = ''): bool
    {
        $this->output   = '';
        $this->error    = '';
        $this->outBytes = 0;

        if ($content === '') {
            $this->error = self::ERR_INVALID_FILE;
            return false;
        }

        // 1. 文件头判断 IsUTF16（仅 LE 模式）
        $isUtf16 = $this->isUtf16($content);
        if (!$isUtf16) {
            // 与 C++ 行为一致：非 UTF-16 直接判定格式错误
            $this->error = self::ERR_INVALID_UTF16;
            return false;
        }

        // 转为 PHP 内部字符串，UTF-16 LE 字节流 -> UTF-8 字符串
        $text = $this->utf16LeToUtf8($content);
        if ($text === '' || $text === null) {
            $this->error = self::ERR_READ_FAIL;
            return false;
        }

        // 2. ABC 谱判断 IsABCScore
        $bJsonScore = !$this->isABCScore($text);
        $pos = 0;

        // 3. 读取 BPM
        $bpm = 0;
        if ($bJsonScore) {
            $bpm = $this->readBetweenStrAndChar($text, '"bpm":', ',', $pos);
        } else {
            // ABC 谱：跳过 <DontCopyThisLine>(18 字符) 后的第一个 token 即 BPM
            $pos = 18;
            $bpm = $this->readStrIgnoreSpace($text, $pos);
        }
        $bpm = (int)trim((string)$bpm);

        if ($bpm <= 0 || $bpm > self::MILLISECONDS_PER_MINUTE) {
            $this->error = self::ERR_INVALID_BPM;
            return false;
        }

        $lNoteTime = intdiv(self::MILLISECONDS_PER_MINUTE, $bpm);
        $this->noteTime   = $lNoteTime;
        $this->bJsonScore = $bJsonScore;

        // 4. 输出 var time / var time2 / var time4
        // 参考 Main.cpp::fprintf c8VarCode[0..5]
        $rand = (int)$this->config['random_offset'];
        $this->append('var time=' . ($lNoteTime * 1) . ';');
        $this->append('var time2=' . ($lNoteTime * 2) . ';');
        $this->append('var time4=' . ($lNoteTime * 4) . ';');
        $this->append('var pressoffset=' . ($rand * 1) . ';');
        $this->append('var pressoffset2=' . ($rand * 2) . ';');

        // 5. 基础代码 c8BaseCode
        $this->append('var stop=1;');
        $this->append('var speedControl=1;');
        $this->append('var zuobiaoPath = "/sdcard/Download/SkyMsToJs/zuobiao.txt";');
        $this->append('if (files.exists(zuobiaoPath)) {eval(files.read(zuobiaoPath));}else{setScreenMetrics(1080, 2340);var x=[780,975,1170,1365,1560];var y=[215,410,605];}');

        // 6. 随机偏移 / 无随机偏移
        if ($this->config['use_random_offset']) {
            $this->writeCodeArray(self::randomCode(), "\n");
        } else {
            $this->writeCodeArray(self::noRandomCode(), "\n");
        }

        // 7. 窗口 / 无窗口
        if ($this->config['have_window']) {
            $this->writeCodeArray(self::windowCode(), "\n");
        } else {
            // c8NoWindowCode 共 4 行，前 3 行直接输出，最后 sleep(NO_WINDOW_START_TIME) 模板由参数替换
            $arr = self::noWindowCode();
            $cnt = count($arr);
            for ($i = 0; $i < $cnt - 2; $i++) {
                $this->append($arr[$i] . "\n");
            }
            $this->append($arr[3] . $this->config['no_window_start_time'] . $arr[4] . "\n");
        }

        // 8. 跳到 songNotes 起点
        if ($bJsonScore) {
            $pos = mb_strpos($text, '"songNotes":');
            if ($pos === false) {
                $this->error = '未找到 songNotes 数据';
                return false;
            }
            $text = mb_substr($text, $pos + mb_strlen('"songNotes":'));
        } else {
            // JumpFileLine：丢弃到行尾
            $pos = mb_strpos($text, "\n");
            if ($pos === false) {
                $this->error = 'ABC 谱格式错误';
                return false;
            }
            $text = mb_substr($text, $pos + 1);
        }

        // 9. 核心转换
        if ($bJsonScore) {
            $this->json2Autojs($text, $lNoteTime, (bool)$this->config['ignore_score_begin']);
        } else {
            $this->abc2Autojs($text, $lNoteTime, (bool)$this->config['ignore_score_begin']);
        }

        if ($this->outBytes === 0) {
            $this->error = self::ERR_EMPTY_OUTPUT;
            return false;
        }

        return true;
    }

    /**
     * 模板化转换入口
     *
     * - 'press'      : 与 convert() 行为一致，C++ 1:1 复刻的函数式按压
     * - 'press_new'  : gestures 列表 + 35ms 固定按压 + 悬浮窗（带 seekbar / 进度）
     * - 'long_press' : gestures 列表 + 按压时间 = 时间间隔（延音）+ 音量键控制
     *
     * @param string $content  原始字节流
     * @param string $srcName  源文件名
     * @param string $template 模板：press / press_new / long_press
     * @return bool
     */
    public function convertWithTemplate(string $content, string $srcName, string $template, ?string $srcTemplate = null): bool
    {
        if (!in_array($template, self::TEMPLATES, true)) {
            $this->error = self::ERR_INVALID_TEMPLATE . ': ' . $template;
            return false;
        }

        // press 模板 + txt 源 = 1:1 复刻 C++，完全走原 convert() 路径
        if ($template === 'press' && $srcTemplate === null) {
            return $this->convert($content, $srcName);
        }

        // 解析出 notes 列表
        $parsed = $this->parseToNotes($content, $srcName, $srcTemplate);
        if ($parsed === null) {
            return false;
        }
        [$bpm, $noteTime, $bJsonScore, $notes] = $parsed;
        if (empty($notes)) {
            $this->error = self::ERR_EMPTY_OUTPUT;
            return false;
        }

        // 渲染
        $this->output   = '';
        $this->outBytes = 0;
        $this->bJsonScore = $bJsonScore;
        $this->noteTime = $noteTime;

        if ($template === 'press') {
            $this->renderPressFromNotes($noteTime, $notes);
        } elseif ($template === 'press_new') {
            $this->renderPressNew($noteTime, $notes);
        } elseif ($template === 'long_press') {
            $this->renderLongPress($noteTime, $notes);
        }
        return true;
    }

    /**
     * 把源文件（txt 或 js）解析为 notes 中间表示。
     * 每个元素：['keys' => [int,...], 'pressTime' => int(毫秒), 'interval' => int(毫秒)]
     *
     * @param string $content
     * @param string $srcName  用于根据扩展名决定解析路径
     * @param string|null $srcTemplate 当源是 .js 时必填（press / press_new / long_press）
     * @return array|null [bpm, noteTime, bJsonScore, notes]
     */
    private function parseToNotes(string $content, string $srcName = '', ?string $srcTemplate = null): ?array
    {
        if ($content === '') {
            $this->error = self::ERR_INVALID_FILE;
            return null;
        }

        $ext = strtolower(pathinfo($srcName, PATHINFO_EXTENSION));
        if ($ext === 'js') {
            if ($srcTemplate === null || !in_array($srcTemplate, self::TEMPLATES, true)) {
                $this->error = 'JS 源文件必须指定 src_template（press / press_new / long_press）';
                return null;
            }
            $notes = $this->parseJsToNotes($content, $srcTemplate);
            if ($notes === null) {
                return null;
            }
            [$bpm, $noteTime, $notes] = $notes;
            return [$bpm, $noteTime, true, $notes];
        }

        // 默认按 .txt 处理
        if (!$this->isUtf16($content)) {
            $this->error = self::ERR_INVALID_UTF16;
            return null;
        }
        $text = $this->utf16LeToUtf8($content);
        if ($text === '' || $text === null) {
            $this->error = self::ERR_READ_FAIL;
            return null;
        }

        $bJsonScore = !$this->isABCScore($text);
        $pos = 0;

        $bpm = 0;
        if ($bJsonScore) {
            $bpm = $this->readBetweenStrAndChar($text, '"bpm":', ',', $pos);
        } else {
            $pos = 18;
            $bpm = $this->readStrIgnoreSpace($text, $pos);
        }
        $bpm = (int)trim((string)$bpm);
        if ($bpm <= 0 || $bpm > self::MILLISECONDS_PER_MINUTE) {
            $this->error = self::ERR_INVALID_BPM;
            return null;
        }
        $noteTime = intdiv(self::MILLISECONDS_PER_MINUTE, $bpm);
        $notes = [];

        if ($bJsonScore) {
            $raw = $this->parseJsonNotes($text, $noteTime, (bool)$this->config['ignore_score_begin']);
        } else {
            $raw = $this->parseAbcNotes($text, $noteTime, (bool)$this->config['ignore_score_begin']);
        }

        $notes = $this->groupNotesByTime($raw);
        return [$bpm, $noteTime, $bJsonScore, $notes];
    }

    /**
     * 解析 JSON 谱：每个 note 包含单个 key + 与上一音符的绝对时间差
     * 行为对应 C++ Json2Autojs：循环 (读 time, 读 key)，按 C++ 行为一次读一个 key
     */
    private function parseJsonNotes(string $text, int $lNoteTime, bool $bIgnoreScoreBeginTime): array
    {
        $pos = mb_strpos($text, '"songNotes":');
        if ($pos === false) {
            $this->error = '未找到 songNotes 数据';
            return [];
        }
        $text = mb_substr($text, $pos + mb_strlen('"songNotes":'));

        $lastTime = 0;
        $firstIter = true;
        $notes = [];

        while (true) {
            $nowTime = 0;
            if ($firstIter && $bIgnoreScoreBeginTime) {
                // skip
            } else {
                $time = $this->readBetweenStrAndChar($text, '"time":', ',', $pos);
                if ($time === null) {
                    return $notes;
                }
                $nowTime = (int)trim($time);
            }
            $firstIter = false;

            $diff = ($bIgnoreScoreBeginTime && $nowTime === 0) ? 0 : ($nowTime - $lastTime);
            $lastTime = $nowTime;

            $key = $this->readBetweenStrAndChar($text, '"key":"', '"', $pos);
            if ($key === null) {
                return $notes;
            }
            $idx = $this->noteKeyIndex($key);
            // noteKeyIndex 0..14 表示 1..15 琴键；返回 15 是空（idx == 数组长度-1）
            $keyNum = ($idx >= 0 && $idx < 15) ? ($idx + 1) : 0;
            $notes[] = [
                'time'      => $nowTime,
                'diff'      => $diff,
                'key'       => $keyNum,
            ];
        }
    }

    /**
     * 解析 ABC 谱：每个 token "AB" 包含一个或多个 key
     */
    private function parseAbcNotes(string $text, int $lNoteTime, bool $bIgnoreScoreBeginTime): array
    {
        $pos = 0;
        $notes = [];
        $pendingTok = null;
        $pendingInterval = 0;
        $cumulativeTime = 0;

        if ($bIgnoreScoreBeginTime) {
            while (true) {
                $tok = $this->readStrIgnoreSpace($text, $pos);
                if ($tok === null) {
                    return $notes;
                }
                if ($tok !== '.') {
                    $keys = $this->abcTokenToKeys($tok);
                    if (!empty($keys)) {
                        $notes[] = ['time' => 0, 'diff' => 0, 'keys' => $keys];
                    }
                    break;
                }
            }
        }

        while (true) {
            $lNoteInterval = 0;
            $tok = $pendingTok;
            if ($tok === null) {
                while (true) {
                    $tok = $this->readStrIgnoreSpace($text, $pos);
                    if ($tok === null) {
                        return $notes;
                    }
                    if ($tok === '.') {
                        $lNoteInterval++;
                    } else {
                        break;
                    }
                }
            }
            $keys = $this->abcTokenToKeys($tok);
            if (!empty($keys)) {
                $cumulativeTime += $lNoteInterval * $lNoteTime;
                $notes[] = [
                    'time' => $cumulativeTime,
                    'diff' => $lNoteInterval * $lNoteTime,
                    'keys' => $keys,
                ];
            }
            $pendingTok = null;
        }
    }

    /**
     * 把 notes（按单 key/单组）合并为按 time 分组的 groups
     * 每个 group: { keys: [int,...], interval: int(毫秒) }
     */
    private function groupNotesByTime(array $notes): array
    {
        $groups = [];
        foreach ($notes as $n) {
            if (isset($n['keys'])) {
                // ABC 谱：原本就是一组
                $groups[] = ['keys' => $n['keys'], 'interval' => $n['diff']];
            } else {
                // JSON 谱：单 key，按 time 合并
                $t = $n['time'];
                if (!isset($groups[$t])) {
                    $groups[$t] = ['keys' => [], 'interval' => $n['diff']];
                }
                $groups[$t]['keys'][] = $n['key'];
            }
        }
        // 重新计算 interval：第一个 group 的 interval 就是首 notes 的 diff，后续 group 的 interval = time[i] - time[i-1]
        $prevTime = null;
        $out = [];
        foreach ($groups as $t => $g) {
            $interval = $g['interval'];
            // diff 仅在第一组有意义，后续用时间差
            if ($prevTime !== null) {
                $interval = $t - $prevTime;
            }
            $prevTime = $t;
            $out[] = ['keys' => $g['keys'], 'interval' => $interval];
        }
        return $out;
    }

    /**
     * ABC token "AB" "CD" -> keys 列表
     *  对应 abc2NoteKey：a*5 + b - 1（0..14），转换 1..15
     */
    private function abcTokenToKeys(string $tok): array
    {
        $tok = strtoupper($tok);
        $len = strlen($tok);
        $keys = [];
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $pair = substr($tok, $i, 2);
            if (strlen($pair) < 2) {
                break;
            }
            $a = ord($pair[0]) - ord('A');
            $b = (ord($pair[1]) - ord('0')) - 1;
            $idx = $a * 5 + $b;
            if ($idx >= 0 && $idx < 15) {
                $keys[] = $idx + 1;
            }
        }
        return $keys;
    }

    /**
     * press_new 模板渲染：gestures 列表 + 35ms 按压 + 悬浮窗
     */
    private function renderPressNew(int $noteTime, array $notes): void
    {
        $t0 = $noteTime * 2;       // 默认间隔-
        $t1 = $noteTime;            // 较短间隔~
        $t2 = $noteTime * 4;        // 较长间隔
        $t3 = 100;                  // 固定间隔
        $pT = 35;                   // 按压时间

        // 头部
        $this->append("//以下为乐谱转js专有模板\n");
        $this->append("let t0 = $t0,//默认间隔-\n");
        $this->append("    t1 = $t1,//较短间隔~\n");
        $this->append("    t2 = $t2,//较长间隔,\n");
        $this->append("    t3 = $t3,//固定间隔*\n");
        $this->append("    pT = $pT,//默认按压时间\n");
        $this->append("    sW = true;//是否显示悬浮按钮\n");
        $this->append("let s = 1, progressNow=0,speedControl = 1,xy = [],zuobiaoPath = \"/sdcard/脚本/zuobiao21.txt\";\n");
        $this->append("if (files.exists(zuobiaoPath)) { //如果机型适配过\n");
        $this->append("    eval(files.read(zuobiaoPath)); //快速适配分辨率\n");
        $this->append("} else {\n");
        $this->append("    setScreenMetrics(1080, 2340); //默认分辨率，以下按键位置基于此分辨率\n");
        $this->append("    var x = [410, 680, 950, 1220, 1490, 1760, 2030];\n");
        $this->append("    var y = [980, 870, 760];\n");
        $this->append("    for (let i = 0; i < 21; i++) {\n");
        $this->append("        xy.push(x[i % 7], y[parseInt(i / 7)])\n");
        $this->append("    }\n");
        $this->append("};\n");
        $this->append("function ran(c) {c=c|20;return Math.random() * c - c / 2};\n");
        $this->append("function pre(item) {\n");
        $this->append("    let items = [],\n");
        $this->append("        keys = item[0],//按下的琴键\n");
        $this->append("        pressTime = item[1],//按压时间\n");
        $this->append("        sleepTime = item[2]-item[1]>0?item[2]-item[1]:0;//停顿时间\n");
        $this->append("    for (let index in keys) {\n");
        $this->append("        let id = keys[index],\n");
        $this->append("            x = xy[id * 2 - 2] + ran(),\n");
        $this->append("            y = xy[id * 2 - 1] + ran();\n");
        $this->append("        items.push([pressTime / speedControl, [x, y],[x, y]])\n");
        $this->append("    };\n");
        $this->append("    if(items.length>0){gestures.apply(null, items)};\n");
        $this->append("    sleep(sleepTime / speedControl);\n");
        $this->append("}\n");

        // list 数组
        $this->append("list = [\n");
        $n = count($notes);
        for ($i = 0; $i < $n; $i++) {
            $n2 = $notes[$i];
            $keys = $n2['keys'];
            $interval = $n2['interval'];
            $tExpr = $this->intervalToTExpr($interval, $noteTime);
            $keysStr = '[' . implode(',', $keys) . ']';
            $this->append('[' . $keysStr . ',pT,' . $tExpr . '],\n');
        }
        $this->append('[[],pT,0],\n');
        $this->append("]\n");

        // 悬浮窗
        $this->append("if(sW==true){\n");
        $this->append("    sleep(100);var window = floaty.window('<frame><vertical><button id=\"btn\" text=\"暂停\"/><horizontal><button id=\"speedLow\" text=\"减速\" w=\"80\"/><button id=\"speedHigh\" text=\"加速\"w=\"80\"/></horizontal><horizontal><button id=\"speed\" text=\"x1\" w=\"80\"/><button id=\"stop\" text=\"停止\"w=\"80\"/></horizontal><seekbar id=\"seek\"/><text text=\"00:00/00:00\" background=\"#FF5A5A5C\" gravity=\"center\" id=\"jd\"/></vertical></frame>');window.exitOnClose();\n");
        $this->append("    window.btn.click(()=>{if (window.btn.getText() != '暂停') {s = 1;window.btn.setText('暂停')} else {s = 0;window.btn.setText('继续')}})\n");
        $this->append("    window.speedHigh.click(()=>{speedControl=(speedControl*10+1)/10;window.speed.setText(\"x\"+speedControl)})\n");
        $this->append("    window.speedLow.click(()=>{if(speedControl<=0.1){return};speedControl=(speedControl*10-1)/10;window.speed.setText(\"x\"+speedControl)})\n");
        $this->append("    window.speed.click(()=>{speedControl=1;window.speed.setText(\"x\"+speedControl)})\n");
        $this->append("    window.stop.click(()=>{engines.stopAll()})\n");
        $this->append("    window.seek.setMax(list.length)\n");
        $this->append("    window.seek.setOnSeekBarChangeListener(new android.widget.SeekBar.OnSeekBarChangeListener({\tonProgressChanged: function(sb, p) {progressNow=p;window.jd.setText(timeSum(p)+\"/\"+timeSum(sb.getMax()));\t},}))\n");
        $this->append("    function timeSum(p){let timeTotal=0;for(var i=0;i<p;i++){timeTotal+=(list[i][1]==pT?list[i][2]:list[i][1]+list[i][2])/speedControl;}let minute = 0;let second = timeTotal/1000;if (second>59) {minute = parseInt(second / 60);second = second % 60;};return  (Array(2).join(0) + minute.toFixed(0)).slice(-2)+\":\"+ (Array(2).join(0) + second.toFixed(0)).slice(-2)}\n");
        $this->append("    window.jd.setText(\"00:00/\"+timeSum(list.length))\n");
        $this->append("}\n");

        // 主循环
        $this->append("for(var i=0;i<=list.length;i++){\n");
        $this->append("   if (sW==true){\n");
        $this->append("       if (i!=progressNow){i=progressNow;}else{window.seek.setProgress(i)};\n");
        $this->append("       if (i>=list.length||i<=0){i=s=progressNow=0;window.btn.setText('继续');window.seek.setProgress(0);while (s != 1){sleep(100)};}\n");
        $this->append("   }else{\n");
        $this->append("       if (i>=list.length-1){exit()};\n");
        $this->append("   };\n");
        $this->append("   pre(list[i]);progressNow++;\n");
        $this->append("   while (s != 1){sleep(100)};\n");
        $this->append("}\n");
    }

    /**
     * long_press 模板渲染：gestures 列表 + 按压时间 = 间隔时间（延音）
     */
    private function renderLongPress(int $noteTime, array $notes): void
    {
        $t0 = $noteTime * 2;
        $t1 = $noteTime;
        $t2 = $noteTime * 4;
        $t3 = 100;

        $this->append("//以下为乐谱转js专有模板\n");
        $this->append("let t0 = $t0,//默认间隔-\n");
        $this->append("    t1 = $t1,//较短间隔~\n");
        $this->append("    t2 = $t2,//较长间隔,\n");
        $this->append("    t3 = $t3,//固定间隔*\n");
        $this->append("    pT = 35,//默认按压时间\n");
        $this->append("    sW = true;//是否显示悬浮按钮\n");
        $this->append("let s = 1, progressNow=0,speedControl = 1,xy = [],zuobiaoPath = \"/sdcard/脚本/zuobiao21.txt\";\n");
        $this->append("if (files.exists(zuobiaoPath)) { //如果机型适配过\n");
        $this->append("    eval(files.read(zuobiaoPath)); //快速适配分辨率\n");
        $this->append("} else {\n");
        $this->append("    setScreenMetrics(1080, 2340); //默认分辨率，以下按键位置基于此分辨率\n");
        $this->append("    var x = [410, 680, 950, 1220, 1490, 1760, 2030];\n");
        $this->append("    var y = [980, 870, 760];\n");
        $this->append("    for (let i = 0; i < 21; i++) {\n");
        $this->append("        xy.push(x[i % 7], y[parseInt(i / 7)])\n");
        $this->append("    }\n");
        $this->append("};\n");
        $this->append("function ran(c) {c=c|20;return Math.random() * c - c / 2};\n");
        $this->append("function pre(item) {\n");
        $this->append("    let items = [],\n");
        $this->append("        keys = item[0],//按下的琴键\n");
        $this->append("        pressTime = item[1],//按压时间\n");
        $this->append("        sleepTime = item[2]-item[1]>0?item[2]-item[1]:0;//停顿时间\n");
        $this->append("    for (let index in keys) {\n");
        $this->append("        let id = keys[index],\n");
        $this->append("            x = xy[id * 2 - 2] + ran(),\n");
        $this->append("            y = xy[id * 2 - 1] + ran();\n");
        $this->append("        items.push([pressTime / speedControl, [x, y],[x, y]])\n");
        $this->append("    };\n");
        $this->append("    if(items.length>0){gestures.apply(null, items)};\n");
        $this->append("    sleep(sleepTime / speedControl);\n");
        $this->append("}\n");

        // list 数组 - 按压时间 = t 索引（长按 = 间隔时长）
        $this->append("list = [\n");
        $n = count($notes);
        for ($i = 0; $i < $n; $i++) {
            $n2 = $notes[$i];
            $keys = $n2['keys'];
            $interval = $n2['interval'];
            // 长按：pressTime = t 索引变量（t0/t1/t2）而不是数字
            $tExpr = $this->intervalToTExpr($interval, $noteTime);
            $keysStr = '[' . implode(',', $keys) . ']';
            $this->append('[' . $keysStr . ',' . $tExpr . ',0],\n');
        }
        $this->append('[[],0,0],\n');
        $this->append("]\n");

        // 音量键控制
        $this->append("alert(\"延音模式：按“音量➕”停止运行；按“音量➖”暂停、继续运行\");\n");
        $this->append("function zt(){if (s != 1) {s = 1; toast('继续播放')}else{s = 0;toast('已暂停')}};\n");
        $this->append("threads.start(function(){events.observeKey();events.onKeyDown(\"volume_up\",function(event){toast(\"已停止\");engines.stopAll()});events.onKeyDown(\"volume_down\",function(event){zt()});});\n");

        // 主循环
        $this->append("for(var i=0;i<=list.length;i++){\n");
        $this->append("   if (i>=list.length-1){exit()};\n");
        $this->append("   pre(list[i]);progressNow++;\n");
        $this->append("   while (s != 1){sleep(100)};\n");
        $this->append("}\n");
    }

    /**
     * 把毫秒间隔映射为 t 索引：1 -> t1, 2 -> t0, 4 -> t2
     * t0=2*lNoteTime, t1=lNoteTime, t2=4*lNoteTime
     * 与 C++ timeCode(0)=t1 timeCode(1)=t2 timeCode(2)=t4 保持一致
     */
    private function intervalToT(int $intervalMs, int $lNoteTime): int
    {
        if ($intervalMs <= 0) {
            return 1; // t1
        }
        $ratio = (int)round($intervalMs / $lNoteTime);
        if ($ratio <= 1) return 1; // t1
        if ($ratio == 2) return 0; // t0
        if ($ratio >= 4) return 2; // t2
        return 1;
    }

    /**
     * 把毫秒间隔映射为 t 表达式（字符串），支持 t0+t1 这种组合写法
     * 拆分规则：从大到小（t2=4x / t0=2x / t1=1x），贪心
     *  模板 t 索引含义：t0=2*lNoteTime, t1=1*lNoteTime, t2=4*lNoteTime
     *
     * 用法：list 行的第三个字段（press_new）或第二个字段（long_press）
     */
    private function intervalToTExpr(int $intervalMs, int $lNoteTime): string
    {
        if ($intervalMs <= 0) {
            return '0';
        }
        $r = (int)round($intervalMs / $lNoteTime);
        $parts = [];
        // 4x (t2)
        $c = intdiv($r, 4);
        for ($k = 0; $k < $c; $k++) $parts[] = 't2';
        $r = $r % 4;
        // 2x (t0)
        $c = intdiv($r, 2);
        for ($k = 0; $k < $c; $k++) $parts[] = 't0';
        $r = $r % 2;
        // 1x (t1)
        for ($k = 0; $k < $r; $k++) $parts[] = 't1';
        if (empty($parts)) {
            return '0';
        }
        return implode('+', $parts);
    }

    /* =========================================================
     * 转换函数 - 完全复刻 SkyStudio2Autojs.cpp
     * =========================================================*/

    /**
     * 对应 Json2Autojs
     */
    private function json2Autojs(string $text, int $lNoteTime, bool $bIgnoreScoreBeginTime): void
    {
        $lastTime = 0;
        $lNoteTimeArr = [$lNoteTime * 1, $lNoteTime * 2, $lNoteTime * 4];
        $pos = 0;
        $firstIter = true;

        while (true) {
            $nowTime = 0;

            // bIgnoreScoreBeginTime 时第一个 time 已经被读过，不再次读
            if ($firstIter && $bIgnoreScoreBeginTime) {
                // nothing
            } else {
                $time = $this->readBetweenStrAndChar($text, '"time":', ',', $pos);
                if ($time === null) {
                    return;
                }
                $nowTime = (int)trim($time);
            }
            $firstIter = false;

            // bIgnoreScoreBeginTime 第一次迭代不输出时间差
            if ($bIgnoreScoreBeginTime && $nowTime === 0) {
                // skip diff output
            } else {
                $diffTime = $nowTime - $lastTime;
                if ($diffTime !== 0) {
                    for ($i = 2; $i >= 0; --$i) {
                        $count = intdiv($diffTime, $lNoteTimeArr[$i]);
                        for ($j = 0; $j < $count; $j++) {
                            $this->append(self::timeCode($i));
                        }
                        $diffTime = $diffTime % $lNoteTimeArr[$i];
                    }
                }
                $lastTime = $nowTime;
            }

            // 读 key
            $key = $this->readBetweenStrAndChar($text, '"key":"', '"', $pos);
            if ($key === null) {
                return;
            }
            $noteIdx = $this->noteKeyIndex($key);
            $this->append(self::noteCode($noteIdx));
        }
    }

    /**
     * 对应 ABC2Autojs
     */
    private function abc2Autojs(string $text, int $lNoteTime, bool $bIgnoreScoreBeginTime): void
    {
        $lNotelIntervalArr = [1, 2, 4];
        $pos = 0;
        $pendingTok = null;
        $pendingNoteInterval = 0;

        if ($bIgnoreScoreBeginTime) {
            // 一直读取到非 '.'
            while (true) {
                $tok = $this->readStrIgnoreSpace($text, $pos);
                if ($tok === null) {
                    return;
                }
                if ($tok !== '.') {
                    // 找到第一个非 . 的 token，立即处理按键
                    $this->processAbcToken($tok);
                    break;
                }
            }
        }

        while (true) {
            $lNoteInterval = 0;
            $tok = $pendingTok;

            if ($tok === null) {
                // 一直读取到非 '.'
                while (true) {
                    $tok = $this->readStrIgnoreSpace($text, $pos);
                    if ($tok === null) {
                        return;
                    }
                    if ($tok === '.') {
                        $lNoteInterval++;
                    } else {
                        break;
                    }
                }

                // 输出等待函数（与 C++ 一致）
                for ($i = 2; $i >= 0; --$i) {
                    $count = intdiv($lNoteInterval, $lNotelIntervalArr[$i]);
                    for ($j = 0; $j < $count; $j++) {
                        $this->append(self::timeCode($i));
                    }
                    $lNoteInterval = $lNoteInterval % $lNotelIntervalArr[$i];
                }
            }

            $this->processAbcToken($tok);
        }
    }

    private function processAbcToken(string $tok): void
    {
        $len = strlen($tok);
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $pair = substr($tok, $i, 2);
            $noteIdx = $this->abc2NoteKey($pair);
            $this->append(self::noteCode($noteIdx));
        }
    }

    /**
     * 对应 ABC2NoteKey
     *  (wcABCStr[0] - 'A') * 5 + (wcABCStr[1] - '0') - 1
     *  越界返回数组最后一个（空字符串）
     */
    private function abc2NoteKey(string $abc): int
    {
        $abc = strtoupper($abc);
        $a = ord($abc[0]) - ord('A');
        $b = (ord($abc[1]) - ord('0')) - 1;
        $key = $a * 5 + $b;

        $cnt = count(self::noteFuncCode());
        if ($key < 0 || $key >= $cnt) {
            return $cnt - 1;
        }
        return $key;
    }

    private function noteKeyIndex(string $key): int
    {
        // 类似 C++ wcstol(&wcReadStr[4], NULL, 10)
        // 原始 key 形如 "2Key7"，第 4 个字符起为数字
        if (strlen($key) < 5) {
            return 0;
        }
        $idx = (int)substr($key, 4);
        $cnt = count(self::noteFuncCode());
        if ($idx < 0 || $idx >= $cnt) {
            return $cnt - 1;
        }
        return $idx;
    }

    /* =========================================================
     * 文件读取辅助函数 - 复刻 FileOperation.cpp
     * =========================================================*/

    /**
     * IsUTF16: 文件头 0xFEFF（小端）
     */
    private function isUtf16(string $bytes): bool
    {
        if (strlen($bytes) < 2) {
            return false;
        }
        $b0 = ord($bytes[0]);
        $b1 = ord($bytes[1]);
        // LE: 0xFF 0xFE
        return ($b0 === 0xFF && $b1 === 0xFE);
    }

    /**
     * IsABCScore: 前 18 个 wchar_t 等于 "<DontCopyThisLine>"
     */
    private function isABCScore(string $text): bool
    {
        return str_starts_with($text, '<DontCopyThisLine>');
    }

    /**
     * JumpFilePointerAfterStr + ReadFileContentsBeforeCharEncountered
     * 即 找一段 [start, endChar) 内容并返回
     *
     * @return string|null
     */
    private function readBetweenStrAndChar(string &$text, string $startStr, string $endChar, int &$pos = 0): ?string
    {
        $len = strlen($text);
        $startLen = strlen($startStr);

        $found = strpos($text, $startStr, $pos);
        if ($found === false) {
            return null;
        }
        $cursor = $found + $startLen;

        $end = strpos($text, $endChar, $cursor);
        if ($end === false) {
            $sub = substr($text, $cursor);
            $pos = $len;
            return $sub;
        }

        $sub = substr($text, $cursor, $end - $cursor);
        $pos = $end + 1;
        return $sub;
    }

    /**
     * ReadStrIgnoreSpace: 忽略空格读取下一个 token
     */
    private function readStrIgnoreSpace(string &$text, int &$pos = 0): ?string
    {
        $len = strlen($text);
        // 跳过空格
        while ($pos < $len && (ord($text[$pos]) <= 32 || $text[$pos] === ' ')) {
            $pos++;
        }
        if ($pos >= $len) {
            return null;
        }

        $start = $pos;
        // 找到下一个空格
        while ($pos < $len && ord($text[$pos]) > 32) {
            $pos++;
        }
        return substr($text, $start, $pos - $start);
    }

    /**
     * 兼容旧调用（不使用游标）
     */
    private function readBetweenStrAndCharOld(string $text, string $startStr, string $endChar): ?string
    {
        $pos = 0;
        return $this->readBetweenStrAndChar($text, $startStr, $endChar, $pos);
    }

    private function readStrIgnoreSpaceOld(string $text): ?string
    {
        $pos = 0;
        return $this->readStrIgnoreSpace($text, $pos);
    }

    /* =========================================================
     * 代码模板 - 1:1 复刻 CodeResource.hpp
     * =========================================================*/

    private static function noteFuncCode(): array
    {
        return [
            'c4();', 'd4();', 'e4();', 'f4();', 'g4();',
            'a4();', 'b4();', 'c5();', 'd5();', 'e5();',
            'f5();', 'g5();', 'a5();', 'b5();', 'c6();',
            '',
        ];
    }

    private static function timeFuncCode(): array
    {
        return ['t1();', 't2();', 't4();'];
    }

    private static function randomCode(): array
    {
        return [
            'function ran() {return Math.random()*pressoffset2-pressoffset;}',
            'function c4() {press(x[0]+ran(),y[0]+ran(),1);}',
            'function d4() {press(x[1]+ran(),y[0]+ran(),1);}',
            'function e4() {press(x[2]+ran(),y[0]+ran(),1);}',
            'function f4() {press(x[3]+ran(),y[0]+ran(),1);}',
            'function g4() {press(x[4]+ran(),y[0]+ran(),1);}',
            'function a4() {press(x[0]+ran(),y[1]+ran(),1);}',
            'function b4() {press(x[1]+ran(),y[1]+ran(),1);}',
            'function c5() {press(x[2]+ran(),y[1]+ran(),1);}',
            'function d5() {press(x[3]+ran(),y[1]+ran(),1);}',
            'function e5() {press(x[4]+ran(),y[1]+ran(),1);}',
            'function f5() {press(x[0]+ran(),y[2]+ran(),1);}',
            'function g5() {press(x[1]+ran(),y[2]+ran(),1);}',
            'function a5() {press(x[2]+ran(),y[2]+ran(),1);}',
            'function b5() {press(x[3]+ran(),y[2]+ran(),1);}',
            'function c6() {press(x[4]+ran(),y[2]+ran(),1);}',
        ];
    }

    private static function noRandomCode(): array
    {
        return [
            'function c4() {press(x[0],y[0],1);}',
            'function d4() {press(x[1],y[0],1);}',
            'function e4() {press(x[2],y[0],1);}',
            'function f4() {press(x[3],y[0],1);}',
            'function g4() {press(x[4],y[0],1);}',
            'function a4() {press(x[0],y[1],1);}',
            'function b4() {press(x[1],y[1],1);}',
            'function c5() {press(x[2],y[1],1);}',
            'function d5() {press(x[3],y[1],1);}',
            'function e5() {press(x[4],y[1],1);}',
            'function f5() {press(x[0],y[2],1);}',
            'function g5() {press(x[1],y[2],1);}',
            'function a5() {press(x[2],y[2],1);}',
            'function b5() {press(x[3],y[2],1);}',
            'function c6() {press(x[4],y[2],1);}',
        ];
    }

    private static function windowCode(): array
    {
        return [
            'var window = floaty.window(<frame><vertical><button id="btn" text=\'开始\'/><horizontal><button id="speedLow" text=\'减速\' w="80"/><button id="speedHigh" text=\'加速\' w="80"/></horizontal><horizontal><button id="speed" text=\'1.0x\' w="80"/><button id="stop" text=\'停止\' w="80"/></horizontal></vertical></frame>);',
            'window.exitOnClose();',
            'window.btn.click(()=>{if(stop) {stop = 0;window.btn.setText(\'暂停\');}else{stop = 1;window.btn.setText(\'继续\');}})',
            'window.speedHigh.click(()=>{speedControl=(speedControl*10+1)/10;window.speed.setText(speedControl+\'x\');})',
            'window.speedLow.click(()=>{if(speedControl<=0.1){return;}speedControl=(speedControl*10-1)/10;window.speed.setText(speedControl+\'x\');})',
            'window.speed.click(()=>{speedControl=1;window.speed.setText(speedControl+\'x\');})',
            'window.stop.click(()=>{engines.stopAll();})',
            'function start() {while (stop) {sleep(100);}}',
            'function t1() {while (stop) {sleep(100)}sleep(time/speedControl);}',
            'function t2() {while (stop) {sleep(100)}sleep(time2/speedControl);}',
            'function t4() {while (stop) {sleep(100)}sleep(time4/speedControl);}',
            'start();',
        ];
    }

    private static function noWindowCode(): array
    {
        return [
            'function t1() {sleep(time);}',
            'function t2() {sleep(time2);}',
            'function t4() {sleep(time4);}',
            'sleep(', ');',
        ];
    }

    private static function noteCode(int $idx): string
    {
        $arr = self::noteFuncCode();
        return $arr[$idx] ?? '';
    }

    private static function timeCode(int $idx): string
    {
        $arr = self::timeFuncCode();
        return $arr[$idx] ?? '';
    }

    private function writeCodeArray(array $arr, string $suffix = ''): void
    {
        foreach ($arr as $line) {
            $this->append($line . $suffix);
        }
    }

    private function append(string $s): void
    {
        $this->output .= $s;
        $this->outBytes += strlen($s);
    }

    /* =========================================================
     * 编码转换：UTF-16 LE 字节流 -> UTF-8 PHP 字符串
     * 对应 FileOperation.cpp::my_fgetwc（每次读 2 字节 LE 拼成 wchar_t）
     * =========================================================*/

    private function utf16LeToUtf8(string $bytes): ?string
    {
        // 跳过 BOM (FF FE)
        if (strlen($bytes) >= 2 && $bytes[0] === "\xFF" && $bytes[1] === "\xFE") {
            $bytes = substr($bytes, 2);
        }
        $out = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
        return $out === false ? null : $out;
    }

    /* =========================================================
     * JS 源反解析（支持 press / press_new / long_press 三种输出）
     *  用于 .js → .js 模板互转
     * =========================================================*/

    /**
     * 从 .js 字符串反解析 notes 中间表示
     * @return array|null [bpm, noteTime, notes]
     */
    private function parseJsToNotes(string $js, string $srcTemplate): ?array
    {
        $noteTime = $this->extractNoteTimeFromJs($js);
        if ($noteTime === null || $noteTime <= 0) {
            $this->error = 'JS 源文件未能识别 lNoteTime（缺少 var time/let t0 等 BPM 字段）';
            return null;
        }

        $notes = match ($srcTemplate) {
            'press'      => $this->parseJsPress($js, $noteTime),
            'press_new'  => $this->parseJsGestures($js, $noteTime),
            'long_press' => $this->parseJsGestures($js, $noteTime),
            default      => null,
        };
        if ($notes === null) {
            $this->error = 'JS 源文件反解析失败（模板=' . $srcTemplate . '）';
            return null;
        }
        $bpm = (int)round(self::MILLISECONDS_PER_MINUTE / $noteTime);
        return [$bpm, $noteTime, $notes];
    }

    /**
     * 从 JS 中提取 lNoteTime（毫秒）
     *  兼容 press（var time=NNN）与 press_new/long_press（let t0=424, t1=212, t2=848 多行声明）两种写法
     *  lNoteTime = t1 (较短间隔)
     */
    private function extractNoteTimeFromJs(string $js): ?int
    {
        // 优先：press 模板的 var time=NNN
        if (preg_match('/var\s+time\s*=\s*(\d+)/i', $js, $m)) {
            return (int)$m[1];
        }
        // 其次：press_new/long_press 的 let t0..t2 声明（在多行注释 // 块中，t0..t2 由逗号/换行分隔）
        // 整个 let 块以 "let t" 开头，以分号 ; 结束
        if (preg_match('/let\s+t0[\s\S]*?;/i', $js, $mLet)) {
            $block = $mLet[0];
            if (preg_match('/\bt1\s*=\s*(\d+)/', $block, $m1)) {
                return (int)$m1[1];
            }
        }
        return null;
    }

    /**
     * 反解析 press 模板的 .js
     *  - 抽取 start() 之后的全部 key()/time() 调用
     *  - 累积 t1/t2/t4 转为绝对时间（毫秒）
     *  - 按 time 合并 keys
     */
    private function parseJsPress(string $js, int $noteTime): ?array
    {
        // 提取 start() 之后的主体（去掉前面的 var/function 定义）
        $pos = strpos($js, 'start();');
        if ($pos === false) {
            // 也可能无 start() 调用，直接全文搜
            $pos = 0;
        } else {
            $pos += 7; // 跳过 "start();"
        }
        $body = substr($js, $pos);
        // 提取所有 a4(); t1(); b4(); 形式
        if (!preg_match_all('/([a-g][4-6])\s*\(\s*\)\s*;|t([124])\s*\(\s*\)\s*;/i', $body, $matches, PREG_SET_ORDER)) {
            return null;
        }
        $cumulativeMs = 0;
        $events = []; // ['time' => ms, 'key' => 1..15]
        foreach ($matches as $m) {
            if (!empty($m[2])) {
                // t1/t2/t4
                $mult = (int)$m[2];
                $cumulativeMs += $noteTime * ($mult === 1 ? 1 : ($mult === 2 ? 2 : 4));
            } else {
                // 琴键：a-g + 4-6 → 1..15
                $events[] = ['time' => $cumulativeMs, 'key' => $this->jsKeyToNum($m[1])];
            }
        }
        if (empty($events)) {
            return null;
        }
        // 按 time 分组，keys 累积
        $byTime = [];
        foreach ($events as $ev) {
            $t = $ev['time'];
            if (!isset($byTime[$t])) {
                $byTime[$t] = ['time' => $t, 'keys' => []];
            }
            $byTime[$t]['keys'][] = $ev['key'];
        }
        $sorted = array_values($byTime);
        usort($sorted, function ($a, $b) { return $a['time'] <=> $b['time']; });

        // 转 groupNotesByTime 风格
        $groups = [];
        $prev = null;
        foreach ($sorted as $s) {
            $interval = $prev === null ? $s['time'] : ($s['time'] - $prev);
            $groups[] = ['keys' => $s['keys'], 'interval' => $interval];
            $prev = $s['time'];
        }
        return $groups;
    }

    /**
     * 反解析 press_new / long_press 模板的 .js
     *  列表项形如：[[1,5,10],pT,t0] (press_new) 或 [[1,5,10],t0,0] (long_press)
     *  - press_new 语义：item[1]=pressTime（固定 pT=35），item[2]=sleepTime（=interval+pressTime）
     *  - long_press 语义：item[1]=pressTime（=t0/t1/t2，按 interval 走），item[2]=0
     */
    private function parseJsGestures(string $js, int $noteTime): ?array
    {
        // 抽出 t0/t1/t2 数值映射（从 let t0 = .. 一直到分号）
        $t0 = $t1 = $t2 = 0;
        if (preg_match('/let\s+t0[\s\S]*?;/i', $js, $mLet)) {
            $block = $mLet[0];
            if (preg_match('/\bt0\s*=\s*(\d+)/', $block, $m0)) { $t0 = (int)$m0[1]; }
            if (preg_match('/\bt1\s*=\s*(\d+)/', $block, $m1)) { $t1 = (int)$m1[1]; }
            if (preg_match('/\bt2\s*=\s*(\d+)/', $block, $m2)) { $t2 = (int)$m2[1]; }
        }

        // 抽出 list = [ ... ]; 区块：用括号配对扫描，而非依赖正则
        if (!preg_match('/list\s*=\s*\[/i', $js, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]); // 跳过 "list = ["
        $len = strlen($js);
        $depth = 1;
        $i = $start;
        while ($i < $len && $depth > 0) {
            $c = $js[$i];
            if ($c === '[') $depth++;
            elseif ($c === ']') $depth--;
            elseif ($c === '"' || $c === "'") {
                $quote = $c;
                $i++;
                while ($i < $len && $js[$i] !== $quote) {
                    if ($js[$i] === '\\') $i++;
                    $i++;
                }
            }
            elseif ($c === '/' && $i + 1 < $len && $js[$i + 1] === '/') {
                while ($i < $len && $js[$i] !== "\n") $i++;
            }
            $i++;
        }
        if ($depth !== 0) {
            return null;
        }
        $listBody = substr($js, $start, $i - $start - 1);

        // 抽取每一条 [ [..], EXPR, EXPR ]
        if (!preg_match_all('/\[\s*(\[[^\]]*\])\s*,\s*([^,\]]+?)\s*,\s*([^\]]+?)\s*\](?=,|\s|\n|$)/u', $listBody, $matches, PREG_SET_ORDER)) {
            return null;
        }
        $groups = [];
        $isPressNew = (strpos($js, 'pT = 35') !== false) && $this->containsPTSleep($js);
        $isLongPress = (strpos($js, '延音模式') !== false) || (strpos($js, 'volume_up') !== false);
        foreach ($matches as $m) {
            $keysStr  = $m[1];
            $pressStr = trim($m[2]);
            $sleepStr = trim($m[3]);
            // 解析 keys 数组
            preg_match_all('/(\d+)/', $keysStr, $km);
            $keys = array_map('intval', $km[1] ?? []);
            if (empty($keys)) {
                // 跳过空 list 项（long_press 末尾的占位 [[],0,0]）
                continue;
            }
            // 解析 pressTime / sleepTime
            $pressMs = $this->evalTExpr($pressStr, $t0, $t1, $t2, $noteTime);
            $sleepMs = $this->evalTExpr($sleepStr, $t0, $t1, $t2, $noteTime);
            if ($isPressNew) {
                // press_new: item[1]=pressTime, item[2]=sleepTime
                // sleepTime = interval + pressTime
                $interval = max(0, $sleepMs - $pressMs);
            } elseif ($isLongPress) {
                // long_press: item[1]=pressTime（=t 表达式，已经表达 interval 长度）, item[2]=0
                $interval = $pressMs;
            } else {
                // 未知模板，按 press_new 语义处理
                $interval = max(0, $sleepMs - $pressMs);
            }
            $groups[] = ['keys' => $keys, 'interval' => $interval];
        }
        return $groups ?: null;
    }

    /**
     * press_new 的 pre 函数签名：sleepTime = item[2]-item[1]
     */
    private function containsPTSleep(string $js): bool
    {
        return strpos($js, 'item[2]-item[1]') !== false
            || strpos($js, 'item[2] - item[1]') !== false
            || strpos($js, 'item[2] -item[1]') !== false
            || strpos($js, 'item[2]- item[1]') !== false;
    }

    /**
     * 计算 t 表达式：t0+t1+t2 / 0 / 数字
     */
    private function evalTExpr(string $expr, int $t0, int $t1, int $t2, int $noteTime): int
    {
        $expr = trim($expr);
        if ($expr === '' || $expr === '0') {
            return 0;
        }
        // 直接是数字
        if (ctype_digit($expr)) {
            return (int)$expr;
        }
        // 替换 t0/t1/t2 为数值
        $resolved = preg_replace_callback('/\bt([012])\b/', function ($m) use ($t0, $t1, $t2) {
            return (string)($m[1] === '0' ? $t0 : ($m[1] === '1' ? $t1 : $t2));
        }, $expr);
        // 简单加减安全求值
        $resolved = preg_replace('/[^0-9+\-]/', '', $resolved);
        if ($resolved === '' || $resolved === null) {
            return 0;
        }
        try {
            $v = (int)eval('return ' . $resolved . ';');
            return $v;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 琴键名 (a4..g6) → 编号 1..15
     *  与 C++ 一致：(letter - 'A') * 5 + (digit - '0') - 1
     *  a4=0, b4=1, c5=2, d5=3, e5=4, f5=5, g5=6, a5=7, b5=8, c6=9, d6=10, e6=11, f6=12, g6=13
     *  即字母 a-g × 2 + 行（4/5/6）偏移
     */
    private function jsKeyToNum(string $name): int
    {
        $name = strtolower(trim($name));
        if (strlen($name) < 2) return 0;
        $letter = ord($name[0]) - ord('a');     // 0..6
        $row    = (int)$name[1] - 4;             // 4->0, 5->1, 6->2
        $idx    = $letter * 2 + $row;            // 0..14
        return $idx >= 0 && $idx < 15 ? ($idx + 1) : 0;
    }

    /**
     * 从 notes 中间表示渲染 press 模板输出
     *  （用于 .js → press / .js → .js）
     *  与原 C++ 1:1 复刻的 convert() 输出格式一致
     */
    private function renderPressFromNotes(int $noteTime, array $notes): void
    {
        $this->append('var time=' . $noteTime . ';');
        $this->append('var time2=' . ($noteTime * 2) . ';');
        $this->append('var time4=' . ($noteTime * 4) . ';');
        $this->append('var pressoffset=' . (int)$this->config['random_offset'] . ';');
        $this->append('var pressoffset2=' . ((int)$this->config['random_offset'] * 2) . ';');
        $this->append('var stop=1;');
        $this->append('var speedControl=1;');
        $this->append('var zuobiaoPath = "/sdcard/Download/SkyMsToJs/zuobiao.txt";');
        $this->append('if (files.exists(zuobiaoPath)) {eval(files.read(zuobiaoPath));}else{setScreenMetrics(1080, 2340);var x=[780,975,1170,1365,1560];var y=[215,410,605];}');

        if ($this->config['use_random_offset']) {
            $this->writeCodeArray(self::randomCode(), "\n");
        } else {
            $this->writeCodeArray(self::noRandomCode(), "\n");
        }
        if ($this->config['have_window']) {
            $this->writeCodeArray(self::windowCode(), "\n");
        } else {
            $arr = self::noWindowCode();
            $cnt = count($arr);
            for ($i = 0; $i < $cnt - 2; $i++) {
                $this->append($arr[$i] . "\n");
            }
            $this->append($arr[3] . $this->config['no_window_start_time'] . $arr[4] . "\n");
        }

        // notes 渲染：interval 拆分 4/2/1 倍 lNoteTime，对应 t4/t2/t1
        foreach ($notes as $g) {
            $diff = (int)($g['interval'] ?? 0);
            // 拆分 diff 为 4/2/1 累加
            for ($i = 2; $i >= 0; --$i) {
                $multArr = [1, 2, 4];
                $mult = $multArr[$i];
                $cnt = intdiv($diff, $noteTime * $mult);
                for ($k = 0; $k < $cnt; $k++) {
                    $this->append(self::timeCode($i));
                }
                $diff = $diff - $cnt * $noteTime * $mult;
            }
            // 写 keys
            foreach ($g['keys'] as $k) {
                $idx = max(0, min(14, ((int)$k) - 1));
                $this->append(self::noteCode($idx));
            }
        }
    }
}
