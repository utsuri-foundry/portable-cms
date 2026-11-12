import { spawn } from "node:child_process";
import { cp, mkdtemp, readFile, realpath, rm, writeFile } from "node:fs/promises";
import { createServer } from "node:net";
import { tmpdir } from "node:os";
import { delimiter, dirname, join } from "node:path";

/** A free loopback TCP port. */
export function freePort() {
	return new Promise((resolve, reject) => {
		const server = createServer();
		server.on("error", reject);
		server.listen(0, "127.0.0.1", () => {
			const { port } = server.address();
			server.close(() => resolve(port));
		});
	});
}

async function apacheBinary() {
	for (const directory of (process.env.PATH ?? "").split(delimiter)) {
		try {
			return await realpath(join(directory, "httpd"));
		} catch {
			// Not in this PATH entry.
		}
	}
	throw new Error("Pinned Apache httpd is required for the public site audit");
}

/**
 * Serves a copy of a static artifact through the pinned Apache with the artifact's own
 * approved delivery rules, as a conventional host would. The input is never modified.
 */
export async function startStaticHost(artifact) {
	const manifest = JSON.parse(await readFile(join(artifact, "artifact.json"), "utf8"));
	const delivery = manifest.delivery;
	if (delivery?.target !== "apache24" || typeof delivery.path !== "string") {
		throw new Error("The artifact has no Apache delivery rules");
	}
	const binary = await apacheBinary();
	const prefix = dirname(dirname(binary));
	const root = await mkdtemp(join(tmpdir(), "portable-cms-public-site-"));
	const documentRoot = join(root, "htdocs");
	let child;
	try {
		await cp(artifact, documentRoot, { recursive: true });
		await cp(join(artifact, delivery.path), join(documentRoot, ".htaccess"));
		const port = await freePort();
		const modules = [
			"mpm_event",
			"unixd",
			"authz_core",
			"alias",
			"headers",
			"dir",
			"mime",
		];
		const configuration = [
			`ServerRoot "${root}"`,
			`Listen 127.0.0.1:${port}`,
			"ServerName 127.0.0.1",
			`PidFile "${root}/httpd.pid"`,
			`ErrorLog "${root}/error.log"`,
			"LogLevel warn",
			...modules.map(
				(module) => `LoadModule ${module}_module "${prefix}/modules/mod_${module}.so"`,
			),
			`User #${process.getuid()}`,
			`Group #${process.getgid()}`,
			`TypesConfig "${prefix}/conf/mime.types"`,
			"AddType text/javascript .js .mjs",
			"AddType font/woff2 .woff2",
			"AddType image/webp .webp",
			"AddType image/avif .avif",
			`DocumentRoot "${documentRoot}"`,
			`<Directory "${documentRoot}">`,
			"Require all granted",
			"Options -Indexes -MultiViews",
			"AllowOverride FileInfo Indexes",
			"</Directory>",
			"",
		].join("\n");
		await writeFile(join(root, "httpd.conf"), configuration);
		child = spawn(binary, ["-X", "-f", join(root, "httpd.conf")], { stdio: "ignore" });
		const origin = `http://127.0.0.1:${port}`;
		for (let attempt = 0; ; attempt++) {
			try {
				await fetch(`${origin}/`);
				break;
			} catch {
				if (attempt >= 100 || child.exitCode !== null) {
					const log = await readFile(join(root, "error.log"), "utf8").catch(() => "");
					throw new Error(`Apache did not start: ${log.slice(0, 500)}`);
				}
				await new Promise((resolve) => setTimeout(resolve, 50));
			}
		}
		const host = child;
		return {
			origin,
			manifest,
			async close() {
				const exited = new Promise((resolve) => host.once("exit", resolve));
				host.kill();
				await Promise.race([
					exited,
					new Promise((resolve) => setTimeout(resolve, 5000)),
				]);
				await rm(root, {
					recursive: true,
					force: true,
					maxRetries: 5,
					retryDelay: 100,
				});
			},
		};
	} catch (error) {
		child?.kill();
		await rm(root, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
		throw error;
	}
}
