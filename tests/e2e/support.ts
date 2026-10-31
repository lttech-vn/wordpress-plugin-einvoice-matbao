import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";

// Helpers that reach the Docker Compose WordPress through wp-cli. Nothing here logs credentials.
const root = fileURLToPath(new URL("../..", import.meta.url));

export function wp(...args: string[]): string {
	return execFileSync("docker", ["compose", "exec", "-T", "wordpress", "wp", ...args], { cwd: root, encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] }).trim();
}

export function productId(): string {
	return wp("eval", 'echo wc_get_product_id_by_sku( "E2E-SVC" );');
}

export function latestOrderId(): number {
	return Number(wp("eval", 'echo wc_get_orders( array( "limit" => 1, "orderby" => "id", "order" => "DESC", "return" => "ids" ) )[0];'));
}

// Completes the order like an admin would and runs the queued draft action synchronously.
export function completeOrderAndRunQueue(orderId: number): void {
	wp("eval", `wc_get_order( ${orderId} )->update_status( "completed" );`);
	wp("action-scheduler", "run", "--force");
}
