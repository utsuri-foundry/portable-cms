import assert from "node:assert/strict";
import { fork } from "node:child_process";
import { once } from "node:events";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { connect, createServer } from "node:net";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import { setTimeout as delay } from "node:timers/promises";

test("PHP browser fixture serves requests beside idle sockets and stops every worker", {
	timeout: 15000,
}, async (t) => {
	const directory = await mkdtemp(join(tmpdir(), "cms-php-server-"));
	await writeFile(join(directory, "index.php"), '<?php echo "fixture ready";');
	const listener = createServer();
	listener.listen(0, "127.0.0.1");
	await once(listener, "listening");
	const port = listener.address().port;
	await new Promise((resolve) => listener.close(resolve));
	const origin = `http://127.0.0.1:${port}`;
	const server = fork(
		new URL("../browser/local-php-server.mjs", import.meta.url),
		[`127.0.0.1:${port}`, "-t", directory],
		{ silent: true },
	);
	const closed = once(server, "close");
	let group;
	const sockets = [];
	let log = "";
	server.stderr.on("data", (chunk) => {
		log += chunk.toString();
	});
	server.stdout.resume();
	t.after(async () => {
		for (const socket of sockets) socket.destroy();
		server.kill("SIGTERM");
		if (group) {
			try {
				process.kill(-group, "SIGTERM");
			} catch (error) {
				if (error.code !== "ESRCH") throw error;
			}
		}
		try {
			await Promise.race([
				closed,
				delay(3000, null, { ref: false }).then(() => {
					throw new Error("PHP fixture shutdown timed out");
				}),
			]);
		} catch (error) {
			if (group) {
				try {
					process.kill(-group, "SIGKILL");
				} catch (failure) {
					if (failure.code !== "ESRCH") throw failure;
				}
			}
			server.kill("SIGKILL");
			await closed;
			throw error;
		} finally {
			await rm(directory, { recursive: true, force: true });
		}
	});
	[{ group }] = await once(server, "message", { signal: AbortSignal.timeout(3000) });
	const deadline = Date.now() + 5000;
	while (true) {
		try {
			const response = await fetch(origin, {
				headers: { Connection: "close" },
				signal: AbortSignal.timeout(500),
			});
			assert.equal(await response.text(), "fixture ready");
			break;
		} catch (error) {
			if (Date.now() >= deadline)
				throw new Error(`PHP fixture did not start: ${log}`, { cause: error });
			await delay(20);
		}
	}
	const accepted = log.match(/ Accepted/g)?.length ?? 0;
	for (let index = 0; index < 2; index++) {
		const socket = connect(port, "127.0.0.1");
		sockets.push(socket);
		await once(socket, "connect");
	}
	const acceptDeadline = Date.now() + 2000;
	while ((log.match(/ Accepted/g)?.length ?? 0) < accepted + 2) {
		assert.ok(
			Date.now() < acceptDeadline,
			`Workers did not accept idle connections: ${log}`,
		);
		await delay(20);
	}
	const response = await fetch(origin, {
		headers: { Connection: "close" },
		signal: AbortSignal.timeout(2000),
	});
	assert.equal(
		await response.text(),
		"fixture ready",
		"idle connections do not block native form requests",
	);
	server.kill("SIGTERM");
	const [code, signal] = await closed;
	assert.equal(code, 0);
	assert.equal(signal, null);
	await assert.rejects(
		new Promise((resolve, reject) => {
			const socket = connect(port, "127.0.0.1");
			socket.once("connect", () => {
				socket.destroy();
				resolve();
			});
			socket.once("error", reject);
		}),
		{ code: "ECONNREFUSED" },
		"shutdown releases the listener even with idle clients",
	);
});
