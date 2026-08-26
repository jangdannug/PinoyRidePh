<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Reads .pinoyride-session.json and kills the tunnel + PHP server processes,
// same as STOP-ADMIN.bat but triggered from the browser.
// Also logs the logout and pushes activity logs to git.

$sessionFile = __DIR__ . '/.pinoyride-session.json';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_shutdown'])) {
    // Log the logout
    log_activity('logout', '', '', 'Staff logged out / shutdown');

    // Push logs to git before shutting down (uses token from .env for auth on any device)
    $root = __DIR__;
    $gitExe = trim(shell_exec('where git 2>nul') ?: '');
    if ($gitExe && is_dir($root . '/.git') && is_dir($root . '/logs')) {
        // Read GIT_TOKEN from .env
        $gitToken = getenv('GIT_TOKEN') ?: '';
        $pushUrl = $gitToken !== ''
            ? "https://jangdannug:{$gitToken}@github.com/jangdannug/PinoyRidePh.git"
            : '';

        // Ensure git user is configured (needed for commit on fresh machines)
        exec("git -C \"$root\" config user.email \"pinoyride-admin@local\" 2>&1");
        exec("git -C \"$root\" config user.name \"PinoyRide Admin\" 2>&1");
        // Pull first to avoid conflicts
        if ($pushUrl !== '') {
            exec("git -C \"$root\" pull \"$pushUrl\" main --rebase 2>&1", $gitOut);
        }
        exec("git -C \"$root\" add logs/ 2>&1", $gitOut);
        exec("git -C \"$root\" commit -m \"Activity log - " . date('Y-m-d H:i') . " - " . current_staff() . "\" 2>&1", $gitOut);
        if ($pushUrl !== '') {
            exec("git -C \"$root\" push \"$pushUrl\" main 2>&1", $gitOut);
        }
    }

    $killed = [];

    if (file_exists($sessionFile)) {
        $session = json_decode(file_get_contents($sessionFile), true);
        $tunnelPid = (int)($session['tunnelPid'] ?? 0);

        // Kill tunnel
        if ($tunnelPid > 0) {
            exec("taskkill /F /T /PID $tunnelPid 2>&1", $out, $code);
            if ($code === 0) $killed[] = "Tunnel (PID $tunnelPid)";
        }
    }

    // The PHP server is serving this very request, so we schedule its death
    // after sending the response. We use a background cmd that waits 2 seconds
    // then kills the php process on port 8000.
    $batContent = "@echo off\r\ntimeout /t 2 /nobreak >nul\r\ntaskkill /F /FI \"IMAGENAME eq php.exe\" /FI \"WINDOWTITLE eq *php*\" >nul 2>&1\r\nfor /f \"tokens=5\" %%a in ('netstat -ano ^| findstr :8000 ^| findstr LISTENING') do taskkill /F /PID %%a >nul 2>&1\r\n";
    $batFile = sys_get_temp_dir() . '\\pinoyride_shutdown_' . getmypid() . '.bat';
    file_put_contents($batFile, $batContent);
    pclose(popen("start /min cmd /c \"$batFile\"", 'r'));

    $killed[] = 'PHP server (shutting down in 2s)';
    $message = 'Shutting down: ' . implode(', ', $killed) . '. You can close this tab.';
}

$tabTitle = 'Shutdown';
$activeNav = '';
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center mt-5">
  <div class="col-md-5">
    <div class="card text-center">
      <div class="card-body p-4">
        <?php if ($message): ?>
          <h5 class="text-success mb-3">Done!</h5>
          <p><?= htmlspecialchars($message) ?></p>
          <p class="text-muted small">Activity logs have been synced. You can close this browser tab now.</p>
        <?php else: ?>
          <h5 class="mb-3">Shutdown Admin Panel</h5>
          <p class="text-muted">This will stop the database tunnel and the PHP server — same as double-clicking STOP-ADMIN.</p>
          <form method="post">
            <button type="submit" name="confirm_shutdown" value="1" class="btn btn-danger"
                    onclick="return confirm('Stop the admin panel? You will need to run START-ADMIN again to use it.');">
              Shutdown
            </button>
            <a href="index.php" class="btn btn-outline-secondary ms-2">Cancel</a>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
