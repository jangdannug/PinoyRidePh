<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
$tabTitle = 'Adjust Credit';

// Admin credit adjustment — fixes bad top-ups (e.g. an accidental double
// top-up) by writing an explicit ledger entry instead of deleting or
// editing the original wallet_history row. The ledger stays append-only,
// so the mistaken credit and its correction are both auditable.
//
// Direction: 'credit' (labelled "Add Credit", the default) puts money
// back, 'debit' takes money out. A deduction is refused if it would push
// the balance below 0.

$walletId = (int)($_GET['wallet_id'] ?? $_POST['wallet_id'] ?? 0);
$riderId  = (int)($_GET['rider_id'] ?? $_POST['rider_id'] ?? 0);
$wallet   = null;
$rider    = null;
$errorMsg = '';
$formErrors = [];

// Staff must state a reason on every adjustment — it is stored as the
// wallet_history description so the ledger explains itself later.
$direction = ($_SERVER['REQUEST_METHOD'] === 'POST') ? trim($_POST['direction'] ?? '') : 'credit';
$amount    = trim($_POST['amount'] ?? '');
$reason    = trim($_POST['description'] ?? '');

// Recomputed from the ledger (wallet.avail_balance is only a cached column).
$currentBalance = null;

if ($walletId <= 0 && $riderId <= 0) {
    $errorMsg = 'Invalid wallet or rider id.';
} else {
    try {
        $pdo = get_pdo();

        if ($walletId > 0) {
            $walletStmt = $pdo->prepare(
                "SELECT w.*, r.first_name AS rider_fname, r.last_name AS rider_lname, r.code AS rider_code
                 FROM public.wallet w
                 LEFT JOIN public.riders r ON r.id = w.user_id AND w.user_type = 'rider'
                 WHERE w.id = :wallet_id
                   AND w.deleted_at IS NULL
                 LIMIT 1"
            );
            $walletStmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
            $walletStmt->execute();
            $wallet = $walletStmt->fetch();

            if (!$wallet) {
                $errorMsg = 'Wallet not found.';
            } else {
                $riderId = (int)$wallet['user_id'];
            }
        }

        if ($errorMsg === '' && !$wallet && $riderId > 0) {
            // Rider passed without a wallet id — reuse the same wallet
            // rider_show.php reads, so the balance shown here matches the
            // balance the admin just saw on the rider page.
            $findWalletStmt = $pdo->prepare(
                "SELECT w.*,
                        COALESCE(SUM(wt.credit_amount), 0) AS tx_total_credit,
                        COALESCE(SUM(wt.debit_amount), 0)  AS tx_total_debit
                 FROM public.wallet w
                 LEFT JOIN public.wallet_history wt
                        ON wt.wallet_id = w.id AND wt.deleted_at IS NULL
                 WHERE w.user_id = :user_id
                   AND w.user_type = 'rider'
                   AND w.type = 'user-wallet'
                   AND w.deleted_at IS NULL
                 GROUP BY w.id
                 LIMIT 1"
            );
            $findWalletStmt->bindValue(':user_id', $riderId, PDO::PARAM_INT);
            $findWalletStmt->execute();
            $wallet = $findWalletStmt->fetch();

            if ($wallet) {
                $walletId       = (int)$wallet['id'];
                $currentBalance = (float)$wallet['tx_total_credit'] - (float)$wallet['tx_total_debit'];
            }
        }

        if ($errorMsg === '' && $riderId > 0) {
            $riderStmt = $pdo->prepare(
                "SELECT id, code, first_name, last_name FROM public.riders WHERE id = :id LIMIT 1"
            );
            $riderStmt->bindValue(':id', $riderId, PDO::PARAM_INT);
            $riderStmt->execute();
            $rider = $riderStmt->fetch();

            if (!$rider && !$wallet) {
                $errorMsg = 'Rider not found.';
            }
        }

        if ($errorMsg === '' && $wallet && $currentBalance === null) {
            $balanceStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(wt.credit_amount), 0) - COALESCE(SUM(wt.debit_amount), 0)
                 FROM public.wallet_history wt
                 WHERE wt.wallet_id = :wallet_id
                   AND wt.deleted_at IS NULL"
            );
            $balanceStmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
            $balanceStmt->execute();
            $currentBalance = (float)$balanceStmt->fetchColumn();
        }
    } catch (PDOException $e) {
        $errorMsg = 'Query failed: ' . $e->getMessage();
    }
}

$amountVal = 0.0;

if ($errorMsg === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($direction !== 'credit' && $direction !== 'debit') {
        $formErrors[] = 'Choose whether this adjustment adds or deducts credit.';
    }

    if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
        $formErrors[] = 'Enter a valid adjustment amount greater than 0.';
    } else {
        $amountVal = round((float)$amount, 2);
        if ($amountVal <= 0) {
            $formErrors[] = 'Enter a valid adjustment amount greater than 0.';
        }
    }

    if ($reason === '') {
        $formErrors[] = 'A reason is required so this adjustment can be audited later.';
    }

    if ($direction === 'debit' && !$wallet) {
        $formErrors[] = 'This rider has no wallet yet — there is no balance to deduct from.';
    }

    if ($direction === 'debit' && $wallet && $currentBalance !== null
        && ($currentBalance - $amountVal) < -0.000001) {
        $formErrors[] = 'Deduction of ₱' . number_format($amountVal, 2)
            . ' exceeds the current balance of ₱' . number_format($currentBalance, 2)
            . '. An adjustment cannot make the balance negative.';
    }

    if (empty($formErrors)) {
        $pdo->beginTransaction();
        try {
            if (!$wallet) {
                // Credit to a rider with no wallet row yet — create one first
                // (same pattern as the commission-debit block in booking_show.php).
                $walletRefCode = 'WL-' . strtoupper(bin2hex(random_bytes(5)));

                $createWalletStmt = $pdo->prepare(
                    "INSERT INTO public.wallet
                        (ref_code, user_id, user_type, type, avail_balance, credit_amount, debit_amount, status, created_at, updated_at)
                     VALUES
                        (:ref_code, :user_id, 'rider', 'user-wallet', 0, 0, 0, 1, NOW(), NOW())
                     RETURNING id"
                );
                $createWalletStmt->bindValue(':ref_code', $walletRefCode);
                $createWalletStmt->bindValue(':user_id', $riderId, PDO::PARAM_INT);
                $createWalletStmt->execute();
                $walletId = (int)$createWalletStmt->fetchColumn();
            } else {
                // Serialize concurrent adjustments on this wallet.
                $lockStmt = $pdo->prepare(
                    "SELECT id FROM public.wallet WHERE id = :wallet_id FOR UPDATE"
                );
                $lockStmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
                $lockStmt->execute();
            }

            if ($direction === 'debit') {
                // Re-read the balance now that the wallet row is locked, so a
                // concurrent debit can't slip the balance below zero.
                $recheckStmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(wt.credit_amount), 0) - COALESCE(SUM(wt.debit_amount), 0)
                     FROM public.wallet_history wt
                     WHERE wt.wallet_id = :wallet_id
                       AND wt.deleted_at IS NULL"
                );
                $recheckStmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
                $recheckStmt->execute();
                $lockedBalance = (float)$recheckStmt->fetchColumn();

                if (($lockedBalance - $amountVal) < -0.000001) {
                    $pdo->rollBack();
                    $formErrors[] = 'Balance changed while saving — the wallet now holds ₱'
                        . number_format($lockedBalance, 2)
                        . ', so a deduction of ₱' . number_format($amountVal, 2) . ' is no longer allowed.';
                }
            }

            if (empty($formErrors)) {
                // Keep the cached wallet columns in step with the ledger
                // (CLAUDE.md: update both the wallet row and wallet_history).
                if ($direction === 'credit') {
                    $walletUpdateStmt = $pdo->prepare(
                        "UPDATE public.wallet
                         SET avail_balance = avail_balance + :amount,
                             credit_amount  = credit_amount + :amount,
                             updated_at = NOW()
                         WHERE id = :wallet_id"
                    );
                } else {
                    $walletUpdateStmt = $pdo->prepare(
                        "UPDATE public.wallet
                         SET avail_balance = avail_balance - :amount,
                             debit_amount   = debit_amount + :amount,
                             updated_at = NOW()
                         WHERE id = :wallet_id"
                    );
                }
                $walletUpdateStmt->bindValue(':amount', $amountVal);
                $walletUpdateStmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
                $walletUpdateStmt->execute();


                $refCode = 'ADJ-' . strtoupper(bin2hex(random_bytes(5)));

                $insertStmt = $pdo->prepare(
                    "INSERT INTO public.wallet_history
                        (type, credit_amount, debit_amount, time_created, date_created,
                         status, created_at, updated_at, wallet_id,
                         creditor_account_number, debitor_account_number,
                         description, tran_type)
                     VALUES
                        (:type, :credit_amount, :debit_amount, :time_created, :date_created,
                         :status, NOW(), NOW(), :wallet_id,
                         :creditor_account, :debitor_account,
                         :description, 'adjustment')"
                );
                $insertStmt->bindValue(':type', $direction);
                $insertStmt->bindValue(':credit_amount', $direction === 'credit' ? $amountVal : 0.0);
                $insertStmt->bindValue(':debit_amount', $direction === 'debit' ? $amountVal : 0.0);
                $insertStmt->bindValue(':time_created', date('H:i:s'));
                $insertStmt->bindValue(':date_created', date('Y-m-d'));
                $insertStmt->bindValue(':status', $direction === 'credit' ? 1 : 0, PDO::PARAM_INT);
                $insertStmt->bindValue(':wallet_id', $walletId, PDO::PARAM_INT);
                $insertStmt->bindValue(':creditor_account', $direction === 'credit' ? $refCode : null);
                $insertStmt->bindValue(':debitor_account', $direction === 'debit' ? $refCode : null);
                $insertStmt->bindValue(':description', $reason);
                $insertStmt->execute();

                $pdo->commit();

                log_activity(
                    'adjust_wallet_credit',
                    'wallet',
                    (string)$walletId,
                    ($direction === 'credit' ? 'Added ₱' : 'Deducted ₱') . number_format($amountVal, 2)
                        . ' for rider #' . $riderId . ' — ' . $reason
                );

                header('Location: rider_show.php?id=' . $riderId . '&adjusted=1');
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMsg = 'Adjustment failed: ' . $e->getMessage();
        }
    }
}

function val($v): string
{
    return ($v === null || $v === '') ? '—' : htmlspecialchars((string)$v);
}

function fmt_money($v): string
{
    return ($v === null || $v === '') ? '—' : '₱' . number_format((float)$v, 2);
}

$activeNav = 'riders';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
  <?php if ($riderId > 0): ?>
    <a href="rider_show.php?id=<?= (int)$riderId ?>" class="btn btn-sm btn-pr-secondary">&laquo; Back to Rider</a>
  <?php else: ?>
    <a href="riders.php" class="btn btn-sm btn-pr-secondary">&laquo; Back to Riders</a>
  <?php endif; ?>
</div>


<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
<?php else: ?>

  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header bg-white fw-semibold">Adjust Credit</div>
        <div class="card-body">
          <p class="text-muted mb-1">
            <?php if ($wallet): ?>
              Wallet <strong><?= val($wallet['ref_code'] ?? null) ?></strong>
              <?php if (!empty($wallet['rider_fname'])): ?>
                &mdash; <?= val(trim($wallet['rider_fname'] . ' ' . $wallet['rider_lname'])) ?>
              <?php endif; ?>
            <?php else: ?>
              <?= val(trim(($rider['first_name'] ?? '') . ' ' . ($rider['last_name'] ?? ''))) ?>
              (<?= val($rider['code'] ?? null) ?>) has no wallet yet &mdash; one will be created
              automatically if this adjustment adds credit.
            <?php endif; ?>
          </p>

          <?php if ($wallet): ?>
            <p class="mb-3">
              <span class="badge bg-secondary fs-6">Current Balance: <?= fmt_money($currentBalance) ?></span>
            </p>
          <?php endif; ?>

          <?php if (!empty($formErrors)): ?>
            <div class="alert alert-danger">
              <?php foreach ($formErrors as $err): ?>
                <div><?= htmlspecialchars($err) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="post" class="row g-3" id="adjustForm">
            <input type="hidden" name="wallet_id" value="<?= (int)$walletId ?>">
            <input type="hidden" name="rider_id" value="<?= (int)$riderId ?>">

            <div class="col-12">
              <label class="form-label">Direction</label>
              <div class="d-flex gap-4">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="direction" value="credit"
                         id="dir_credit" <?= ($direction !== 'debit' || !$wallet) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="dir_credit">Add Credit</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="direction" value="debit"
                         id="dir_debit" <?= (!$wallet) ? 'disabled' : '' ?>
                         <?= ($direction === 'debit' && $wallet) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="dir_debit">Deduct &mdash; remove credit</label>
                </div>
              </div>
              <div class="form-text">
                <?php if (!$wallet): ?>
                  Deduction is unavailable because this rider has no wallet balance yet.
                <?php else: ?>
                  Deductions cannot take the balance below &#8369;0.
                <?php endif; ?>
              </div>
            </div>


            <div class="col-12">
              <label class="form-label">Amount (₱)</label>
              <input type="number" step="0.01" min="0.01" name="amount" id="adj_amount"
                     class="form-control" placeholder="0.00" value="<?= htmlspecialchars($amount) ?>" required>
              <?php if ($wallet): ?>
                <div class="form-text" id="adj_preview">
                  Balance after adjustment:
                  <strong id="adj_preview_value"><?= fmt_money($currentBalance) ?></strong>
                </div>
              <?php endif; ?>
            </div>

            <div class="col-12">
              <label class="form-label">Reason (required)</label>
              <textarea name="description" class="form-control" rows="3"
                        placeholder="e.g. Duplicate top-up on 2026-10-18 (amount sent twice by mistake)"
                        required><?= htmlspecialchars($reason) ?></textarea>
              <div class="form-text">Saved to the wallet ledger as this transaction's description.</div>
            </div>

            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-pr-primary" id="adjustSubmit">
                <span class="spinner-border spinner-border-sm me-1 d-none" id="adjustSpinner" role="status" aria-hidden="true"></span>
                <span id="adjustSubmitLabel">Apply Adjustment</span>
              </button>
              <a href="rider_show.php?id=<?= (int)$riderId ?>" class="btn btn-pr-secondary" id="adjustCancel">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <?php if ($wallet): ?>
  <script>
  (function () {
    var balance = <?= json_encode((float)$currentBalance) ?>;
    var amountEl = document.getElementById('adj_amount');
    var previewEl = document.getElementById('adj_preview_value');
    if (!amountEl || !previewEl) return;

    function refresh() {
      var amount = parseFloat(amountEl.value);
      if (isNaN(amount)) amount = 0;
      var dir = document.querySelector('input[name="direction"]:checked');
      var next = balance + (dir && dir.value === 'credit' ? amount : -amount);
      previewEl.textContent = '₱' + next.toLocaleString('en-PH', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
      });
      previewEl.className = next < 0 ? 'text-danger' : '';
    }

    amountEl.addEventListener('input', refresh);
    document.querySelectorAll('input[name="direction"]').forEach(function (r) {
      r.addEventListener('change', refresh);
    });
    refresh();
  })();
  </script>
  <?php endif; ?>

  <script>
  (function () {
    var form = document.getElementById('adjustForm');
    var btn = document.getElementById('adjustSubmit');
    var spinner = document.getElementById('adjustSpinner');
    var label = document.getElementById('adjustSubmitLabel');
    var cancel = document.getElementById('adjustCancel');
    if (!form || !btn) return;

    // Loading state: disable the button and show a spinner while the
    // adjustment is being saved, so it can't be double-clicked. The
    // 'submit' event only fires once the browser's own validation passes,
    // so empty/invalid fields still get their normal error messages and
    // the button stays usable. On a server-side error the page re-renders
    // with the button enabled again.
    form.addEventListener('submit', function (e) {
      if (btn.disabled) {
        e.preventDefault();
        return;
      }
      btn.disabled = true;
      if (spinner) spinner.classList.remove('d-none');
      if (label) label.textContent = 'Applying…';
    });

    // Don't let Cancel navigate away mid-save.
    if (cancel) {
      cancel.addEventListener('click', function (e) {
        if (btn.disabled) e.preventDefault();
      });
    }
  })();
  </script>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>

