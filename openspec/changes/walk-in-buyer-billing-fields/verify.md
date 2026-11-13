# Verification Report

**Change**: `walk-in-buyer-billing-fields`
**Verified at**: `2026-11-13 09:20`
**Iteration**: `1`
**Verifier**: `parent agent (inline; evidence collected in this run in the worktree)`

---

## 1. Structural Validation (`openspec validate <change>`)

- [x] The command passes for this change

```text
Change 'walk-in-buyer-billing-fields' is valid
```

---

## 2. Task Completion (`tasks.md`)

- [x] Every box is ticked

`rg -c '^- \[ \]'` = 0 open, `rg -c '^- \[x\]'` = 12. `openspec instructions apply` reports progress 12/12.

| Open task | Reason | Blocks archive? |
|---|---|---|
| — | — | — |

---

## 3. Requirement Evidence

- [x] ADDED / MODIFIED: implementation evidence
- [x] REMOVED: none in this change
- [x] RENAMED: none in this change

| Requirement | Operation | Status | Evidence |
|---|---|---|---|
| Invoice address from billing (buyer-invoice-request) | ADDED | Pass | `src/Buyer/BillingAddress.php:25,43,62` (from_array/from_order/join); Classic `src/Checkout/ClassicCheckout.php:169`; Blocks `src/Checkout/BlocksCheckout.php:143`; `einv_address` no longer in `src/` or `assets/src` (only a negative assertion in `tests/integration/ClassicCheckoutBuyerTest.php:333`); legacy `address` kept ignored `BlocksCheckout.php:90` |
| Individual buyer validation | MODIFIED | Pass | `src/Buyer/BuyerRules.php:52,98` (billing address required, 400 limit, message points to billing); `assets/src/checkout-rules.ts:23,38` |
| Business buyer validation | MODIFIED | Pass | same rules; `BuyerRules.php` business branch; `checkout-rules.ts:38` |
| Request stored on order with encrypted CCCD | MODIFIED | Pass | `src/Buyer/BuyerRequestStore.php:42` writes `address` as `''`; `:85` still reads it |
| Classic checkout fields | MODIFIED | Pass | field removed from `ClassicCheckout.php`; test `ClassicCheckoutBuyerTest::test_classic_form_has_no_address_field`; e2e replay |
| Walk-in buyer billing fields (buyer-snapshot-privacy) | ADDED | Pass | `src/Buyer/BuyerSnapshotter.php:133-205` (`build_walk_in` and per-field filters, `mb_substr` at `:157,:174`, phone `:185`, email `:200`); `personal` flag `BuyerSnapshot.php:36,79,99` |
| Buyer snapshot per buyer type | MODIFIED | Pass | `BuyerSnapshotter.php:213-217` address precedence (billing, else legacy request address), used by `build()` and `validate()` |
| Privacy exporter | MODIFIED | Pass | `src/Privacy/BuyerPersonalData.php:273` (identified buyer includes `personal`), `:255-274` walk-in label |
| Privacy eraser | MODIFIED | Pass | `BuyerPersonalData.php:294` (`personal_values` uses the flag); `:177-195,317-320` anonymization |
| Order-change detection of billing address (design decision; living spec order-change-handling) | n/a (contract deviation) | Pass | `src/Invoice/OrderState.php:63-67` |

---

## 4. Scenario Coverage

- [x] Every scenario maps to a test
- [x] The project test command was run fresh in this step

**Test commands** (worktree, this run):

- `docker compose exec ... composer test:integration` → exit 0, `OK (871 tests, 4499 assertions)`
- `docker compose exec ... composer test:without-woocommerce` → `OK (8 tests, 21 assertions)`
- `docker compose exec ... composer lint` → exit 0
- `docker compose exec ... composer analyse` → `[OK] No errors`
- `bun test assets/src` → 16 pass, 0 fail, 37 expect()
- `bun run e2e:replay` (no model API keys) → exit 0, 9 files, 78 passed, 8 cache replayed

| Scenario | Test |
|---|---|
| Address composed from billing | `BillingAddressTest::test_from_array_joins_all_four_parts_with_the_province_name`, `test_from_order_joins_all_parts_with_the_province_name` |
| Legacy address field ignored | `BlocksCheckoutBuyerTest::test_legacy_address_field_is_ignored`, `test_legacy_address_does_not_replace_an_empty_billing_address` |
| No separate address field | `ClassicCheckoutBuyerTest::test_classic_form_has_no_address_field`; Blocks: no automated test (see warnings) |
| CCCD not 12 digits | `ClassicCheckoutBuyerTest::test_individual_rejects_an_id_number_that_is_not_twelve_digits` |
| International phone form | `ClassicCheckoutBuyerTest::test_individual_rejects_an_invalid_phone_and_accepts_international_form` |
| Length limits (individual) | `ClassicCheckoutBuyerTest::test_individual_rejects_a_billing_address_longer_than_the_mat_bao_limit` |
| Missing billing address (individual) | `ClassicCheckoutBuyerTest::test_individual_requires_a_billing_address`, `BlocksCheckoutBuyerTest::test_a_ticked_request_without_a_billing_address_is_refused`, `checkout-rules.test.ts` |
| Malformed tax code | `ClassicCheckoutBuyerTest::test_business_rejects_a_malformed_tax_code`, `BlocksCheckoutBuyerTest::test_business_with_a_bad_tax_code_or_too_long_billing_email_is_refused` |
| Branch tax code | `ClassicCheckoutBuyerTest::test_business_accepts_ten_digit_and_branch_tax_codes` |
| Length limits (business) | `ClassicCheckoutBuyerTest::test_business_rejects_a_billing_address_longer_than_the_mat_bao_limit` |
| Missing billing address (business) | `ClassicCheckoutBuyerTest::test_business_requires_a_billing_address` |
| Individual stored | `BuyerRequestStoreTest::test_individual_request_encrypts_the_id_number_and_drops_business_fields` |
| Business ignores individual fields | `BuyerRequestStoreTest::test_business_request_keeps_business_fields_and_never_stores_an_id_number` |
| No address stored | `BuyerRequestStoreTest::test_individual_request_...` (`address` is `''`), `test_load_still_reads_the_address_of_a_request_stored_before_billing_fields` |
| Hidden until ticked / Fields per type | e2e `army/checkout-validation.army.ts` (replay 78/78) |
| Re-rendered after failed submit | `ClassicCheckoutBuyerTest::test_fields_never_echo_a_submitted_id_number` |
| Billing complete | `DraftInvoiceBuyerSnapshotTest::test_walk_in_draft_carries_billing_fields` |
| Invalid fields skipped | `DraftInvoiceBuyerSnapshotTest::test_walk_in_skips_invalid_phone_and_email`, `test_walk_in_drops_a_malformed_email_under_the_length_limit` |
| Empty billing | `DraftInvoiceBuyerSnapshotTest::test_walk_in_with_empty_billing_sends_only_the_configured_name` |
| Walk-in / Individual / Business buyer | `DraftInvoiceBuyerSnapshotTest::test_walk_in_draft_carries_billing_fields`, `test_individual_draft_carries_the_snapshot_fields`, `test_business_draft_carries_the_snapshot_fields_without_a_contact_name` |
| Legacy request address fallback | `DraftInvoiceBuyerSnapshotTest::test_legacy_request_address_is_the_fallback` |
| Export customer orders | `BuyerPersonalDataPrivacyTest::test_export_lists_the_buyer_data_of_a_guest_order_with_a_masked_id_number` |
| Export walk-in order with billing data | `BuyerPersonalDataPrivacyTest::test_exports_walk_in_order_with_billing_data` |
| Paged export | `BuyerPersonalDataPrivacyTest::test_paged_export_and_erase_cover_more_orders_than_one_page_and_leave_other_emails_alone` (skipping walk-in orders without data is covered only by the single-order walk-in test) |
| Erase an order with a draft | `BuyerPersonalDataPrivacyTest::test_eraser_removes_the_buyer_meta_and_anonymizes_the_invoice_row_of_that_email_only` |
| Walk-in order | `BuyerPersonalDataPrivacyTest::test_walk_in_order_without_personal_data_is_not_anonymized` |
| Walk-in order with billing data | `BuyerPersonalDataPrivacyTest::test_erases_walk_in_order_with_billing_data`, `test_eraser_scrubs_walk_in_snapshot_values_that_billing_no_longer_holds` |

---

## 5. Design and Contract Coherence (warning only)

- [x] Decisions in design.md match the code
- [x] Contracts in plan.md, with the Contract deviations recorded in apply.md, match the code

Contracts compared read-only:

| Contract | Result |
|---|---|
| `BillingAddress::from_array` / `from_order` | Matched: `BillingAddress.php:25,43`, no truncation in the helper |
| `BuyerRules` address (key/limit/message) | Matched: `BuyerRules.php:52,98` |
| `BuyerRequestStore::from_input` / `load` | Matched: `BuyerRequestStore.php:42,85` |
| `BuyerSnapshot` `personal` | Matched: `BuyerSnapshot.php:36,79,99` |
| `BuyerSnapshotter::build()` | Matched: `BuyerSnapshotter.php:99-217` |
| Store API extension `address` | Matched: kept in schema, ignored (`BlocksCheckout.php:90`) |
| Classic `einv_address` | Matched: not rendered or read |
| `OrderState::encode()` buyer digest (apply.md deviation) | Matched: `OrderState.php:63-67`; tests `PostDraftOrderChangeTest.php:286,305,317` |

**Drift warnings** (non-blocking):

- Client length check composes the state **code** while the server composes the state **name** (`assets/src/checkout-rules.ts:23` vs `BillingAddress.php`): an address near 400 characters may show the error only on submit. Accepted in design.md Risks.
- `plan.md` Task 1 table lists the `BuyerSnapshotter::build()` personal rule loosely; the implemented rule (any billing-derived name/address/phone/email) is the one in design.md.

---

## 6. Clean Tree and Commit Range

- [x] Working tree is clean (`git status --short` empty in the worktree; `.env` and `tests/e2e/test-results` are git-ignored)

**Commit range**: `c6ee475..387c967` (19 commits on `walk-in-buyer-billing-fields`; `apply.md` Count 18 predates the receipt-refresh commit `387c967`)

---

## Overall Decision

- [ ] PASS
- [x] PASS_WITH_WARNINGS: no CRITICAL issues; all fresh test runs green; warnings below
- [ ] FAIL

### Issues

**CRITICAL**: none.

**WARNING**

1. Blocks has no automated check that the old "Địa chỉ xuất hóa đơn" field is gone or that the billing-address error is shown in the Blocks block (only the server side is tested). Recommendation: add an e2e assertion on the Blocks checkout the next time the cache is re-recorded.
2. Client/server address length rule differs near the 400-character limit (state code vs name). Recommendation: expose state labels to the browser only if users report it.
3. Recapturing a `buyer_invalid` order after an admin fills the billing address does not resend; the admin presses "Gửi lại". Recommendation: document it in the user guide if support asks.

**SUGGESTION**

1. The Classic `billing_state` select2 native-event wiring (`assets/src/checkout.ts`) was not exercised in a real browser; the server revalidates on submit.
2. Walk-in orders without data are skipped in export only by the single-order test; add one case to the paged-export test.
3. E2E record runs showed two model/UI noise failures that did not recur (trailing period in a business name; ambiguous `alert` locator in `settings.army.ts`).

**Next step**: run `/opsx-continue` with the worktree as working directory to create the finalize artifact, then `/opsx-archive`. Nothing was pushed or merged.
