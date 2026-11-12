import assert from "node:assert/strict";
import { spawn } from "node:child_process";

// Keep all PHP workers in one owned process group, separate from the harness.
const [address, ...args] = process.argv.slice(2);
assert.match(address, /^(localhost|127\.0\.0\.1):[0-9]{1,5}$/);
assert.ok(args.length > 0);
const server = spawn("php", ["-S", address, ...args], {
	env: { ...process.env, PHP_CLI_SERVER_WORKERS: "4" },
	stdio: "inherit",
	detached: true,
});
let stopping = false;
function stop() {
	stopping = true;
	if (server.pid === undefined) return;
	try {
		process.kill(-server.pid, "SIGTERM");
	} catch (error) {
		if (error.code !== "ESRCH") throw error;
	}
}
for (const signal of ["SIGTERM", "SIGINT", "SIGHUP"]) process.once(signal, stop);
server.once("error", (error) => {
	process.stderr.write(`${error.message}\n`);
	if (process.connected) process.disconnect();
	process.exitCode = 1;
});
server.once("spawn", () => process.send?.({ group: server.pid }));
server.once("exit", (code) => {
	const expected = stopping;
	stop();
	if (process.connected) process.disconnect();
	process.exitCode = expected ? 0 : (code ?? 1);
});
