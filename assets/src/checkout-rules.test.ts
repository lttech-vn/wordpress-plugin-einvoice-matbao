import { describe, expect, test } from "bun:test";
import { validate, visibleErrors } from "./checkout-rules";

const messages = {
	type: "type",
	full_name: "full_name",
	business_name: "business_name",
	tax_code: "tax_code",
	address: "address",
	id_number: "id_number",
	id_encryption: "id_encryption",
	phone: "phone",
	email: "email",
};

// Obviously fake identity numbers.
const individual: Record<string, string> = {
	billing_first_name: "Nguyễn",
	billing_last_name: "Văn A",
	billing_phone: "0900000001",
	billing_email: "",
	einv_address: "Số 1 Đường Thử",
	einv_id_number: "000000001234",
};
const business: Record<string, string> = {
	billing_first_name: "",
	billing_last_name: "",
	billing_phone: "+84 900 000 001",
	billing_email: "buyer@example.invalid",
	einv_address: "Số 2 Đường Thử",
	einv_business_name: "Doanh nghiệp Thử",
	einv_tax_code: "0000000000-001",
};

const form = (type: string, values: Record<string, string>) => ({ type, get: (name: string) => values[name] ?? "" });

describe("validate", () => {
	test("requires a buyer type", () => {
		expect(validate(form("", individual), messages, true)).toEqual({ einv_buyer_type: "type" });
	});

	test("accepts a valid individual and a valid business without a contact name", () => {
		expect(validate(form("individual", individual), messages, true)).toEqual({});
		expect(validate(form("business", business), messages, false)).toEqual({});
	});

	test("individual needs name, address, a 12-digit CCCD and a phone", () => {
		expect(Object.keys(validate(form("individual", {}), messages, true)).sort()).toEqual(
			["billing_first_name", "billing_phone", "einv_address", "einv_id_number"].sort(),
		);
		expect(validate(form("individual", { ...individual, einv_id_number: "00000000123" }), messages, true)).toEqual({
			einv_id_number: "id_number",
		});
	});

	test("individual is refused without an encryption key", () => {
		expect(validate(form("individual", individual), messages, false)).toEqual({ einv_id_number: "id_encryption" });
	});

	test("business ignores individual fields and needs a valid tax code and email", () => {
		expect(validate(form("business", { ...business, einv_id_number: "x" }), messages, false)).toEqual({});
		expect(
			validate(form("business", { ...business, einv_tax_code: "0000000000-01", billing_email: "nope" }), messages, false),
		).toEqual({ einv_tax_code: "tax_code", billing_email: "email" });
	});

	test("lengths count characters like mb_strlen", () => {
		expect(validate(form("individual", { ...individual, einv_address: "ạ".repeat(400) }), messages, true)).toEqual({});
		expect(validate(form("individual", { ...individual, einv_address: "ạ".repeat(401) }), messages, true)).toEqual({
			einv_address: "address",
		});
		expect(validate(form("individual", { ...individual, einv_address: "😀".repeat(400) }), messages, true)).toEqual({});
	});
});

describe("visibleErrors", () => {
	const errors = { einv_address: "address", einv_id_number: "id_number" };

	test("shows nothing before the customer leaves a field or submits", () => {
		expect(visibleErrors(errors, new Set(), false)).toEqual({});
	});

	test("shows errors of fields the customer has left", () => {
		expect(visibleErrors(errors, new Set(["einv_address"]), false)).toEqual({ einv_address: "address" });
	});

	test("shows every error after a submit attempt", () => {
		expect(visibleErrors(errors, new Set(), true)).toEqual(errors);
	});
});
