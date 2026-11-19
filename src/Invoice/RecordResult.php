<?php
/**
 * Outcome of recording a Mat Bao status.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

/**
 * Result of InvoiceRepository::record_matbao_status(). Only WRITTEN lets the caller alert.
 */
enum RecordResult {

	/** This call changed the row. */
	case WRITTEN;

	/** The guard did not match (another writer, recreated draft) or nothing changed; safe to skip. */
	case STALE;

	/** The statement failed. */
	case FAILED;
}
