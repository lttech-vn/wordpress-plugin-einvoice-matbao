import { describe, expect, test } from "bun:test";
import { errorKeyOf, isWatchedControl, validate, visibleErrors } from "./checkout-rules";

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
	billing_address_1: "Số 1 Đường Thử",
	billing_city: "Hà Nội",
	einv_id_number: "000000001234",
};
const business: Record<string, string> = {
	billing_first_name: "",
	billing_last_name: "",
	billing_phone: "+84 900 000 001",
	billing_email: "buyer@example.invalid",
	billing_address_1: "Số 2 Đường Thử",
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
			["billing_first_name", "billing_address_1", "billing_phone", "einv_id_number"].sort(),
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
		const only = (address_1: string) => ({ ...individual, billing_address_1: address_1, billing_city: "" });
		expect(validate(form("individual", only("ạ".repeat(400))), messages, true)).toEqual({});
		expect(validate(form("individual", only("ạ".repeat(401))), messages, true)).toEqual({
			billing_address_1: "address",
		});
		expect(validate(form("individual", only("😀".repeat(400))), messages, true)).toEqual({});
	});
});

describe("billing address", () => {
	test("is joined from line 1, line 2, city and state, skipping empties", () => {
		const parts = { ...individual, billing_address_1: "a".repeat(190), billing_address_2: "b".repeat(100), billing_city: "c".repeat(100), billing_state: "d".repeat(10) };
		// 190 + 100 + 100 + 10 characters plus three ", " separators is 406.
		expect(validate(form("individual", parts), messages, true)).toEqual({ billing_address_1: "address" });
		expect(validate(form("individual", { ...parts, billing_state: "" }), messages, true)).toEqual({});
	});

	test("is missing only when every part is empty", () => {
		const empty = { ...individual, billing_address_1: "", billing_city: "" };
		expect(validate(form("individual", empty), messages, true)).toEqual({ billing_address_1: "address" });
		expect(validate(form("individual", { ...empty, billing_city: "Hà Nội" }), messages, true)).toEqual({});
	});
});

describe("visibleErrors", () => {
	const errors = { billing_address_1: "address", einv_id_number: "id_number" };

	test("shows nothing before the customer leaves a field or submits", () => {
		expect(visibleErrors(errors, new Set(), false)).toEqual({});
	});

	test("shows errors of fields the customer has left", () => {
		expect(visibleErrors(errors, new Set(["billing_address_1"]), false)).toEqual({ billing_address_1: "address" });
	});

	test("shows every error after a submit attempt", () => {
		expect(visibleErrors(errors, new Set(), true)).toEqual(errors);
	});
});

describe("checkout control helpers", () => {
	test("maps every billing address component to the composed address error key", () => {
		for (const name of ["billing_address_1", "billing_address_2", "billing_city", "billing_state"]) {
			expect(errorKeyOf(name)).toBe("billing_address_1");
		}
		expect(errorKeyOf("billing_phone")).toBe("billing_phone");
		expect(errorKeyOf("einv_tax_code")).toBe("einv_tax_code");
	});

	test("accepts inputs and selects for watched names only", () => {
		expect(isWatchedControl({ tagName: "SELECT", name: "billing_state" })).toBe(true);
		expect(isWatchedControl({ tagName: "INPUT", name: "einv_tax_code" })).toBe(true);
		expect(isWatchedControl({ tagName: "INPUT", name: "billing_company" })).toBe(false);
		expect(isWatchedControl({ tagName: "DIV", name: "billing_state" })).toBe(false);
	});
});
