// Checkout Blocks editor: a static preview of the invoice request block.

/* eslint-disable @typescript-eslint/no-explicit-any */
const w = window as any;
const { createElement: h } = w.wp.element;
const { __ } = w.wp.i18n;

w.wp.blocks.registerBlockType("einvoice-matbao/invoice-request", {
	edit: () => h("div", w.wp.blockEditor.useBlockProps(), h("label", null, h("input", { type: "checkbox", disabled: true }), " ", __("Yêu cầu xuất hóa đơn", "einvoice-matbao"))),
	save: () => null,
});
