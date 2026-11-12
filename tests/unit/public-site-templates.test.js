import assert from "node:assert/strict";
import test from "node:test";
import {
	publicDocuments,
	publicTemplates,
} from "../../tools/quality/public-site/templates.mjs";

const manifest = {
	files: [
		{ path: "index.html" },
		{ path: "en/index.html" },
		{ path: "en/work/index.html" },
		{ path: "en/work/b/index.html" },
		{ path: "fr/work/a/index.html" },
		{ path: "assets/site.css" },
	],
	routeIdentities: [
		{ path: "/fr/work/a/", locale: "fr", translationGroup: "record:1" },
		{ path: "/en/work/b/", locale: "en", translationGroup: "record:2" },
		{ path: "/fr/work/", locale: "fr", translationGroup: "page:work" },
		{ path: "/en/work/", locale: "en", translationGroup: "page:work" },
		{ path: "/en/", locale: "en", translationGroup: "page:home" },
	],
};

test("records share a template with their siblings under the same index page", () => {
	assert.deepEqual(publicTemplates(manifest), [
		{ id: "page:home", paths: ["/en/"], representative: "/en/" },
		{ id: "page:work", paths: ["/en/work/", "/fr/work/"], representative: "/en/work/" },
		{
			id: "record:page:work",
			paths: ["/en/work/b/", "/fr/work/a/"],
			representative: "/en/work/b/",
		},
	]);
});

test("every HTML file is a public document, other files are not", () => {
	assert.deepEqual(publicDocuments(manifest), [
		"/",
		"/en/",
		"/en/work/",
		"/en/work/b/",
		"/fr/work/a/",
	]);
});

test("the language at the root stands for each template when the root is its own page", () => {
	const root = {
		files: [],
		routeIdentities: [
			{ path: "/", locale: "fr", translationGroup: "page:home" },
			{ path: "/en/", locale: "en", translationGroup: "page:home" },
			{ path: "/work/", locale: "fr", translationGroup: "page:work" },
			{ path: "/en/work/", locale: "en", translationGroup: "page:work" },
			{ path: "/work/a/", locale: "fr", translationGroup: "record:1" },
			{ path: "/en/work/a/", locale: "en", translationGroup: "record:1" },
		],
	};
	assert.deepEqual(
		publicTemplates(root).map((template) => [template.id, template.representative]),
		[
			["page:home", "/"],
			["page:work", "/work/"],
			["record:page:work", "/work/a/"],
		],
	);
});
