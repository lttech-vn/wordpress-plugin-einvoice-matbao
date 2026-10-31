// Classic checkout: instant structural feedback for the invoice request.
// Mirrors BuyerRules (PHP), which stays the authority on submit. No network, no identity checks.

interface CheckoutConfig {
	messages: Record<string, string>;
	encryptionAvailable: boolean;
}

declare const einvoiceMatbaoCheckout: CheckoutConfig;

const ID_NUMBER = /^\d{12}$/;
const TAX_CODE = /^\d{10}(-?\d{3})?$/;
const PHONE = /^(?:0|\+?84)(?:[35789]\d{8}|2\d{9})$/;
const LIMITS = { fullName: 100, businessName: 400, address: 400, phone: 20, email: 50 };

const byName = (name: string): HTMLInputElement | null =>
	document.querySelector<HTMLInputElement>(`[name="${name}"]`);
const value = (name: string): string => (byName(name)?.value ?? "").trim();

function validate(
	form: { get: (name: string) => string; type: string },
	encryptionAvailable: boolean,
): Record<string, string> {
	const m = einvoiceMatbaoCheckout.messages;
	const errors: Record<string, string> = {};
	if (form.type !== "individual" && form.type !== "business") {
		return { einv_buyer_type: m.type };
	}
	const address = form.get("einv_address");
	if (address === "" || address.length > LIMITS.address) errors.einv_address = m.address;
	const phone = form.get("billing_phone").replace(/[\s.\-()]/g, "");
	if (phone.length > LIMITS.phone || !PHONE.test(phone)) errors.billing_phone = m.phone;
	if (form.type === "individual") {
		const name = `${form.get("billing_first_name")} ${form.get("billing_last_name")}`.trim();
		if (name === "" || name.length > LIMITS.fullName) errors.billing_first_name = m.full_name;
		if (!ID_NUMBER.test(form.get("einv_id_number"))) errors.einv_id_number = m.id_number;
		else if (!encryptionAvailable) errors.einv_id_number = m.id_encryption;
		return errors;
	}
	const business = form.get("einv_business_name");
	if (business === "" || business.length > LIMITS.businessName) errors.einv_business_name = m.business_name;
	if (!TAX_CODE.test(form.get("einv_tax_code"))) errors.einv_tax_code = m.tax_code;
	const email = form.get("billing_email");
	if (email === "" || email.length > LIMITS.email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
		errors.billing_email = m.email;
	}
	return errors;
}

function init(): void {
	const box = document.getElementById("einv-invoice-fields");
	const checkbox = byName("einv_request_invoice");
	if (!box || !checkbox) return;
	const encryptionAvailable = einvoiceMatbaoCheckout.encryptionAvailable;

	const selectedType = (): string =>
		document.querySelector<HTMLInputElement>('[name="einv_buyer_type"]:checked')?.value ?? "";

	const clearErrors = (): void => {
		document.querySelectorAll(".einv-error").forEach((el) => el.remove());
	};

	const render = (): void => {
		box.hidden = !checkbox.checked;
		const type = selectedType();
		box.querySelectorAll<HTMLElement>("[data-einv-only]").forEach((row) => {
			row.hidden = row.dataset.einvOnly !== type;
		});
	};

	const check = (): Record<string, string> => {
		clearErrors();
		if (!checkbox.checked) return {};
		const errors = validate({ type: selectedType(), get: value }, encryptionAvailable);
		for (const [name, message] of Object.entries(errors)) {
			const target = byName(name) ?? document.getElementById("einv_buyer_type_field");
			const note = document.createElement("span");
			note.className = "einv-error woocommerce-error";
			note.setAttribute("role", "alert");
			note.textContent = message;
			target?.closest("p")?.append(note);
		}
		return errors;
	};

	checkbox.addEventListener("change", () => {
		render();
		check();
	});
	box.addEventListener("input", () => {
		render();
		if (box.querySelector(".einv-error")) check();
	});
	box.addEventListener("change", () => {
		render();
		check();
	});
	// Capture phase so a failing form never reaches WooCommerce's AJAX submit.
	document.addEventListener(
		"submit",
		(event) => {
			if (!(event.target as HTMLElement).matches?.("form.checkout")) return;
			if (Object.keys(check()).length > 0) {
				event.preventDefault();
				event.stopImmediatePropagation();
				box.scrollIntoView({ behavior: "smooth", block: "center" });
			}
		},
		true,
	);
	render();
}

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
else init();
