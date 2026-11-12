import assert from "node:assert/strict";
import { connectLocalBrowser } from "./local-browser.mjs";

// Only the harness's fictional, ephemeral installation is mutated.
const browser = await connectLocalBrowser(
	process.env.CMS_BROWSER_ORIGIN,
	process.env.CMS_BROWSER_DEBUG_URL,
);
const { command, evaluate, navigate, ready, browserErrors } = browser;
const temporary = "fictional-temporary-password";
const permanent = "fictional-permanent-password";

async function submit(action, values) {
	await evaluate(`(() => {
		const form=document.querySelector('form[action="${action}"]');
		for(const [name,value] of Object.entries(${JSON.stringify(values)})) {
			const field=form.elements.namedItem(name);
			if(!field) throw new Error('Missing native field '+name);
			field.value=value;
		}
		form.requestSubmit();
	})()`);
}

async function signOut() {
	await evaluate("document.querySelector('form[action=\"/logout\"]').requestSubmit()");
	await ready(
		"location.pathname==='/login' && document.querySelector('[name=password]')",
	);
}

try {
	await command("Page.enable");
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await command("Page.navigate", { url: `${process.env.CMS_BROWSER_ORIGIN}/account` });
	await ready(
		"document.readyState==='complete' && document.querySelector('main form')",
	);
	if ((await evaluate("location.pathname")) === "/login") {
		await submit("/login", {
			login: "admin@portable.test",
			password: "portable-cms-local",
		});
		await ready(
			"location.pathname==='/' && document.querySelector('.admin-navigation')",
		);
	}
	if (process.env.CMS_TEST_ACCOUNTS_ONLY === "1") {
		// Native setup for the profile's required singletons, never a privileged editor write.
		for (const type of ["home", "about", "site-settings"]) {
			await navigate(`/records/${type}/new`, "[data-editorial-form]");
			for (const locale of ["ca", "es", "en"]) {
				if (type === "about") {
					const name = `body__${locale}`;
					await evaluate(`(() => {
						const root=document.querySelector('[data-rich-name="${name}"]');
						root.closest('form').requestSubmit(root.querySelector('[data-rich-action=add]'));
					})()`);
					await ready(`document.querySelector('[name="${name}__0__0__text"]')`);
					await evaluate(
						`document.querySelector('[name="${name}__0__0__text"]').value='A fictional studio'`,
					);
				} else if (type === "home") {
					await evaluate(
						`document.querySelector('[name="introduction__${locale}"]').value='A fictional studio'`,
					);
				}
			}
			if (type === "site-settings")
				await evaluate(
					"document.querySelector('[name=organizationName]').value='Fictional account study'",
				);
			await evaluate("document.querySelector('[data-editorial-form]').requestSubmit()");
			await ready(
				`location.pathname.match(/^\\/records\\/${type}\\/[a-f0-9]{32}$/) && document.querySelector('[name=_revision]')?.value==='1'`,
			);
		}
	}
	await navigate("/accounts", "[name=temporary_password]");
	assert.deepEqual(
		await evaluate(
			'Array.from(document.querySelector("select[name=role]").options, option=>option.value)',
		),
		["editor", "administrator"],
	);
	await submit("/accounts", {
		action: "create",
		login: "editor@portable.test",
		name: "Fictional editor",
		role: "editor",
		temporary_password: temporary,
	});
	await ready(
		"location.pathname==='/accounts' && document.querySelector('main').textContent.includes('editor@portable.test')",
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
	);
	await signOut();
	await submit("/login", { login: "editor@portable.test", password: temporary });
	await ready(
		"location.pathname==='/account' && document.querySelector('[name=new_password]')",
	);
	assert.match(
		await evaluate("document.querySelector('main').textContent"),
		/Replace your temporary password/,
	);
	await command("Page.navigate", {
		url: `${process.env.CMS_BROWSER_ORIGIN}/records/article`,
	});
	await ready(
		"location.pathname==='/account' && document.querySelector('[name=new_password]')",
	);
	await submit("/account", { current_password: temporary, new_password: permanent });
	await ready(
		"location.pathname==='/account' && !document.querySelector('[name=new_password]').required",
	);
	await navigate("/", ".admin-navigation");
	assert.equal(
		await evaluate(
			'document.querySelector(\'a[href="/accounts"],a[href="/exports"],a[href="/records/site-settings"],a[href="/settings/design"]\')!==null',
		),
		false,
	);
	await navigate("/records/article/new", "[data-editorial-form]");
	const fields = {};
	for (const locale of ["ca", "es", "en"]) {
		fields[`title__${locale}`] = "Article by a fictional editor";
		fields[`slug__${locale}`] = "fictional-editor-article";
		fields[`excerpt__${locale}`] = "A fictional account test";
		const name = `body__${locale}`;
		await evaluate(`(() => {
			const root=document.querySelector('[data-rich-name="${name}"]');
			root.closest('form').requestSubmit(root.querySelector('[data-rich-action=add]'));
		})()`);
		await ready(`document.querySelector('[name="${name}__0__0__text"]')`);
		await evaluate(
			`document.querySelector('[name="${name}__0__0__text"]').value='A native editorial paragraph'`,
		);
	}
	await evaluate(`(() => {
		const form=document.querySelector('[data-editorial-form]');
		for (const [name,value] of Object.entries(${JSON.stringify(fields)})) form.elements.namedItem(name).value=value;
		form.requestSubmit();
	})()`);
	await ready(
		"location.pathname.match(/^\\/records\\/article\\/[a-f0-9]{32}$/) && document.querySelector('[name=_revision]')?.value==='1'",
	);
	assert.equal(
		await evaluate("document.querySelector('#record-status-form')===null"),
		true,
	);
	await navigate("/previews", 'form[action="/previews"]');
	await evaluate(`(() => {
		const form=document.querySelector('form[action="/previews"]');
		form.elements.namedItem('include_drafts').checked=true;
		form.requestSubmit();
	})()`);
	try {
		await ready(
			"location.pathname.match(/^\\/previews\\/[a-f0-9]{32}$/) && document.querySelector('[data-preview-state]')",
		);
	} catch (error) {
		throw new Error(await evaluate("document.querySelector('main').textContent"), {
			cause: error,
		});
	}
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
	);
	await navigate("/account", "[name=new_password]");
	await submit("/account", {
		name: "Renamed editor",
		login: "renamed-editor@portable.test",
		current_password: permanent,
		new_password: "another-fictional-password",
	});
	await ready(
		"location.pathname==='/account' && document.querySelector('[name=login]').value==='renamed-editor@portable.test'",
	);
	assert.equal(
		await evaluate("document.querySelector('[name=new_password]').value"),
		"",
	);
	await signOut();
	await submit("/login", {
		login: "renamed-editor@portable.test",
		password: "another-fictional-password",
	});
	await ready("location.pathname==='/' && document.querySelector('.admin-navigation')");
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS no-script account creation, forced password replacement, editor authoring and previews, restricted navigation, self-service credentials and 360px layout.",
	);
} finally {
	await browser.close();
}
