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

    // Push logs to git before shutting down - through the SAME sync engine
    // used by START-ADMIN and PinoyRideAdmin-Fix-Update.bat, so every machine
    // converges: commits logs/, holds back other local files, rebases onto
    // everyone else's commits, pushes ONLY if this machine is ahead, then
    // restores everything. (The old inline pull-before-commit here regularly
    // failed with "cannot pull with rebase: You have unstaged changes".)
    $root = __DIR__;
    $syncScript = $root . DIRECTORY_SEPARATOR . 'other-scripts' . DIRECTORY_SEPARATOR . 'sync_repo.ps1';
    $onWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($onWindows && is_dir($root . '/.git') && is_dir($root . '/logs') && file_exists($syncScript)) {
        $cmsg = str_replace(['"', "\r", "\n"], '', 'Activity log - ' . date('Y-m-d H:i') . ' - ' . current_staff());
        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File "'
             . str_replace('"', '', $syncScript)
             . '" -Root "' . str_replace('"', '', $root)
             . '" -Reason "shutdown" -CommitMessage "' . $cmsg . '" 2>&1';
        exec($cmd, $gitOut, $gitExit);
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
            <a href="index.php" class="btn btn-pr-secondary ms-2">Cancel</a>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
