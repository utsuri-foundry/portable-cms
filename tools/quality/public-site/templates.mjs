/**
 * Groups the artifact's routes into templates from its own route identities: each
 * `page:` translation group is one template, and record pages share a template with
 * the other records beneath the same index page. One route per template stands for the
 * template in Lighthouse, in the language published at the root when the root is a page of
 * its own, otherwise in the first locale; every route is still validated.
 */
export function publicTemplates(manifest) {
	const identities = Array.isArray(manifest.routeIdentities)
		? manifest.routeIdentities
		: [];
	const byPath = new Map(identities.map((identity) => [identity.path, identity]));
	const locales = [...new Set(identities.map((identity) => identity.locale))].sort();
	const primary = byPath.get("/")?.locale ?? locales[0];
	const templates = new Map();
	for (const identity of [...identities].sort((a, b) => a.path.localeCompare(b.path))) {
		let key = identity.translationGroup;
		if (!key.startsWith("page:")) {
			const parent = identity.path.replace(/[^/]+\/$/, "");
			key = `record:${byPath.get(parent)?.translationGroup ?? parent}`;
		}
		const entry = templates.get(key) ?? { id: key, paths: [] };
		entry.paths.push(identity.path);
		templates.set(key, entry);
	}
	return [...templates.values()].map((template) => ({
		id: template.id,
		paths: template.paths,
		representative:
			template.paths.find((path) => byPath.get(path).locale === primary) ??
			template.paths[0],
	}));
}

/** Every HTML document in the artifact, as public paths. */
export function publicDocuments(manifest) {
	return manifest.files
		.filter((file) => file.path.endsWith(".html"))
		.map((file) => `/${file.path.replace(/index\.html$/, "")}`)
		.sort();
}
