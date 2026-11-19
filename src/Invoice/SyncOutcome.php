<?php
/**
 * Result of syncing the Mat Bao status of one invoice.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Invoice;

/**
 * What one status sync did to one invoice.
 */
enum SyncOutcome: string {

	/** The Mat Bao status was written. */
	case UPDATED = 'updated';

	/** The row changed meanwhile or nothing differed; nothing was written. */
	case UNCHANGED = 'unchanged';

	/** Mat Bao did not confirm the invoice either way; the stored status is kept. */
	case UNCONFIRMED = 'unconfirmed';

	/** The lookup or the write failed; the stored status is kept. */
	case FAILED = 'failed';
}
