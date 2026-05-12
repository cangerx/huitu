<?php
declare(strict_types=1);

// ============================================================
//  苍API-绘图 · Web 安装向导
//  安装完成后会生成 install.lock 锁定此页面
// ============================================================

$lockFile   = __DIR__ . '/install.lock';
$runtimeDir = __DIR__ . '/runtime';
$imagesDir  = $runtimeDir . '/images';
$taskDir    = $runtimeDir . '/generation-task-files';
$ossConfig  = __DIR__ . '/oss-config.php';
$r2Config   = __DIR__ . '/r2-config.php';
$cosConfig  = __DIR__ . '/cos-config.php';

// ── 已安装检测 ──
if (file_exists($lockFile)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>已安装</title></head>';
    echo '<body style="font-family:system-ui;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f7f7f5">';
    echo '<div style="text-align:center"><h2>✅ 已安装</h2><p style="color:#6b7280">如需重新安装，请删除 <code>install.lock</code> 文件后刷新。</p></div>';
    echo '</body></html>';
    exit;
}

// ── 环境检测 ──
function checkEnv(): array {
    $checks = [];
    $checks['php_version'] = [
        'label' => 'PHP 版本 ≥ 8.1',
        'ok'    => version_compare(PHP_VERSION, '8.1.0', '>='),
        'value' => PHP_VERSION,
    ];
    $exts = ['curl', 'pdo_sqlite', 'fileinfo', 'mbstring'];
    foreach ($exts as $ext) {
        $checks["ext_{$ext}"] = [
            'label' => "扩展 {$ext}",
            'ok'    => extension_loaded($ext),
            'value' => extension_loaded($ext) ? '已启用' : '未启用',
        ];
    }
    $checks['runtime_writable'] = [
        'label' => 'runtime 目录可写',
        'ok'    => is_writable(__DIR__) || is_writable(__DIR__ . '/runtime'),
        'value' => (is_writable(__DIR__) || is_writable(__DIR__ . '/runtime')) ? '可写' : '不可写',
    ];
    return $checks;
}

// ── 处理 POST ──
$result  = null;
$formErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = trim($_POST['storage_mode'] ?? 'local');

    // 创建 runtime 目录
    foreach ([$runtimeDir, $imagesDir, $taskDir] as $dir) {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true)) {
                $formErr = "创建目录失败: {$dir}";
            }
        }
    }

    if ($formErr === '' && $mode === 'oss') {
        $endpoint  = trim($_POST['oss_endpoint'] ?? '');
        $akId      = trim($_POST['oss_ak_id'] ?? '');
        $akSecret  = trim($_POST['oss_ak_secret'] ?? '');
        $bucket    = trim($_POST['oss_bucket'] ?? '');
        $publicUrl = trim($_POST['oss_public_url'] ?? '');
        $prefix    = trim($_POST['oss_prefix'] ?? '') ?: 'cang-api-draw';

        if ($endpoint === '' || $akId === '' || $akSecret === '' || $bucket === '') {
            $formErr = 'OSS 模式下所有必填项不能为空';
        }

        // 测试 OSS 连接
        if ($formErr === '') {
            $host     = $bucket . '.' . $endpoint;
            $date     = gmdate('D, d M Y H:i:s \G\M\T');
            $resource = '/' . $bucket . '/';
            $strSign  = "GET\n\n\n{$date}\n{$resource}";
            $sig      = base64_encode(hash_hmac('sha1', $strSign, $akSecret, true));

            $ch = curl_init("https://{$host}/?list-type=2&max-keys=1");
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET        => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => [
                    "Host: {$host}",
                    "Date: {$date}",
                    "Authorization: OSS {$akId}:{$sig}",
                ],
            ]);
            $body   = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error  = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                $formErr = "OSS 连接失败: {$error}";
            } elseif ($status < 200 || $status >= 300) {
                $formErr = "OSS 验证失败 (HTTP {$status})，请检查 Endpoint、AK/SK、Bucket 是否正确";
            }
        }

        // 写入配置
        if ($formErr === '') {
            $configContent = "<?php\n\ndeclare(strict_types=1);\n\n"
                . "define('OSS_ENDPOINT', " . var_export($endpoint, true) . ");\n"
                . "define('OSS_ACCESS_KEY_ID', " . var_export($akId, true) . ");\n"
                . "define('OSS_ACCESS_KEY_SECRET', " . var_export($akSecret, true) . ");\n"
                . "define('OSS_BUCKET', " . var_export($bucket, true) . ");\n"
                . "define('OSS_PUBLIC_BASE_URL', " . var_export($publicUrl, true) . ");\n"
                . "define('OSS_KEY_PREFIX', " . var_export($prefix, true) . ");\n"
                . "define('OSS_SIGNED_URL_EXPIRES', 3600);\n";

            if (@file_put_contents($ossConfig, $configContent) === false) {
                $formErr = '写入 oss-config.php 失败，请检查目录权限';
            }
        }
    }

    if ($formErr === '' && $mode === 'r2') {
        $accountId = trim($_POST['r2_account_id'] ?? '');
        $akId      = trim($_POST['r2_ak_id'] ?? '');
        $akSecret  = trim($_POST['r2_ak_secret'] ?? '');
        $bucket    = trim($_POST['r2_bucket'] ?? '');
        $publicUrl = trim($_POST['r2_public_url'] ?? '');
        $prefix    = trim($_POST['r2_prefix'] ?? '') ?: 'cang-api-draw';

        if ($accountId === '' || $akId === '' || $akSecret === '' || $bucket === '') {
            $formErr = 'R2 模式下所有必填项不能为空';
        }
        if ($formErr === '' && $publicUrl === '') {
            $formErr = 'R2 需要配置公开访问域名（r2.dev 域名或自定义域名）';
        }

        if ($formErr === '') {
            $configContent = "<?php\n\ndeclare(strict_types=1);\n\n"
                . "define('R2_ACCOUNT_ID', " . var_export($accountId, true) . ");\n"
                . "define('R2_ACCESS_KEY_ID', " . var_export($akId, true) . ");\n"
                . "define('R2_ACCESS_KEY_SECRET', " . var_export($akSecret, true) . ");\n"
                . "define('R2_BUCKET', " . var_export($bucket, true) . ");\n"
                . "define('R2_PUBLIC_BASE_URL', " . var_export($publicUrl, true) . ");\n"
                . "define('R2_KEY_PREFIX', " . var_export($prefix, true) . ");\n";

            if (@file_put_contents($r2Config, $configContent) === false) {
                $formErr = '写入 r2-config.php 失败，请检查目录权限';
            }
        }
    }

    if ($formErr === '' && $mode === 'cos') {
        $region    = trim($_POST['cos_region'] ?? '');
        $secretId  = trim($_POST['cos_secret_id'] ?? '');
        $secretKey = trim($_POST['cos_secret_key'] ?? '');
        $bucket    = trim($_POST['cos_bucket'] ?? '');
        $publicUrl = trim($_POST['cos_public_url'] ?? '');
        $prefix    = trim($_POST['cos_prefix'] ?? '') ?: 'cang-api-draw';

        if ($region === '' || $secretId === '' || $secretKey === '' || $bucket === '') {
            $formErr = 'COS 模式下所有必填项不能为空';
        }

        // 测试 COS 连接
        if ($formErr === '') {
            $cosHost = $bucket . '.cos.' . $region . '.myqcloud.com';
            $now     = time();
            $keyTime = $now . ';' . ($now + 60);
            $signKey = hash_hmac('sha1', $keyTime, $secretKey);
            $httpString = "get\n/\n\n\n";
            $sha1ed = sha1($httpString);
            $strToSign = "sha1\n{$keyTime}\n{$sha1ed}\n";
            $sig = hash_hmac('sha1', $strToSign, $signKey);
            $auth = "q-sign-algorithm=sha1&q-ak={$secretId}&q-sign-time={$keyTime}&q-key-time={$keyTime}&q-header-list=&q-url-param-list=&q-signature={$sig}";

            $ch = curl_init("https://{$cosHost}/");
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET        => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => [
                    "Host: {$cosHost}",
                    "Authorization: {$auth}",
                ],
            ]);
            $body   = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error  = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                $formErr = "COS 连接失败: {$error}";
            } elseif ($status < 200 || $status >= 300) {
                $formErr = "COS 验证失败 (HTTP {$status})，请检查 Region、SecretId/SecretKey、Bucket 是否正确";
            }
        }

        if ($formErr === '') {
            $configContent = "<?php\n\ndeclare(strict_types=1);\n\n"
                . "define('COS_REGION', " . var_export($region, true) . ");\n"
                . "define('COS_SECRET_ID', " . var_export($secretId, true) . ");\n"
                . "define('COS_SECRET_KEY', " . var_export($secretKey, true) . ");\n"
                . "define('COS_BUCKET', " . var_export($bucket, true) . ");\n"
                . "define('COS_PUBLIC_BASE_URL', " . var_export($publicUrl, true) . ");\n"
                . "define('COS_KEY_PREFIX', " . var_export($prefix, true) . ");\n"
                . "define('COS_SIGNED_URL_EXPIRES', 3600);\n";

            if (@file_put_contents($cosConfig, $configContent) === false) {
                $formErr = '写入 cos-config.php 失败，请检查目录权限';
            }
        }
    }

    if ($formErr === '' && $mode === 'local') {
        // 本地模式：移除旧的配置（如有）
        foreach ([$ossConfig, $r2Config, $cosConfig] as $cf) {
            if (file_exists($cf)) @unlink($cf);
        }
    }

    // 写入锁文件
    if ($formErr === '') {
        $lockContent = json_encode([
            'installed_at' => date('c'),
            'storage_mode' => $mode,
            'php_version'  => PHP_VERSION,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        @file_put_contents($lockFile, $lockContent);
        $result = $mode;
    }
}

$envChecks  = checkEnv();
$allPassed  = !in_array(false, array_column($envChecks, 'ok'), true);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>苍API-绘图 · 安装向导</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f7f7f5;--card:#fff;--border:#e5e5e4;--text:#18181b;--muted:#6b7280;
  --accent:#18181b;--accent-light:#f4f4f5;--green:#16a34a;--green-bg:#dcfce7;
  --red:#dc2626;--red-bg:#fef2f2;--yellow:#ca8a04;--yellow-bg:#fefce8;
  --radius:12px;
}
body{
  font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
  background:var(--bg);color:var(--text);line-height:1.6;
  min-height:100vh;padding:40px 16px;
}
.container{max-width:560px;margin:0 auto}
.logo{text-align:center;margin-bottom:32px}
.logo h1{font-size:24px;font-weight:700;letter-spacing:-0.5px}
.logo p{color:var(--muted);font-size:14px;margin-top:4px}
.card{
  background:var(--card);border:1px solid var(--border);border-radius:var(--radius);
  padding:28px;margin-bottom:20px;
}
.card h2{font-size:16px;font-weight:600;margin-bottom:16px}
.check-list{list-style:none}
.check-item{
  display:flex;align-items:center;gap:10px;padding:8px 0;
  border-bottom:1px solid var(--accent-light);font-size:14px;
}
.check-item:last-child{border-bottom:none}
.check-icon{width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0}
.check-ok{background:var(--green-bg);color:var(--green)}
.check-fail{background:var(--red-bg);color:var(--red)}
.check-label{flex:1}
.check-value{color:var(--muted);font-size:13px}

.mode-tabs{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:20px}
.mode-tab{
  padding:14px 16px;border:2px solid var(--border);border-radius:10px;
  background:var(--card);cursor:pointer;text-align:center;transition:all .2s;
}
.mode-tab:hover{border-color:var(--muted)}
.mode-tab.active{border-color:var(--accent);background:var(--accent-light)}
.mode-tab strong{display:block;font-size:14px;margin-bottom:2px}
.mode-tab span{font-size:12px;color:var(--muted)}

.form-group{margin-bottom:16px}
.form-group label{display:block;font-size:13px;font-weight:500;margin-bottom:6px;color:var(--text)}
.form-group .hint{font-size:12px;color:var(--muted);margin-top:4px}
input[type="text"],input[type="password"]{
  width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;
  font-size:14px;font-family:inherit;background:var(--card);color:var(--text);
  transition:border-color .2s;
}
input:focus{outline:none;border-color:var(--accent)}

.oss-fields{display:none}
.oss-fields.show{display:block}

.btn{
  display:block;width:100%;padding:12px;border:none;border-radius:10px;
  font-size:15px;font-weight:600;cursor:pointer;transition:all .2s;font-family:inherit;
}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{opacity:.85}
.btn-primary:disabled{opacity:.4;cursor:not-allowed}

.alert{padding:14px 16px;border-radius:10px;font-size:14px;margin-bottom:16px}
.alert-error{background:var(--red-bg);color:var(--red);border:1px solid #fecaca}
.alert-success{background:var(--green-bg);color:var(--green);border:1px solid #bbf7d0}

.success-page{text-align:center;padding:48px 28px}
.success-page .icon{font-size:48px;margin-bottom:16px}
.success-page h2{font-size:20px;margin-bottom:8px}
.success-page p{color:var(--muted);font-size:14px;margin-bottom:24px}
.success-page a{
  display:inline-block;padding:10px 28px;background:var(--accent);color:#fff;
  border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;
}
.success-page a:hover{opacity:.85}
.success-page .note{
  margin-top:20px;padding:12px 16px;background:var(--yellow-bg);
  border-radius:8px;font-size:13px;color:var(--yellow);text-align:left;
}
</style>
</head>
<body>
<div class="container">
  <div class="logo">
    <h1>苍API-绘图</h1>
    <p>安装向导</p>
  </div>

<?php if ($result !== null): ?>
  <!-- ── 安装成功 ── -->
  <div class="card">
    <div class="success-page">
      <div class="icon">🎉</div>
      <h2>安装完成</h2>
      <p>存储模式：<?= ['oss'=>'阿里云 OSS','r2'=>'Cloudflare R2','cos'=>'腾讯云 COS'][$result] ?? '本地存储' ?></p>
      <a href="./">开始使用</a>
      <div class="note">
        <strong>安全提示：</strong>请在宝塔 Nginx 伪静态中添加以下规则，禁止访问安装文件和配置：<br>
        <code style="font-size:12px">
          location ~* (install\.php|install\.lock|oss-config\.php|r2-config\.php|cos-config\.php|\.example$) { deny all; }
        </code>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- ── 环境检测 ── -->
  <div class="card">
    <h2>环境检测</h2>
    <ul class="check-list">
    <?php foreach ($envChecks as $check): ?>
      <li class="check-item">
        <span class="check-icon <?= $check['ok'] ? 'check-ok' : 'check-fail' ?>"><?= $check['ok'] ? '✓' : '✗' ?></span>
        <span class="check-label"><?= htmlspecialchars($check['label']) ?></span>
        <span class="check-value"><?= htmlspecialchars($check['value']) ?></span>
      </li>
    <?php endforeach; ?>
    </ul>
  </div>

  <?php if (!$allPassed): ?>
    <div class="alert alert-error">环境检测未全部通过，请先在宝塔面板中修复上述问题后刷新页面。</div>
  <?php endif; ?>

  <?php if ($formErr !== ''): ?>
    <div class="alert alert-error"><?= htmlspecialchars($formErr) ?></div>
  <?php endif; ?>

  <!-- ── 配置表单 ── -->
  <form method="POST" id="installForm">
    <div class="card">
      <h2>存储配置</h2>

      <input type="hidden" name="storage_mode" id="storageMode" value="local">

      <div class="mode-tabs" style="flex-wrap:wrap">
        <div class="mode-tab active" data-mode="local" onclick="switchMode('local')">
          <strong>📁 本地存储</strong>
          <span>图片保存在服务器</span>
        </div>
        <div class="mode-tab" data-mode="oss" onclick="switchMode('oss')">
          <strong>☁️ 阿里云 OSS</strong>
          <span>阿里云对象存储</span>
        </div>
        <div class="mode-tab" data-mode="r2" onclick="switchMode('r2')">
          <strong>⚡ Cloudflare R2</strong>
          <span>零出口费用</span>
        </div>
        <div class="mode-tab" data-mode="cos" onclick="switchMode('cos')">
          <strong>🐧 腾讯云 COS</strong>
          <span>腾讯云对象存储</span>
        </div>
      </div>

      <div class="oss-fields" id="ossFields">
        <div class="form-group">
          <label>OSS Endpoint <span style="color:var(--red)">*</span></label>
          <input type="text" name="oss_endpoint" placeholder="oss-cn-hangzhou.aliyuncs.com" value="<?= htmlspecialchars($_POST['oss_endpoint'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>AccessKey ID <span style="color:var(--red)">*</span></label>
          <input type="text" name="oss_ak_id" placeholder="LTAI5t..." value="<?= htmlspecialchars($_POST['oss_ak_id'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>AccessKey Secret <span style="color:var(--red)">*</span></label>
          <input type="password" name="oss_ak_secret" placeholder="输入后不会明文显示" value="<?= htmlspecialchars($_POST['oss_ak_secret'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Bucket 名称 <span style="color:var(--red)">*</span></label>
          <input type="text" name="oss_bucket" placeholder="my-draw-bucket" value="<?= htmlspecialchars($_POST['oss_bucket'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>公开访问域名</label>
          <input type="text" name="oss_public_url" placeholder="https://oss.example.com（留空用签名 URL）" value="<?= htmlspecialchars($_POST['oss_public_url'] ?? '') ?>">
          <div class="hint">Bucket 为公共读时填写，支持自定义域名。留空则自动生成签名临时 URL。</div>
        </div>
        <div class="form-group">
          <label>Key 前缀</label>
          <input type="text" name="oss_prefix" placeholder="cang-api-draw" value="<?= htmlspecialchars($_POST['oss_prefix'] ?? 'cang-api-draw') ?>">
        </div>
      </div>

      <div class="oss-fields" id="r2Fields">
        <div class="form-group">
          <label>Account ID <span style="color:var(--red)">*</span></label>
          <input type="text" name="r2_account_id" placeholder="Cloudflare Account ID" value="<?= htmlspecialchars($_POST['r2_account_id'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Access Key ID <span style="color:var(--red)">*</span></label>
          <input type="text" name="r2_ak_id" placeholder="R2 API Token Access Key ID" value="<?= htmlspecialchars($_POST['r2_ak_id'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Secret Access Key <span style="color:var(--red)">*</span></label>
          <input type="password" name="r2_ak_secret" placeholder="输入后不会明文显示" value="<?= htmlspecialchars($_POST['r2_ak_secret'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Bucket 名称 <span style="color:var(--red)">*</span></label>
          <input type="text" name="r2_bucket" placeholder="my-draw-bucket" value="<?= htmlspecialchars($_POST['r2_bucket'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>公开访问域名 <span style="color:var(--red)">*</span></label>
          <input type="text" name="r2_public_url" placeholder="https://your-bucket.r2.dev" value="<?= htmlspecialchars($_POST['r2_public_url'] ?? '') ?>">
          <div class="hint">R2 必须配置公开域名。可在 Cloudflare Dashboard 开启 r2.dev 子域名或绑定自定义域名。</div>
        </div>
        <div class="form-group">
          <label>Key 前缀</label>
          <input type="text" name="r2_prefix" placeholder="cang-api-draw" value="<?= htmlspecialchars($_POST['r2_prefix'] ?? 'cang-api-draw') ?>">
        </div>
      </div>

      <div class="oss-fields" id="cosFields">
        <div class="form-group">
          <label>COS Region <span style="color:var(--red)">*</span></label>
          <input type="text" name="cos_region" placeholder="ap-guangzhou" value="<?= htmlspecialchars($_POST['cos_region'] ?? '') ?>">
          <div class="hint">地域代码，如 ap-guangzhou, ap-shanghai, ap-beijing 等</div>
        </div>
        <div class="form-group">
          <label>SecretId <span style="color:var(--red)">*</span></label>
          <input type="text" name="cos_secret_id" placeholder="AKIDxxxxxxxx" value="<?= htmlspecialchars($_POST['cos_secret_id'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>SecretKey <span style="color:var(--red)">*</span></label>
          <input type="password" name="cos_secret_key" placeholder="输入后不会明文显示" value="<?= htmlspecialchars($_POST['cos_secret_key'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Bucket 名称 <span style="color:var(--red)">*</span></label>
          <input type="text" name="cos_bucket" placeholder="mybucket-1250000000（含 APPID）" value="<?= htmlspecialchars($_POST['cos_bucket'] ?? '') ?>">
          <div class="hint">完整 Bucket 名（含 APPID 后缀），如 mybucket-1250000000</div>
        </div>
        <div class="form-group">
          <label>公开访问域名</label>
          <input type="text" name="cos_public_url" placeholder="https://mybucket-1250000000.cos.ap-guangzhou.myqcloud.com" value="<?= htmlspecialchars($_POST['cos_public_url'] ?? '') ?>">
          <div class="hint">Bucket 为公有读时填写，支持 CDN 或自定义域名。留空则自动生成签名临时 URL。</div>
        </div>
        <div class="form-group">
          <label>Key 前缀</label>
          <input type="text" name="cos_prefix" placeholder="cang-api-draw" value="<?= htmlspecialchars($_POST['cos_prefix'] ?? 'cang-api-draw') ?>">
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary" <?= $allPassed ? '' : 'disabled' ?>>
      安装
    </button>
  </form>

  <script>
  function switchMode(mode) {
    document.getElementById('storageMode').value = mode;
    document.querySelectorAll('.mode-tab').forEach(t => {
      t.classList.toggle('active', t.dataset.mode === mode);
    });
    document.getElementById('ossFields').classList.toggle('show', mode === 'oss');
    document.getElementById('r2Fields').classList.toggle('show', mode === 'r2');
    document.getElementById('cosFields').classList.toggle('show', mode === 'cos');
  }
  <?php $pm = $_POST['storage_mode'] ?? ''; if (in_array($pm, ['oss','r2','cos'], true)): ?>
  switchMode('<?= $pm ?>');
  <?php endif; ?>
  </script>

<?php endif; ?>

</div>
</body>
</html>
