// Classic checkout invoice request: structural rules, mirroring BuyerRules (PHP).
// PHP stays the authority on submit; this only gives instant feedback. No DOM access here.

export interface BuyerForm {
	type: string;
	get: (name: string) => string;
}

const ID_NUMBER = /^\d{12}$/;
const TAX_CODE = /^\d{10}(-?\d{3})?$/;
const PHONE = /^(?:0|\+?84)(?:[35789]\d{8}|2\d{9})$/;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
export const LIMITS = { fullName: 100, businessName: 400, address: 400, phone: 20, email: 50 };

/** Length in code points, like PHP mb_strlen. */
const length = (text: string): number => Array.from(text).length;

/** Error message by input name (`einv_*` or `billing_*`); `einv_buyer_type` for the radio group. */
export function validate(
	form: BuyerForm,
	messages: Record<string, string>,
	encryptionAvailable: boolean,
): Record<string, string> {
	const errors: Record<string, string> = {};
	if (form.type !== "individual" && form.type !== "business") {
		return { einv_buyer_type: messages.type };
	}
	const address = form.get("einv_address").trim();
	if (address === "" || length(address) > LIMITS.address) errors.einv_address = messages.address;
	const phone = form.get("billing_phone").replace(/[\s.\-()]/g, "");
	if (phone.length > LIMITS.phone || !PHONE.test(phone)) errors.billing_phone = messages.phone;
	if (form.type === "individual") {
		const name = `${form.get("billing_first_name").trim()} ${form.get("billing_last_name").trim()}`.trim();
		if (name === "" || length(name) > LIMITS.fullName) errors.billing_first_name = messages.full_name;
		if (!ID_NUMBER.test(form.get("einv_id_number").trim())) errors.einv_id_number = messages.id_number;
		else if (!encryptionAvailable) errors.einv_id_number = messages.id_encryption;
		return errors;
	}
	const business = form.get("einv_business_name").trim();
	if (business === "" || length(business) > LIMITS.businessName) errors.einv_business_name = messages.business_name;
	if (!TAX_CODE.test(form.get("einv_tax_code").trim())) errors.einv_tax_code = messages.tax_code;
	const email = form.get("billing_email").trim();
	if (email === "" || length(email) > LIMITS.email || !EMAIL.test(email)) errors.billing_email = messages.email;
	return errors;
}

/** Errors to show: all after a submit attempt, otherwise only for fields the customer has left. */
export function visibleErrors(
	errors: Record<string, string>,
	touched: ReadonlySet<string>,
	submitted: boolean,
): Record<string, string> {
	if (submitted) return errors;
	return Object.fromEntries(Object.entries(errors).filter(([name]) => touched.has(name)));
}
