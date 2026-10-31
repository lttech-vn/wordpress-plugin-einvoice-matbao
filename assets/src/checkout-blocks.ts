// Checkout Blocks: the invoice request fields. Convenience only; the Store API extension in
// BlocksCheckout.php re-validates everything. The citizen ID lives in component state and is
// sent only inside the checkout extension data; it is never written to a data store or the URL.
// WordPress/WooCommerce packages come from their script globals (no bundling, no CDN).

import { validate, visibleErrors } from "./checkout-rules";

interface BlocksConfig {
	messages: Record<string, string>;
	encryptionAvailable: boolean;
}

/* eslint-disable @typescript-eslint/no-explicit-any */
const w = window as any;
const { createElement: h, useState, useEffect } = w.wp.element;
const { __ } = w.wp.i18n;
const NAMESPACE = "einvoice-matbao";
const VALIDATION_ID = "einvoice-matbao-request";

const config = (): BlocksConfig =>
	w.wc.wcSettings.getSetting(`${NAMESPACE}_data`, { messages: {}, encryptionAvailable: false });

interface Values {
	type: string;
	business_name: string;
	tax_code: string;
	address: string;
	id_number: string;
}

const EMPTY: Values = { type: "", business_name: "", tax_code: "", address: "", id_number: "" };

function field(name: string, label: string, value: string, onChange: (v: string) => void, error: string | undefined, maxLength: number) {
	const id = `einv_${name}`;
	return h(
		"div",
		{ className: "wc-block-components-text-input", key: id },
		h("input", {
			id,
			type: "text",
			value,
			maxLength,
			autoComplete: "off",
			"aria-required": "true",
			"aria-invalid": error ? "true" : undefined,
			"aria-describedby": error ? `${id}_error` : undefined,
			onChange: (e: any) => onChange(e.target.value),
		}),
		h("label", { htmlFor: id }, label),
		error ? h("div", { className: "wc-block-components-validation-error", id: `${id}_error`, role: "alert" }, error) : null,
	);
}

function InvoiceRequest({ checkoutExtensionData }: { checkoutExtensionData: { setExtensionData: (ns: string, data: Record<string, unknown>) => void } }) {
	const settings = config();
	const [requested, setRequested] = useState(false);
	const [values, setValues] = useState<Values>(EMPTY);
	const billing = w.wp.data.useSelect((select: any) => select("wc/store/cart").getCustomerData().billingAddress, []);
	const { setValidationErrors, clearValidationError } = w.wp.data.useDispatch("wc/store/validation");

	const set = (key: keyof Values) => (v: string) => setValues((current) => ({ ...current, [key]: v }));
	const get = (name: string): string => {
		const map: Record<string, string> = {
			einv_address: values.address,
			einv_id_number: values.id_number,
			einv_business_name: values.business_name,
			einv_tax_code: values.tax_code,
			billing_first_name: billing?.first_name ?? "",
			billing_last_name: billing?.last_name ?? "",
			billing_phone: billing?.phone ?? "",
			billing_email: billing?.email ?? "",
		};
		return map[name] ?? "";
	};
	const errors = requested ? validate({ type: values.type, get }, settings.messages, settings.encryptionAvailable) : {};
	const first = Object.values(errors)[0];

	useEffect(() => {
		const { type, ...rest } = values;
		checkoutExtensionData.setExtensionData(NAMESPACE, requested ? { request_invoice: true, buyer_type: type, ...rest } : { request_invoice: false });
	}, [requested, values]);

	// A removed block must not leave a ticked request behind in the checkout store.
	useEffect(() => () => checkoutExtensionData.setExtensionData(NAMESPACE, { request_invoice: false }), []);

	useEffect(() => {
		if (first) setValidationErrors({ [VALIDATION_ID]: { message: first, hidden: false } });
		else clearValidationError(VALIDATION_ID);
		return () => clearValidationError(VALIDATION_ID);
	}, [first]);

	const shown = visibleErrors(errors, new Set(Object.keys(errors)), true);
	const radio = (value: string, label: string, disabled = false) =>
		h(
			"label",
			{ key: value, className: "wc-block-components-radio-control__option" },
			h("input", { type: "radio", name: "einv_buyer_type", value, checked: values.type === value, disabled, onChange: () => set("type")(value) }),
			h("span", null, label),
		);

	return h(
		"div",
		{ className: "einv-invoice-request" },
		h(
			"label",
			{ className: "wc-block-components-checkbox" },
			h("input", { type: "checkbox", className: "wc-block-components-checkbox__input", checked: requested, onChange: (e: any) => setRequested(e.target.checked) }),
			h("span", { className: "wc-block-components-checkbox__label" }, __("Yêu cầu xuất hóa đơn", "einvoice-matbao")),
		),
		requested
			? h(
					"div",
					{ className: "einv-invoice-fields" },
					h(
						"fieldset",
						null,
						h("legend", null, __("Xuất hóa đơn cho", "einvoice-matbao")),
						radio("individual", __("Cá nhân", "einvoice-matbao"), !settings.encryptionAvailable),
						radio("business", __("Doanh nghiệp", "einvoice-matbao")),
						!settings.encryptionAvailable ? h("small", null, __("Hiện cửa hàng chưa nhận được xuất hóa đơn cho cá nhân.", "einvoice-matbao")) : null,
						shown.einv_buyer_type ? h("div", { className: "wc-block-components-validation-error", role: "alert" }, shown.einv_buyer_type) : null,
					),
					values.type === "business" ? field("business_name", __("Tên doanh nghiệp", "einvoice-matbao"), values.business_name, set("business_name"), shown.einv_business_name, 400) : null,
					values.type === "business" ? field("tax_code", __("Mã số thuế", "einvoice-matbao"), values.tax_code, set("tax_code"), shown.einv_tax_code, 14) : null,
					values.type !== "" ? field("address", __("Địa chỉ xuất hóa đơn", "einvoice-matbao"), values.address, set("address"), shown.einv_address, 400) : null,
					values.type === "individual" ? field("id_number", __("Số CCCD", "einvoice-matbao"), values.id_number, set("id_number"), shown.einv_id_number, 12) : null,
					shown.billing_first_name || shown.billing_phone || shown.billing_email
						? h("div", { className: "wc-block-components-validation-error", role: "alert" }, shown.billing_first_name ?? shown.billing_phone ?? shown.billing_email)
						: null,
				)
			: null,
	);
}

w.wc.blocksCheckout.registerCheckoutBlock({
	metadata: { name: "einvoice-matbao/invoice-request", parent: ["woocommerce/checkout-fields-block"] },
	component: InvoiceRequest,
});
