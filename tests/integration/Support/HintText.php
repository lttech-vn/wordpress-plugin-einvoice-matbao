<?php
/**
 * Reads the visible resolution hint from rendered HTML, separate from its data attribute.
 *
 * @package EinvoiceMatbao
 */

namespace LTTech\EinvoiceMatbao\Tests\Integration\Support;

use DOMDocument;
use DOMXPath;

/**
 * Text nodes are asserted on their own element so that `data-einv-hint` cannot satisfy the check.
 */
trait HintText {

	/**
	 * Text content of the element carrying the hint, for a given container of the rendered HTML.
	 *
	 * @param string $html  Rendered HTML.
	 * @param string $xpath XPath of the element whose text is the hint (not the attribute).
	 * @return string Empty when no such element exists.
	 */
	protected function visible_text( string $html, string $xpath ): string {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$nodes = ( new DOMXPath( $document ) )->query( $xpath );

		return false === $nodes || 0 === $nodes->length ? '' : trim( (string) $nodes->item( 0 )->textContent );
	}

	/**
	 * Visible hint of the order meta box: the text of the `<p class="einv-invoice-hint">` element without its label.
	 *
	 * @param string $html Meta box HTML.
	 */
	protected function meta_box_hint_text( string $html ): string {
		$text  = $this->visible_text( $html, '//p[contains(concat(" ", normalize-space(@class), " "), " einv-invoice-hint ")]' );
		$label = __( 'Cách xử lý:', 'einvoice-matbao' );

		return trim( str_starts_with( $text, $label ) ? substr( $text, strlen( $label ) ) : $text );
	}

	/**
	 * Visible hint of the invoice detail: the `<dd>` that follows the "Cách xử lý" `<dt>`.
	 *
	 * @param string $html Detail fragment HTML.
	 */
	protected function detail_hint_text( string $html ): string {
		return $this->visible_text( $html, '//dd[@data-einv-hint]' );
	}
}
