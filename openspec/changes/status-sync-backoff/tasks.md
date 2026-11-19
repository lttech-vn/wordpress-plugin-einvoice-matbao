## 1. Status sync stops early and backs off

Blocked by: none

- [ ] 1.1 Add `StatusSyncBackoff` (`src/Invoice/StatusSyncBackoff.php`): option `einvoice_matbao_status_sync_backoff`, ladder 15m/30m/1h/2h/4h/6h, `is_paused()`, `record_failure()`, `clear()`, single retry action `einvoice_matbao_status_sync_retry` (one queued at a time)
- [ ] 1.2 `MatbaoStatusSync::sync_batch()`: skip while paused; stop at the first temporary lookup failure and record it; keep going on other failures; clear on a successful lookup; register the retry hook to `run()`
- [ ] 1.3 `MatbaoStatusSync::sync_one()`: never blocked; success clears the backoff, failure leaves it
- [ ] 1.4 Update `test_lookup_503_keeps_status_and_continues` and add integration tests in `tests/integration/MatbaoStatusSyncTest.php` for every scenario of the change's `matbao-status-sync` delta except "Backoff state removed on uninstall" (covered in 2.1)

## 2. Uninstall and docs

Blocked by: 1

- [ ] 2.1 `Uninstaller`: add `STATUS_SYNC_BACKOFF_OPTION`, delete it on default uninstall; name-match and removal tests in `tests/integration/UninstallTest.php`
- [ ] 2.2 Update `docs/phat-trien.md` (MatbaoStatusSync section) and `docs/huong-dan-su-dung.md` (Đồng bộ trạng thái Mắt Bão) with the stop-early and backoff behaviour
- [ ] 2.3 Run `composer check` (lint, phpstan, integration tests) green
