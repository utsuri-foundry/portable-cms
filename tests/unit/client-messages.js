import { readFileSync } from "node:fs";

/** The shipped English island bundle, read from the engine catalog so tests assert real text. */
export function englishClientMessages() {
	const source = readFileSync(
		new URL(
			"../../engine/src/cms/conventional/i18n/messages/en/client.php",
			import.meta.url,
		),
		"utf8",
	);
	const bundle = {};
	for (const [, key, value] of source.matchAll(
		/^\s*'client\.([a-z0-9_.]+)' => '((?:[^'\\]|\\.)*)',$/gm,
	)) {
		bundle[key] = value.replace(/\\(['\\])/g, "$1");
	}
	return bundle;
}

/** A stand-in for the server-rendered `data-messages` ancestor. */
export function messagesHost() {
	return { dataset: { messages: JSON.stringify(englishClientMessages()) } };
}
