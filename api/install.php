<?php
/**
 * CANG-AI 绘图 - 系统安装向导
 * Copyright (c) 2025 苍洱 (CANG-AI). All rights reserved.
 */
declare(strict_types=1);

// ============================================================
//  CANG-AI 绘图 · 系统安装向导
//  安装完成后会生成 install.lock 锁定此页面
// ============================================================

$lockFile   = __DIR__ . '/../runtime/install.lock';
$runtimeDir = __DIR__ . '/../runtime';
$imagesDir  = $runtimeDir . '/images';
$taskDir    = $runtimeDir . '/generation-task-files';
$ossConfig  = __DIR__ . '/../config/oss-config.php';
$r2Config   = __DIR__ . '/../config/r2-config.php';
$cosConfig  = __DIR__ . '/../config/cos-config.php';

require_once __DIR__ . '/../lib/app-lib.php';
require_once __DIR__ . '/../lib/auth-lib.php';

// ── 已安装检测 ──
if (file_exists($lockFile)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>已安装</title></head>';
    echo '<body style="font-family:system-ui;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f7fafc">';
    echo '<div style="text-align:center;background:white;padding:40px;border-radius:12px;box-shadow:0 4px 6px rgba(0,0,0,0.1)">';
    echo '<h2 style="color:#2d3748;margin-bottom:12px">✅ 系统已安装</h2>';
    echo '<p style="color:#718096;margin-bottom:20px">如需重新安装，请删除 <code style="background:#edf2f7;padding:2px 6px;border-radius:4px">install.lock</code> 文件后刷新。</p>';
    echo '<a href="../index.html" style="display:inline-block;padding:10px 20px;background:#4c51bf;color:white;text-decoration:none;border-radius:6px">返回首页</a>';
    echo '</div></body></html>';
    exit;
}

// ── 环境检测 ──
function checkEnv(): array {
    $runtimeDir = __DIR__ . '/../runtime';
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
        'ok'    => is_writable($runtimeDir) || is_writable(dirname($runtimeDir)),
        'value' => (is_writable($runtimeDir) || is_writable(dirname($runtimeDir))) ? '可写' : '不可写',
    ];
    return $checks;
}

// ── 处理 POST ──
$result  = null;
$formErr = '';
$step = (int)($_POST['step'] ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 1) {
        // 步骤1：存储配置
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
                    $formErr = "OSS 验证失败 (HTTP {$status})，请检查配置";
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

        if ($formErr === '' && $mode === 'local') {
            // 本地模式：移除旧的配置（如有）
            foreach ([$ossConfig, $r2Config, $cosConfig] as $cf) {
                if (file_exists($cf)) @unlink($cf);
            }
        }

        if ($formErr === '') {
            $step = 2;
        }
    } elseif ($step === 2) {
        // 步骤2：创建管理员
        $name = trim($_POST['admin_name'] ?? '');
        $email = trim($_POST['admin_email'] ?? '');
        $password = $_POST['admin_password'] ?? '';
        $passwordConfirm = $_POST['admin_password_confirm'] ?? '';

        if (strlen($name) < 2 || strlen($name) > 50) {
            $formErr = '用户名长度必须在2-50个字符之间';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $formErr = '邮箱格式不正确';
        } elseif (strlen($password) < 6 || strlen($password) > 100) {
            $formErr = '密码长度必须在6-100个字符之间';
        } elseif ($password !== $passwordConfirm) {
            $formErr = '两次密码不一致';
        } else {
            try {
                $db = auth_db();
                
                // 检查是否已有管理员
                $stmt = $db->query('SELECT COUNT(*) as count FROM users WHERE is_admin = 1');
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    $formErr = '管理员账号已存在';
                } else {
                    // 创建管理员
                    $user = auth_create_user($name, $email, $password);
                    
                    $stmt = $db->prepare('UPDATE users SET is_admin = 1, credits = 100 WHERE id = ?');
                    $stmt->execute([$user['id']]);
                    
                    // 写入锁文件
                    $lockContent = json_encode([
                        'installed_at' => date('c'),
                        'storage_mode' => $_POST['storage_mode'] ?? 'local',
                        'php_version'  => PHP_VERSION,
                        'admin_email'  => $email,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    @file_put_contents($lockFile, $lockContent);
                    
                    $result = 'success';
                }
            } catch (Exception $e) {
                $formErr = $e->getMessage();
            }
        }
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
<title>系统安装向导 - CANG-AI 绘图</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f7fafc;--card:#fff;--border:#e2e8f0;--text:#2d3748;--text-light:#4a5568;
  --muted:#718096;--accent:#4c51bf;--accent-hover:#434190;--accent-light:#eef2ff;
  --success:#38a169;--success-bg:#f0fff4;--danger:#e53e3e;--danger-bg:#fff5f5;
  --warning:#dd6b20;--warning-bg:#fffaf0;--radius:8px;--radius-lg:12px;
  --shadow:0 4px 6px -1px rgba(0,0,0,0.1),0 2px 4px -1px rgba(0,0,0,0.06);
}
body{
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
  background:var(--bg);color:var(--text);line-height:1.6;
  min-height:100vh;padding:40px 20px;
}
.container{max-width:600px;margin:0 auto}
.logo{text-align:center;margin-bottom:32px}
.logo h1{font-size:28px;font-weight:700;color:var(--text);margin-bottom:8px}
.logo p{color:var(--muted);font-size:14px}
.card{
  background:var(--card);border:1px solid var(--border);border-radius:var(--radius-lg);
  padding:32px;margin-bottom:24px;box-shadow:var(--shadow);
}
.card h2{font-size:18px;font-weight:700;margin-bottom:20px;color:var(--text)}

.steps{display:flex;justify-content:center;gap:12px;margin-bottom:32px}
.step{
  display:flex;align-items:center;gap:8px;padding:10px 16px;
  background:var(--card);border:1px solid var(--border);border-radius:var(--radius);
  font-size:14px;color:var(--muted);
}
.step.active{border-color:var(--accent);background:var(--accent-light);color:var(--accent);font-weight:600}
.step.completed{border-color:var(--success);background:var(--success-bg);color:var(--success)}
.step-num{
  width:24px;height:24px;border-radius:50%;background:var(--border);
  display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;
}
.step.active .step-num{background:var(--accent);color:white}
.step.completed .step-num{background:var(--success);color:white}

.check-list{list-style:none}
.check-item{
  display:flex;align-items:center;gap:12px;padding:12px 0;
  border-bottom:1px solid var(--border);font-size:14px;
}
.check-item:last-child{border-bottom:none}
.check-icon{
  width:24px;height:24px;border-radius:50%;display:flex;
  align-items:center;justify-content:center;font-size:12px;flex-shrink:0;font-weight:700;
}
.check-ok{background:var(--success-bg);color:var(--success)}
.check-fail{background:var(--danger-bg);color:var(--danger)}
.check-label{flex:1;font-weight:500}
.check-value{color:var(--muted);font-size:13px}

.mode-tabs{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:24px}
.mode-tab{
  padding:16px;border:2px solid var(--border);border-radius:var(--radius);
  background:var(--card);cursor:pointer;text-align:center;transition:all .2s;
}
.mode-tab:hover{border-color:var(--accent)}
.mode-tab.active{border-color:var(--accent);background:var(--accent-light)}
.mode-tab strong{display:block;font-size:15px;margin-bottom:4px;color:var(--text)}
.mode-tab span{font-size:13px;color:var(--muted)}

.form-group{margin-bottom:20px}
.form-group label{display:block;font-size:14px;font-weight:600;margin-bottom:8px;color:var(--text)}
.form-group .hint{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.5}
.form-group .required{color:var(--danger)}
input[type="text"],input[type="email"],input[type="password"]{
  width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius);
  font-size:14px;font-family:inherit;background:var(--card);color:var(--text);
  transition:border-color .2s;
}
input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-light)}

.alert{
  padding:12px 16px;border-radius:var(--radius);margin-bottom:20px;
  font-size:14px;border-left:4px solid;
}
.alert-success{background:var(--success-bg);color:var(--success);border-color:var(--success)}
.alert-danger{background:var(--danger-bg);color:var(--danger);border-color:var(--danger)}
.alert-warning{background:var(--warning-bg);color:var(--warning);border-color:var(--warning)}

.btn{
  padding:12px 24px;border:none;border-radius:var(--radius);font-size:14px;
  font-weight:600;cursor:pointer;transition:all .2s;display:inline-block;
  text-decoration:none;text-align:center;
}
.btn-primary{background:var(--accent);color:white}
.btn-primary:hover:not(:disabled){background:var(--accent-hover)}
.btn-primary:disabled{opacity:0.6;cursor:not-allowed}
.btn-block{width:100%;display:block}

.success-box{text-align:center;padding:20px}
.success-box .icon{font-size:64px;margin-bottom:16px}
.success-box h3{font-size:20px;margin-bottom:12px;color:var(--text)}
.success-box p{color:var(--muted);margin-bottom:24px}
.success-box .btn{margin:0 8px}

.oss-fields{display:none}
.oss-fields.active{display:block}

.footer{text-align:center;margin-top:32px;color:var(--muted);font-size:13px}
</style>
</head>
<body>
<div class="container">
  <div class="logo">
    <h1>🎨 CANG-AI 绘图</h1>
    <p>系统安装向导</p>
  </div>

  <?php if ($result === 'success'): ?>
    <div class="card">
      <div class="success-box">
        <div class="icon">✅</div>
        <h3>安装成功！</h3>
        <p>系统已成功安装并初始化，您现在可以开始使用了</p>
        <a href="../admin-login.html" class="btn btn-primary">进入管理后台</a>
        <a href="../index.html" class="btn btn-primary">访问首页</a>
      </div>
    </div>
  <?php else: ?>

  <div class="steps">
    <div class="step <?= $step === 1 ? 'active' : ($step > 1 ? 'completed' : '') ?>">
      <span class="step-num"><?= $step > 1 ? '✓' : '1' ?></span>
      <span>环境检测</span>
    </div>
    <div class="step <?= $step === 2 ? 'active' : ($step > 2 ? 'completed' : '') ?>">
      <span class="step-num"><?= $step > 2 ? '✓' : '2' ?></span>
      <span>创建管理员</span>
    </div>
  </div>

  <?php if ($step === 1): ?>
    <!-- 步骤1：环境检测和存储配置 -->
    <div class="card">
      <h2>环境检测</h2>
      <?php if (!$allPassed): ?>
        <div class="alert alert-danger">
          ⚠️ 环境检测未通过，请先解决以下问题后再继续安装
        </div>
      <?php endif; ?>
      <ul class="check-list">
        <?php foreach ($envChecks as $check): ?>
          <li class="check-item">
            <span class="check-icon <?= $check['ok'] ? 'check-ok' : 'check-fail' ?>">
              <?= $check['ok'] ? '✓' : '✗' ?>
            </span>
            <span class="check-label"><?= htmlspecialchars($check['label']) ?></span>
            <span class="check-value"><?= htmlspecialchars($check['value']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <?php if ($allPassed): ?>
    <div class="card">
      <h2>存储配置</h2>
      <?php if ($formErr): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($formErr) ?></div>
      <?php endif; ?>
      
      <form method="POST">
        <input type="hidden" name="step" value="1">
        
        <div class="mode-tabs">
          <label class="mode-tab active" onclick="switchMode('local')">
            <input type="radio" name="storage_mode" value="local" checked style="display:none">
            <strong>本地存储</strong>
            <span>文件存储在服务器</span>
          </label>
          <label class="mode-tab" onclick="switchMode('oss')">
            <input type="radio" name="storage_mode" value="oss" style="display:none">
            <strong>OSS 存储</strong>
            <span>阿里云对象存储</span>
          </label>
        </div>

        <div id="ossFields" class="oss-fields">
          <div class="form-group">
            <label>Endpoint <span class="required">*</span></label>
            <input type="text" name="oss_endpoint" placeholder="oss-cn-hangzhou.aliyuncs.com">
            <div class="hint">OSS 区域节点，如 oss-cn-hangzhou.aliyuncs.com</div>
          </div>
          <div class="form-group">
            <label>Access Key ID <span class="required">*</span></label>
            <input type="text" name="oss_ak_id" placeholder="LTAI...">
          </div>
          <div class="form-group">
            <label>Access Key Secret <span class="required">*</span></label>
            <input type="password" name="oss_ak_secret" placeholder="输入后不会明文显示">
          </div>
          <div class="form-group">
            <label>Bucket 名称 <span class="required">*</span></label>
            <input type="text" name="oss_bucket" placeholder="my-bucket">
          </div>
          <div class="form-group">
            <label>公开访问域名</label>
            <input type="text" name="oss_public_url" placeholder="https://cdn.example.com（可选）">
            <div class="hint">Bucket 为公共读时填写，支持自定义域名。留空则使用签名 URL</div>
          </div>
          <div class="form-group">
            <label>Key 前缀</label>
            <input type="text" name="oss_prefix" placeholder="cang-api-draw" value="cang-api-draw">
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">下一步</button>
      </form>
    </div>
    <?php endif; ?>

  <?php elseif ($step === 2): ?>
    <!-- 步骤2：创建管理员 -->
    <div class="card">
      <h2>创建管理员账号</h2>
      <p style="color:var(--muted);font-size:14px;margin-bottom:24px">
        请创建系统管理员账号，用于登录管理后台
      </p>
      
      <?php if ($formErr): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($formErr) ?></div>
      <?php endif; ?>
      
      <form method="POST">
        <input type="hidden" name="step" value="2">
        <input type="hidden" name="storage_mode" value="<?= htmlspecialchars($_POST['storage_mode'] ?? 'local') ?>">
        
        <div class="form-group">
          <label>用户名 <span class="required">*</span></label>
          <input type="text" name="admin_name" required placeholder="管理员" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>">
          <div class="hint">2-50个字符</div>
        </div>
        
        <div class="form-group">
          <label>邮箱 <span class="required">*</span></label>
          <input type="email" name="admin_email" required placeholder="admin@example.com" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>">
          <div class="hint">用于登录管理后台</div>
        </div>
        
        <div class="form-group">
          <label>密码 <span class="required">*</span></label>
          <input type="password" name="admin_password" required placeholder="至少6位">
          <div class="hint">6-100个字符</div>
        </div>
        
        <div class="form-group">
          <label>确认密码 <span class="required">*</span></label>
          <input type="password" name="admin_password_confirm" required placeholder="再次输入密码">
        </div>
        
        <button type="submit" class="btn btn-primary btn-block">完成安装</button>
      </form>
    </div>
  <?php endif; ?>

  <?php endif; ?>

  <div class="footer">
    <p>CANG-AI 绘图管理系统 &copy; 2026</p>
  </div>
</div>

<script>
function switchMode(mode) {
  document.querySelectorAll('.mode-tab').forEach(tab => tab.classList.remove('active'));
  event.currentTarget.classList.add('active');
  document.querySelectorAll('input[name="storage_mode"]').forEach(input => {
    input.checked = input.value === mode;
  });
  
  if (mode === 'oss') {
    document.getElementById('ossFields').classList.add('active');
  } else {
    document.getElementById('ossFields').classList.remove('active');
  }
}
</script>
</body>
</html>
