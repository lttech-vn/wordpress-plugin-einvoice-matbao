// Classic checkout: instant structural feedback for the invoice request.
// Rules live in checkout-rules.ts (mirroring BuyerRules in PHP, the authority on submit).
// Every listener is delegated from `document` and every element is looked up on use, so a theme
// or WooCommerce re-rendering the form (`updated_checkout`) never leaves stale listeners.

import { validate, visibleErrors } from "./checkout-rules";

interface CheckoutConfig {
	messages: Record<string, string>;
	encryptionAvailable: boolean;
}

declare const einvoiceMatbaoCheckout: CheckoutConfig;

const BOX = "einv-invoice-fields";
const TYPE_GROUP = "einv_buyer_type_field";
const OWN_ERROR = "einv-error";

const touched = new Set<string>();
let submitted = false;

const byName = (name: string): HTMLInputElement | null =>
	document.querySelector<HTMLInputElement>(`[name="${name}"]`);
const value = (name: string): string => byName(name)?.value ?? "";
const requested = (): boolean => byName("einv_request_invoice")?.checked ?? false;
const selectedType = (): string =>
	document.querySelector<HTMLInputElement>('[name="einv_buyer_type"]:checked')?.value ?? "";

/** Shows the fields of the request and of the chosen buyer type only. */
function render(): void {
	const box = document.getElementById(BOX);
	if (!box) return;
	box.hidden = !requested();
	const type = selectedType();
	box.querySelectorAll<HTMLElement>("[data-einv-only]").forEach((row) => {
		row.hidden = row.dataset.einvOnly !== type;
	});
}

/** Element that receives `aria-invalid` and the error id for an error key. */
function errorTarget(name: string): HTMLElement | null {
	return name === "einv_buyer_type" ? document.getElementById(TYPE_GROUP) : byName(name);
}

/** Error message element for an error key: the server-rendered slot or one added after the input. */
function errorSlot(name: string, target: HTMLElement): HTMLElement {
	const id = `${name === "einv_buyer_type" ? "einv_buyer_type" : name}_error`;
	let slot = document.getElementById(id);
	if (!slot) {
		slot = document.createElement("span");
		slot.id = id;
		slot.setAttribute("aria-live", "polite");
		(target.closest("p") ?? target.parentElement)?.append(slot);
	}
	return slot;
}

function clearErrors(): void {
	document.querySelectorAll<HTMLElement>(`.${OWN_ERROR}`).forEach((slot) => {
		slot.textContent = "";
		slot.classList.remove(OWN_ERROR, "woocommerce-error");
	});
	document.querySelectorAll<HTMLElement>("[data-einv-invalid]").forEach((el) => {
		el.removeAttribute("aria-invalid");
		el.removeAttribute("data-einv-invalid");
		const described = (el.getAttribute("aria-describedby") ?? "").split(" ").filter((id) => id !== el.dataset.einvErrorId);
		if (described.length > 0) el.setAttribute("aria-describedby", described.join(" "));
		else el.removeAttribute("aria-describedby");
		delete el.dataset.einvErrorId;
	});
}

/** Validates, shows the errors allowed so far and returns every current error. */
function check(): Record<string, string> {
	clearErrors();
	if (!requested()) return {};
	const config = einvoiceMatbaoCheckout;
	const errors = validate({ type: selectedType(), get: value }, config.messages, config.encryptionAvailable);
	for (const [name, message] of Object.entries(visibleErrors(errors, touched, submitted))) {
		const target = errorTarget(name);
		if (!target) continue;
		const slot = errorSlot(name, target);
		slot.textContent = message;
		slot.classList.add(OWN_ERROR, "woocommerce-error");
		target.setAttribute("aria-invalid", "true");
		target.setAttribute("data-einv-invalid", "1");
		const described = (target.getAttribute("aria-describedby") ?? "").split(" ").filter(Boolean);
		if (!described.includes(slot.id)) {
			described.push(slot.id);
			target.dataset.einvErrorId = slot.id;
		}
		target.setAttribute("aria-describedby", described.join(" "));
	}
	return errors;
}

/** Whether an event comes from a field the invoice request validates. */
function isWatched(target: EventTarget | null): target is HTMLInputElement {
	if (!(target instanceof HTMLInputElement)) return false;
	return target.name.startsWith("einv_") || /^billing_(first_name|last_name|phone|email)$/.test(target.name);
}

document.addEventListener("change", (event) => {
	if (!isWatched(event.target)) return;
	if (event.target.name === "einv_request_invoice") {
		touched.clear();
		submitted = false;
	}
	if (event.target.name === "einv_buyer_type") touched.add("einv_buyer_type");
	render();
	check();
});

document.addEventListener("focusout", (event) => {
	if (!isWatched(event.target) || event.target.type === "radio" || event.target.type === "checkbox") return;
	touched.add(event.target.name);
	check();
});

document.addEventListener("input", (event) => {
	// Clear an error as soon as it is fixed; new errors still wait for blur or submit.
	if (isWatched(event.target) && document.querySelector(`.${OWN_ERROR}`)) check();
});

// Capture phase so a failing form never reaches WooCommerce's AJAX submit.
document.addEventListener(
	"submit",
	(event) => {
		if (!(event.target instanceof HTMLFormElement) || !event.target.matches("form.checkout")) return;
		submitted = true;
		if (Object.keys(check()).length > 0) {
			event.preventDefault();
			event.stopImmediatePropagation();
			document.getElementById(BOX)?.scrollIntoView({ behavior: "smooth", block: "center" });
		}
	},
	true,
);

// WooCommerce announces checkout refreshes through jQuery only; re-apply visibility when it does.
const jquery = (window as { jQuery?: (el: Element) => { on: (event: string, handler: () => void) => void } }).jQuery;
jquery?.(document.body).on("updated_checkout", render);

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", render);
else render();
