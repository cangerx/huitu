<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/app-lib.php';
require_once __DIR__ . '/../lib/auth-lib.php';

$lockFile = __DIR__ . '/../runtime/admin-init.lock';

if (file_exists($lockFile)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>已初始化</title></head>';
    echo '<body style="font-family:system-ui;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f7f7f5">';
    echo '<div style="text-align:center"><h2>✅ 管理员已初始化</h2><p style="color:#6b7280">如需重新初始化，请删除 <code>runtime/admin-init.lock</code> 文件。</p>';
    echo '<p><a href="index.html" style="color:#2d5bf0">返回首页</a></p></div>';
    echo '</body></html>';
    exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    
    if (!$name || !$email || !$password) {
        $error = '请填写所有字段';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } elseif (strlen($password) < 6) {
        $error = '密码至少需要6位';
    } elseif ($password !== $passwordConfirm) {
        $error = '两次密码不一致';
    } else {
        try {
            $db = auth_db();
            
            $stmt = $db->query('SELECT COUNT(*) as count FROM users WHERE is_admin = 1');
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                $error = '管理员账号已存在';
            } else {
                $user = auth_create_user($name, $email, $password);
                
                $stmt = $db->prepare('UPDATE users SET is_admin = 1 WHERE id = ?');
                $stmt->execute([$user['id']]);
                
                app_ensure_dir(__DIR__ . '/../runtime');
                file_put_contents($lockFile, json_encode([
                    'initialized_at' => gmdate('c'),
                    'admin_email' => $email,
                ], JSON_PRETTY_PRINT));
                
                $success = true;
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>初始化管理员 - CANG-AI 绘图</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500;600;700&display=swap');

:root {
  --bg: #f5f3f0;
  --panel: rgba(255, 255, 255, 0.92);
  --line: rgba(0, 0, 0, 0.06);
  --text: #1a1a1a;
  --muted: #6b7280;
  --accent: #2d5bf0;
  --red: #ef4444;
  --green: #22c55e;
  --shadow: 0 8px 32px -8px rgba(0, 0, 0, 0.12);
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: "Noto Sans SC", -apple-system, BlinkMacSystemFont, sans-serif;
  background: var(--bg);
  color: var(--text);
  line-height: 1.6;
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  padding: 20px;
}

.container {
  background: var(--panel);
  border-radius: 16px;
  padding: 40px;
  max-width: 480px;
  width: 100%;
  box-shadow: var(--shadow);
}

h1 {
  font-size: 24px;
  font-weight: 700;
  margin-bottom: 8px;
  text-align: center;
}

.subtitle {
  text-align: center;
  color: var(--muted);
  font-size: 14px;
  margin-bottom: 32px;
}

.form-group {
  margin-bottom: 20px;
}

.form-group label {
  display: block;
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 8px;
  color: var(--text);
}

.form-group input {
  width: 100%;
  padding: 12px 16px;
  border: 1px solid var(--line);
  border-radius: 8px;
  font-size: 14px;
  font-family: inherit;
}

.form-group input:focus {
  outline: none;
  border-color: var(--accent);
}

.btn {
  width: 100%;
  padding: 14px;
  border: none;
  border-radius: 8px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  background: var(--accent);
  color: white;
}

.btn:hover {
  opacity: 0.9;
}

.alert {
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 20px;
  font-size: 14px;
}

.alert-error {
  background: rgba(239, 68, 68, 0.1);
  color: #dc2626;
}

.alert-success {
  background: rgba(34, 197, 94, 0.1);
  color: #16a34a;
}

.success-box {
  text-align: center;
}

.success-box .icon {
  font-size: 64px;
  margin-bottom: 16px;
}

.success-box h2 {
  font-size: 20px;
  margin-bottom: 8px;
}

.success-box p {
  color: var(--muted);
  margin-bottom: 24px;
}

.success-box a {
  display: inline-block;
  padding: 12px 24px;
  background: var(--accent);
  color: white;
  text-decoration: none;
  border-radius: 8px;
  font-weight: 600;
  transition: all 0.2s;
}

.success-box a:hover {
  opacity: 0.9;
}
</style>
</head>
<body>
  <div class="container">
    <?php if ($success): ?>
      <div class="success-box">
        <div class="icon">✅</div>
        <h2>管理员创建成功</h2>
        <p>您现在可以使用管理员账号登录系统</p>
        <a href="index.html">前往登录</a>
      </div>
    <?php else: ?>
      <h1>🎨 初始化管理员</h1>
      <p class="subtitle">创建第一个管理员账号</p>
      
      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      
      <form method="POST">
        <div class="form-group">
          <label>用户名</label>
          <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" />
        </div>
        
        <div class="form-group">
          <label>邮箱</label>
          <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" />
        </div>
        
        <div class="form-group">
          <label>密码</label>
          <input type="password" name="password" required minlength="6" />
        </div>
        
        <div class="form-group">
          <label>确认密码</label>
          <input type="password" name="password_confirm" required minlength="6" />
        </div>
        
        <button type="submit" class="btn">创建管理员</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
