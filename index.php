<?php
/*==================================================
                  配置常量区
==================================================*/
// 系统要求
define('PHP_MIN_VERSION', '7.4.0');

// API鉴权配置
define('CLIENT_ID', 'your-client-id');      // 替换为真实ID
define('CLIENT_SECRET', 'your-secret');     // 替换为真实密钥
define('API_DOMAIN', 'https://open-api.123pan.com');

// 分享功能配置
define('SHARE_URL', 'https://www.123pan.com/s/');
define('SHARE_EXPIRE', 7);       // 有效期(0=永久,1/7/30=天数)
define('SHARE_PWD', '');         // 分享密码(空=无密码)
define('MAX_SELECT', 30);        // 最大可选文件数
define('COOLDOWN', 0);           // 分享冷却时间(秒)
define('TRAFFIC_SWITCH', 1);     // 免登录流量包开关(1=关闭)
define('TRAFFIC_LIMIT_SWITCH', 1);// 流量限制开关(1=关闭)

// 搜索功能配置
define('SEARCH_LIMIT', 100);     // 每次请求数量
define('SEARCH_MODE', 0);        // 搜索模式(0=全字匹配)
define('SCORE_EXACT_MATCH', 15); // 完全匹配得分
define('SCORE_START_MATCH', 10); // 开头匹配得分
define('SCORE_OCCURRENCE', 3);   // 每次出现得分
define('SCORE_KEYWORD_DISTANCE_BASE',5); // 关键词间距基准分
define('SCORE_VIDEO_EXTRA', 2);  // 视频文件额外分

// 网络请求配置
define('CURL_TIMEOUT', 15);      // API请求超时(秒)
define('API_RETRY', 3);          // API失败重试次数

// 错误报告配置
define('DISPLAY_ERRORS', 0);
define('LOG_ERRORS', 1);
define('ERROR_LOG', __DIR__ . '/error.log');

/*==================================================
                  系统初始化区
==================================================*/
if (version_compare(PHP_VERSION, PHP_MIN_VERSION, '<')) {
    die("需PHP".PHP_MIN_VERSION."+运行环境");
}

ini_set('display_errors', DISPLAY_ERRORS);
ini_set('log_errors', LOG_ERRORS);
ini_set('error_log', ERROR_LOG);

session_start();

/*==================================================
                  核心功能函数区
==================================================*/
/*​
 * CURL请求封装
 */
function apiRequest($url, $method = 'GET', $data = null, $headers = []) {
    $ch = curl_init();
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => CURL_TIMEOUT,
        CURLOPT_HTTPHEADER => array_merge([
            'Platform: open_platform',
            'Content-Type: application/json'
        ], $headers),
        CURLOPT_SSL_VERIFYPEER => true
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        error_log('CURL Error: ' . curl_error($ch));
        return ['error' => 'API连接异常'];
    }
    
    curl_close($ch);
    return json_decode($response, true) ?: ['error' => '无效的JSON响应'];
}

/*​
 * 获取Access Token
 */
function getAccessToken() {
    if (!empty($_SESSION['access_token']) && $_SESSION['expire_time'] > time()) {
        return $_SESSION['access_token'];
    }

    $response = apiRequest(API_DOMAIN . '/api/v1/access_token', 'POST', [
        'clientID' => CLIENT_ID,
        'clientSecret' => CLIENT_SECRET
    ]);

    if (isset($response['data']['accessToken'])) {
        $_SESSION['access_token'] = $response['data']['accessToken'];
        $_SESSION['expire_time'] = strtotime($response['data']['expiredAt']) - 300;
        return $response['data']['accessToken'];
    }

    error_log('Token获取失败: ' . json_encode($response));
    return null;
}

/*​
 * 文件搜索功能（过滤文件夹）
 */
function searchFiles($keyword) {
    $token = getAccessToken();
    if (!$token) return [];

    $lastFileId = 0;
    $results = [];
    
    do {
        $url = API_DOMAIN . "/api/v2/file/list?" . http_build_query([
            'parentFileId' => 0,
            'limit' => SEARCH_LIMIT,
            'searchData' => $keyword,
            'searchMode' => SEARCH_MODE,
            'lastFileId' => $lastFileId
        ]);

        $response = apiRequest($url, 'GET', null, [
            'Authorization: Bearer ' . $token
        ]);

        if (!empty($response['data']['fileList'])) {
            $filteredFiles = array_filter($response['data']['fileList'], function($file) {
                return $file['type'] === 0;
            });
            
            $results = array_merge($results, $filteredFiles);
            $lastFileId = $response['data']['lastFileId'] ?? -1;
        } else {
            break;
        }
    } while ($lastFileId != -1);

    $cleanKeyword = strtolower(trim($keyword));
    $keywords = array_unique(array_filter(explode(' ', $cleanKeyword)));

    usort($results, function($a, $b) use ($keywords) {
        $scoreA = getRelevanceScore($a['filename'], $keywords);
        $scoreB = getRelevanceScore($b['filename'], $keywords);
        return $scoreB != $scoreA ? $scoreB - $scoreA : 
               strlen($a['filename']) - strlen($b['filename']);
    });

    return $results;
}

/*​
 * 计算文件相关性得分
 */
function getRelevanceScore($filename, $keywords) {
    $score = 0;
    $lowerName = strtolower($filename);
    
    foreach ($keywords as $keyword) {
        if (strpos($filename, $keyword) !== false) $score += SCORE_EXACT_MATCH;
        if (stripos($lowerName, $keyword) === 0) $score += SCORE_START_MATCH;
        $score += substr_count($lowerName, $keyword) * SCORE_OCCURRENCE;
        
        if (count($keywords) > 1) {
            $positions = [];
            foreach ($keywords as $k) {
                $pos = stripos($lowerName, $k);
                if ($pos !== false) $positions[] = $pos;
            }
            sort($positions);
            if (count($positions) > 1) {
                $distance = $positions[1] - $positions[0];
                $score += max(SCORE_KEYWORD_DISTANCE_BASE - $distance, 0);
            }
        }
    }
    
    if (strpos($lowerName, '.mp4') !== false || 
        strpos($lowerName, '.avi') !== false) {
        $score += SCORE_VIDEO_EXTRA;
    }
    
    return $score;
}

/*​
 * 创建分享链接
 */
function createShare($fileIds) {
    $token = getAccessToken();
    if (!$token) return ['error' => '访问令牌获取失败'];

    if (COOLDOWN > 0 && !empty($_SESSION['last_share_time'])) {
        $remaining = COOLDOWN - (time() - $_SESSION['last_share_time']);
        if ($remaining > 0) return ['error' => '请等待 '.ceil($remaining/60).' 分钟后再生成'];
    }

    if (count($fileIds) > MAX_SELECT) return ['error' => '每次最多选择'.MAX_SELECT.'个文件'];
    if (!in_array(SHARE_EXPIRE, [0,1,7,30])) return ['error' => '有效期参数错误'];

    $firstFileName = $_POST['first_file_name'] ?? '资源分享';

    $response = apiRequest(
        API_DOMAIN . '/api/v1/share/create',
        'POST',
        [
            'shareName' => $firstFileName,
            'shareExpire' => SHARE_EXPIRE,
            'fileIDList' => implode(',', $fileIds),
            'sharePwd' => SHARE_PWD,
            'trafficSwitch' => TRAFFIC_SWITCH,
            'trafficLimitSwitch' => TRAFFIC_LIMIT_SWITCH
        ],
        [
            'Authorization: Bearer ' . $token,
            'Platform: open_platform'
        ]
    );

    if (isset($response['code']) && $response['code'] === 0 && isset($response['data']['shareKey'])) {
        $_SESSION['last_share_time'] = time();
        return $response['data'];
    }
    
    return ['error' => $response['message'] ?? '服务器响应异常'];
}

/*==================================================
                  主逻辑处理区
==================================================*/
$searchResults = [];
$shareLink = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['search'])) {
            $keyword = filter_input(INPUT_POST, 'keyword', FILTER_SANITIZE_STRING);
            $searchResults = searchFiles($keyword);
        } elseif (isset($_POST['create_share'])) {
            $selectedFiles = $_POST['files'] ?? [];
            if (!empty($selectedFiles)) {
                $_POST['first_file_name'] = $_POST['file_names'][$selectedFiles[0]] ?? '';
                $result = createShare($selectedFiles);
                if (isset($result['shareKey'])) {
                    $shareLink = SHARE_URL . $result['shareKey'];
                } else {
                    $error = '分享失败: '.($result['error'] ?? '未知错误');
                }
            }
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, minimum-scale=1.0, user-scalable=no">
    <title>123云盘资源分享系统</title>
    <style>
        :root {
            --primary-color: #007bff;
            --success-color: #28a745;
            --error-color: #dc3545;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --border-radius: 12px;
        }

        /* 加载动画系统 */
        .loader-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(255, 255, 255, 0.98);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            will-change: opacity;
            transform: translateZ(0);
        }

        .loader {
            position: relative;
            width: 200px;
            height: 200px;
            perspective: 1000px;
        }

        .container {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            transform: translate(-50%, -50%);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .item {
            width: 100px;
            height: 100px;
            position: absolute;
            transform-origin: 50% 50%;
            animation-timing-function: cubic-bezier(.6,.01,.4,1);
        }

        /* 动画核心修正 */
        .item-1 {
            background-color: #FA5667;
            animation: item-move 1.8s infinite;
        }

        .item-2 {
            background-color: #7A45E5;
            animation: item-move 1.8s infinite 0.45s;
        }

        .item-3 {
            background-color: #1B91F7;
            animation: item-move 1.8s infinite 0.3s;
        }

        .item-4 {
            background-color: #FAC24C;
            animation: item-move 1.8s infinite 0.15s;
        }

        @keyframes item-move {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(0, -100px) rotate(90deg); }
            50% { transform: translate(100px, -100px) rotate(180deg); }
            75% { transform: translate(100px, 0) rotate(270deg); }
        }

        /* 文字居中强化 */
        .loader-text {
            position: absolute;
            width: 100%;
            text-align: center;
            top: calc(50% + 40px);
            font-size: 1.2em;
            font-weight: 600;
            background: linear-gradient(90deg, #3b82f6, #ff6b6b, #3b82f6);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            background-size: 200% auto;
            animation: enhanced-text 2.2s ease-in-out infinite;
        }

        /* 关键帧动画系统 */
        @keyframes composite-rotate {
            0% { transform: rotate(0deg) scale(1); filter: hue-rotate(0deg); }
            50% { transform: rotate(180deg) scale(0.92); filter: hue-rotate(90deg); }
            100% { transform: rotate(360deg) scale(1); filter: hue-rotate(180deg); }
        }

        @keyframes optimized-colors {
            0%, 100% { border-color: var(--primary-color) transparent; opacity: 0.9; }
            50% { border-color: #ff6b6b rgba(255,107,107,0.2); opacity: 0.6; }
        }

        @keyframes enhanced-text {
            0% { transform: scale(1); background-position: 0% center; }
            50% { transform: scale(1.06); background-position: 100% center; }
            100% { transform: scale(1); background-position: 200% center; }
        }

        /* 交互界面样式 */
        body { 
            font-family: 'Segoe UI', 'PingFang SC', system-ui, sans-serif;
            margin: 20px auto;
            padding: 0 20px;
            min-width: 320px;
            color: var(--text-color);
            background: #fff;
            line-height: 1.6;
        }

        .search-box {
            background: var(--bg-color);
            padding: 24px;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            margin-bottom: 28px;
            transition: box-shadow 0.3s;
        }

        .search-container {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        input[type="text"] {
            flex: 1;
            min-width: 280px;
            padding: 14px 20px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
            background: #fff;
        }

        input[type="text"]:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0,123,255,0.25);
        }

        button {
            padding: 14px 28px;
            background: var(--primary-color);
            border: none;
            color: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
        }

        button:hover {
            background: #0069d9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,123,255,0.3);
        }

        /* 表格系统 */
        table {
            width: 100%;
            margin: 24px 0;
            border-collapse: collapse;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        th, td {
            padding: 16px 20px;
            text-align: left;
            border-bottom: 1px solid #f1f3f5;
        }

        th {
            background: var(--bg-color);
            font-weight: 600;
            color: #495057;
        }

        tr:hover td {
            background: #f8fafb;
        }

        .file-size {
            color: #6c757d;
            font-size: 0.95em;
            font-family: monospace;
        }

        .share-btn {
            background: var(--success-color);
            padding: 8px 18px;
            transition: transform 0.2s;
        }

        /* 消息提示系统 */
        .error { 
            color: var(--error-color);
            padding: 18px;
            background: #f8d7da;
            border-radius: var(--border-radius);
            margin: 24px 0;
            border: 1px solid #f5c2c7;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .success {
            color: #155724;
            padding: 20px;
            background: #d4edda;
            border-radius: var(--border-radius);
            margin: 24px 0;
            border: 1px solid #c3e6cb;
            animation: fadeIn 0.6s;
        }

        .mobile-notice {
            display: none;
            color: #666;
            font-size: 14px;
            padding: 12px;
            text-align: center;
            margin: 16px 0;
            background: #f3f3f3;
            border-radius: 8px;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* 移动端适配 */
        @media (max-width: 768px) {
            .loader {
                width: 120px;
                height: 120px;
            }
            
            .item {
                width: 60px;
                height: 60px;
            }
            
            .loader-text {
                font-size: 0.8em;
                top: calc(50% + 20px);
            }

            body {
                margin: 12px auto;
                padding: 0 12px;
            }

            .search-box {
                padding: 20px;
            }

            input[type="text"] {
                width: 100%;
                margin-bottom: 12px;
                font-size: 15px;
            }

            button {
                width: 100%;
                padding: 16px;
                font-size: 15px;
            }

            table {
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 10px;
            }

            th:nth-child(3),
            td:nth-child(3) {
                display: none;
            }

            td {
                padding: 14px 16px;
                min-width: 130px;
            }

            .share-btn {
                padding: 8px 14px;
                font-size: 14px;
            }

            .spinner {
                width: 48px;
                height: 48px;
                animation: 
                    composite-rotate 2s ease-out infinite,
                    optimized-colors 3s ease-in-out infinite;
            }
            
            .loader-text {
                animation: enhanced-text 2.2s ease-in-out infinite paused;
            }

            .mobile-notice {
                display: block;
            }
        }
    </style>
</head>
<body>
    <!-- 搜索模块 -->
    <div class="search-box">
        <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <div class="search-container">
                <input type="text" 
                    name="keyword" 
                    placeholder="输入资源关键词（支持模糊搜索）" 
                    value="<?= htmlspecialchars($_POST['keyword'] ?? '') ?>"
                    autocomplete="off" 
                    required>
                <button type="submit" name="search">
                    <span class="desktop-text">🔍 搜索资源</span>
                </button>
            </div>
        </form>
    </div>

    <!-- 移动端提示 -->
    <div class="mobile-notice">
        📱 提示：左右滑动浏览表格内容
    </div>

    <!-- 搜索结果模块 -->
    <?php if (!empty($searchResults)): ?>
    <form method="post" onsubmit="return validateSelection()">
        <table>
            <thead>
                <tr>
                    <th>选择</th>
                    <th>文件名</th>
                    <th>类型</th>
                    <th>大小</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($searchResults as $file): ?>
                <tr>
                    <td>
                        <input type="checkbox" 
                            name="files[]" 
                            value="<?= $file['fileId'] ?>"
                            class="file-checkbox">
                    </td>
                    <td>
                        <?= htmlspecialchars($file['filename']) ?>
                        <input type="hidden" 
                            name="file_names[<?= $file['fileId'] ?>]" 
                            value="<?= htmlspecialchars($file['filename']) ?>">
                    </td>
                    <td>
                        <?php switch($file['category']) {
                            case 1: echo '🎵 音频'; break;
                            case 2: echo '🎥 视频'; break;
                            case 3: echo '🖼️ 图片'; break;
                            default: echo '📄 文档';
                        } ?>
                    </td>
                    <td class="file-size">
                        <?php
                        $size = $file['size'];
                        $units = ['B', 'KB', 'MB', 'GB'];
                        $i = floor(log($size, 1024));
                        $size = round($size / pow(1024, $i), 2);
                        echo $size . ' ' . $units[$i];
                        ?>
                    </td>
                    <td>
                        <button type="submit" class="share-btn" name="create_share">
                            <span class="desktop-text">🔗 生成链接</span>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </form>
    <?php endif; ?>

    <!-- 分享模块 -->
    <?php if (!empty($shareLink)): ?>
    <div class="success">
        <h3>✅ 分享链接已生成</h3>
        <p>访问地址：<a href="<?= $shareLink ?>" target="_blank" rel="noopener"><?= $shareLink ?></a></p>
        <?php if(SHARE_PWD): ?>
        <p>提取密码：<strong class="copy-target"><?= SHARE_PWD ?></strong></p>
        <?php endif; ?>
        <small>有效期：<?= SHARE_EXPIRE == 0 ? '永久有效' : SHARE_EXPIRE.'天内有效' ?></small>
    </div>
    <?php endif; ?>

    <!-- 加载动画 -->
    <div class="loader-overlay" id="loader">
        <div class="loader">
            <div class="container">
                <div class="item item-1"></div>
                <div class="item item-2"></div>
                <div class="item item-3"></div>
                <div class="item item-4"></div>
            </div>
        </div>
    </div>

    <!-- 交互脚本 -->
    <script>
        // 性能监控系统
        const performanceMonitor = {
            init: () => {
                if (window.PerformanceObserver) {
                    const observer = new PerformanceObserver(list => {
                        list.getEntries().forEach(entry => {
                            console.log('[性能监控]', entry.name, 
                                `处理时间: ${entry.processingTime.toFixed(2)}ms`);
                        });
                    });
                    observer.observe({ entryTypes: ['animation'] });
                }
            }
        }

        // 加载控制系统
        const loader = {
            show: () => {
                document.getElementById('loader').style.display = 'flex';
                document.body.style.overflow = 'hidden';
                requestIdleCallback(() => {
                    document.fonts.ready.then(() => {
                        console.log('[性能] 字体预加载完成');
                    });
                });
            },
            hide: () => {
                document.getElementById('loader').style.display = 'none';
                document.body.style.overflow = '';
            }
        };

        // 表单验证系统
        const validateSelection = () => {
            const checkboxes = document.querySelectorAll('.file-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('请至少选择一个文件');
                return false;
            }
            return true;
        }

        // 触摸交互系统
        const touchHandler = {
            init: () => {
                document.querySelectorAll('.file-checkbox').forEach(checkbox => {
                    let touchStartTime = 0;
                    
                    checkbox.addEventListener('touchstart', function(e) {
                        touchStartTime = performance.now();
                        this.classList.add('active');
                    });

                    checkbox.addEventListener('touchend', function(e) {
                        const duration = performance.now() - touchStartTime;
                        this.classList.remove('active');
                        if(duration > 300) {
                            this.checked = !this.checked;
                            e.preventDefault();
                        }
                    }, { passive: true });
                });
            }
        }

        // 表格优化系统
        const tableOptimizer = {
            init: () => {
                document.querySelectorAll('table').forEach(table => {
                    let rafId = null;
                    let lastScroll = 0;
                    
                    table.addEventListener('scroll', () => {
                        if (rafId) return;
                        
                        rafId = requestAnimationFrame(() => {
                            const currentScroll = table.scrollLeft;
                            if (Math.abs(currentScroll - lastScroll) > 50) {
                                table.classList.add('momentum-scroll');
                            }
                            lastScroll = currentScroll;
                            rafId = null;
                        });
                    });
                });
            }
        }

        // 初始化流程
        document.addEventListener('DOMContentLoaded', () => {
            performanceMonitor.init();
            touchHandler.init();
            tableOptimizer.init();
            
            loader.show();
            window.addEventListener('load', () => {
                setTimeout(() => {
                    loader.hide();
                    const link = document.createElement('link');
                    link.rel = 'preload';
                    link.as = 'image';
                    link.href = 'next-page-bg.jpg';
                    document.head.appendChild(link);
                }, 500);
            });
        });
    </script>
</body>
</html>
