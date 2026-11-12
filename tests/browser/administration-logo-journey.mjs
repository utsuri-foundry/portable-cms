import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { connectLocalBrowser } from "./local-browser.mjs";

const origin = process.env.CMS_BROWSER_ORIGIN;
const engineVersion = execFileSync(
	"php",
	[
		"-r",
		"require 'vendor/autoload.php'; require 'engine/src/cms/conventional/bootstrap.php'; echo Utsuri\\PortableCms\\Conventional\\Content\\ProfileManifest::ENGINE_VERSION;",
	],
	{ encoding: "utf8" },
);
const siteVersion = JSON.parse(
	await readFile("profiles/reference-studio/profile.json", "utf8"),
).version;
const sourceRevision = execFileSync("git", ["rev-parse", "HEAD"], {
	encoding: "utf8",
}).trim();
let signInResponse = null;
const browser = await connectLocalBrowser(
	origin,
	process.env.CMS_BROWSER_DEBUG_URL,
	(method, params) => {
		if (
			method === "Network.responseReceived" &&
			params.type === "Document" &&
			params.response.url === `${origin}/login`
		) {
			signInResponse = {
				requestId: params.requestId,
				loaderId: params.loaderId,
				status: params.response.status,
				loaded: false,
			};
		}
		if (
			method === "Network.loadingFinished" &&
			params.requestId === signInResponse?.requestId
		)
			signInResponse.loaded = true;
	},
);
const { command, evaluate, ready, browserErrors } = browser;
const parsedPage = "document.readyState!=='loading'";
const styledPage =
	"[...document.styleSheets].some(s=>s.href?.includes('/assets/admin.css'))";
let websiteName;
const directory = await mkdtemp(join(tmpdir(), "logo-"));
const shapes = [
	{ name: "wordmark", width: 540, height: 240 },
	{ name: "wide", width: 480, height: 80 },
	{ name: "square", width: 512, height: 512 },
	{ name: "tall", width: 120, height: 360 },
].map((shape) => ({
	...shape,
	paths: ["light", "dark"].map((theme) =>
		join(directory, `website-owned-logo-${shape.name}-${theme}.png`),
	),
}));
execFileSync("php", [
	"-r",
	"$n=1; foreach ([[540,240],[480,80],[512,512],[120,360]] as [$w,$h]) { foreach (['#172029','#f1efe9'] as $color) { $i=new Imagick(); try { $i->newImage($w,$h,new ImagickPixel('transparent')); $d=new ImagickDraw(); $d->setFillColor(new ImagickPixel($color)); if ($w===540) { $d->setFont('profiles/reference-studio/assets/studio-sans-latin.woff2'); $d->setFontSize(90); $d->annotation(0,95,'Reference'); $d->annotation(0,215,'Studio'); } else { $d->rectangle(0,0,$w-1,$h-1); } $i->drawImage($d); $i->setImageFormat('png'); $i->writeImage($argv[$n++]); } finally { $i->clear(); } } }",
	...shapes.flatMap((shape) => shape.paths),
]);

async function navigate(path, selector) {
	await evaluate("document.documentElement.dataset.logoWaiting='1'");
	await command("Page.navigate", { url: origin + path });
	await ready(
		`!document.documentElement.dataset.logoWaiting && location.pathname===${JSON.stringify(path)} && document.querySelector(${JSON.stringify(selector)}) && ${parsedPage}`,
	);
}

async function submit(selector, submitter = null) {
	// A later settings save revokes the old URLs; finish their current document's loads first.
	await ready(
		"[...document.querySelectorAll('.admin-logo img')].every(image=>image.complete && image.naturalWidth>0)",
	);
	await evaluate(
		`document.documentElement.dataset.logoWaiting='1';const form=document.querySelector(${JSON.stringify(selector)});form.requestSubmit(${submitter ? `form.querySelector(${JSON.stringify(submitter)})` : ""})`,
	);
	try {
		// A parsed native response settles the form; layout checks wait for styles separately.
		await ready(`!document.documentElement.dataset.logoWaiting && ${parsedPage}`);
	} catch (error) {
		const state = await evaluate(
			"({path:location.pathname,ready:document.readyState,waiting:document.documentElement.dataset.logoWaiting,invalid:[...document.querySelectorAll(':invalid')].map(e=>e.name),alert:document.querySelector('[role=alert]')?.textContent})",
		);
		throw new Error(`Logo form did not settle: ${JSON.stringify(state)}`, {
			cause: error,
		});
	}
}
async function signIn() {
	await evaluate("document.documentElement.dataset.logoWaiting='1'");
	await command("Page.navigate", { url: `${origin}/login` });
	await ready(
		`!document.documentElement.dataset.logoWaiting && ${parsedPage} && (document.querySelector('[name=login]') || document.querySelector('.admin-sidebar'))`,
	);
	if (await evaluate("!!document.querySelector('[name=login]')")) {
		await evaluate(
			"document.querySelector('[name=login]').value='admin@portable.test';document.querySelector('[name=password]').value='portable-cms-local'",
		);
		await submit("form[action='/login']");
	}
}
async function theme(value) {
	await evaluate(
		`document.querySelector('form[action="/settings/theme"] [value="${value}"]').checked=true`,
	);
	await submit("form[action='/settings/theme']");
}
async function visibleLogo(scope) {
	await ready(styledPage);
	await ready(
		`${scope}.querySelectorAll('.admin-logo img').length>0 && [...${scope}.querySelectorAll('.admin-logo img')].every(i=>i.complete&&i.naturalWidth>0)`,
	);
	return evaluate(
		`[...${scope}.querySelectorAll('.admin-logo img')].filter(i=>getComputedStyle(i).display!=='none').map(i=>new URL(i.src).pathname)`,
	);
}
async function screenshot(mode) {
	const root = process.env.CMS_BROWSER_LOGO_SCREENSHOT_ROOT;
	if (root) {
		const shot = await command("Page.captureScreenshot", { format: "png" });
		await writeFile(`${root}-${mode}.png`, Buffer.from(shot.data, "base64"));
	}
}

async function uploadPair(shape) {
	await navigate("/settings/publication", "[name=adminLogoMediaId]");
	await ready(styledPage);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
		"Website settings uploads fit a narrow no-script viewport",
	);
	const originalName = await evaluate(
		"document.querySelector('[name=websiteName]').value",
	);
	const originalRevision = await evaluate(
		"document.querySelector('form[action=\"/settings/publication\"] [name=_revision]').value",
	);
	await evaluate(
		"document.querySelector('[name=websiteName]').value='Unsaved website upload name'",
	);
	for (const [index, field] of ["adminLogoMediaId", "darkAdminLogoMediaId"].entries()) {
		const { root } = await command("DOM.getDocument");
		const { nodeId } = await command("DOM.querySelector", {
			nodeId: root.nodeId,
			selector: `#website-files-${field}`,
		});
		await command("DOM.setFileInputFiles", { nodeId, files: [shape.paths[index]] });
		await submit(
			"form[action='/settings/publication']",
			`[name=_website_upload][value="${field}"]`,
		);
		assert.equal(
			await evaluate("!!document.querySelector('[data-website-upload-result]')"),
			true,
		);
		assert.equal(
			await evaluate("document.querySelector('[name=websiteName]').value"),
			"Unsaved website upload name",
		);
		assert.equal(
			await evaluate(
				"document.querySelector('form[action=\"/settings/publication\"] [name=_revision]').value",
			),
			originalRevision,
			"uploading never commits pending Website settings",
		);
	}
	execFileSync(
		"php",
		[
			"tools/conventional-media-worker.php",
			`--config=${process.env.CMS_BROWSER_WORKER_CONFIG}`,
			"--max-jobs=10",
			"--max-seconds=30",
			"--worker-id=logo-browser",
		],
		{ timeout: 40000 },
	);
	await evaluate(
		`document.querySelector('[name=_adminLogoMediaId_query]').value='website-owned-logo-${shape.name}';document.querySelector('[name=_darkAdminLogoMediaId_query]').value='website-owned-logo-${shape.name}'`,
	);
	await submit(
		"form[action='/settings/publication']",
		"[name=_adminLogoMediaId_action][value=search]",
	);
	const ids = await evaluate(
		`['light','dark'].map(mode=>[...document.querySelectorAll('[name=adminLogoMediaId]')].find(i=>i.closest('label')?.textContent.includes('website-owned-logo-${shape.name}-'+mode+'.png'))?.value)`,
	);
	assert.equal(ids.length, 2);
	assert.ok(ids.every(Boolean), "website images are ordinary picker choices");
	await evaluate(
		`document.querySelector('[name=websiteName]').value=${JSON.stringify(originalName)}`,
	);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1440,
		height: 900,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await navigate("/", ".admin-sidebar");
	assert.equal(
		await evaluate(
			`Promise.all(${JSON.stringify(ids)}.map(id=>fetch('/media/'+id).then(r=>r.text()).then(html=>html.includes('Website (images)')))).then(values=>values.every(Boolean))`,
		),
		true,
		"both images belong to Website without an owning entry",
	);
	return ids;
}

async function fit(scope, shape, description) {
	await ready(styledPage);
	await visibleLogo(`document.querySelector(${JSON.stringify(scope)})`);
	await evaluate("document.fonts.ready.then(()=>true)");
	const inspect = `(() => {
		const root=document.querySelector(${JSON.stringify(scope)}), mark=root.querySelector('.admin-logo');
		const image=[...mark.querySelectorAll('img')].find(i=>getComputedStyle(i).display!=='none');
		const box=e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,w:r.width,h:r.height}};
		const text=mark.nextElementSibling;
		const obstacles=[text,root.querySelector('.admin-navigation'), ...(root.matches('.admin-sidebar:popover-open')?[]:[document.querySelector('h1:not(.admin-visually-hidden)')])].filter(e=>e&&e.getClientRects().length);
		return {mark:box(mark),image:box(image),root:box(root),text:text?box(text):null,obstacles:obstacles.map(box),
			background:getComputedStyle(mark).backgroundColor,
			attributes:[...mark.querySelectorAll('img')].map(i=>({width:Number(i.getAttribute('width')),height:Number(i.getAttribute('height')),naturalWidth:i.naturalWidth,naturalHeight:i.naturalHeight}))};
	})()`;
	const loaded = await evaluate(inspect);
	const { mark, image } = loaded;
	assert.ok(
		image.w > 0 &&
			image.h > 0 &&
			image.x >= mark.x - 0.5 &&
			image.y >= mark.y - 0.5 &&
			image.x + image.w <= mark.x + mark.w + 0.5 &&
			image.y + image.h <= mark.y + mark.h + 0.5,
		`${description}: entire image fits its mark`,
	);
	const signIn = scope === ".admin-auth__brand";
	assert.ok(
		Math.abs(image.y + image.h / 2 - mark.y - mark.h / 2) < 0.6 &&
			(signIn
				? Math.abs(image.x + image.w / 2 - loaded.root.x - loaded.root.w / 2) < 0.6
				: Math.abs(image.x - mark.x) < 0.6),
		`${description}: vertically centred, ${signIn ? "centred above form" : "left-aligned with navigation"}`,
	);
	const frame = signIn ? [256, 96] : scope === ".admin-topbar" ? [128, 36] : [176, 48];
	assert.deepEqual([mark.w, mark.h], frame, `${description}: reserved frame`);
	assert.ok(
		image.w <= shape.width && image.h <= shape.height,
		`${description}: never enlarged`,
	);
	assert.ok(
		Math.abs(image.w / image.h - shape.width / shape.height) < 0.03,
		`${description}: intrinsic aspect ratio`,
	);
	for (const facts of loaded.attributes) {
		assert.deepEqual(
			[facts.width, facts.height],
			[facts.naturalWidth, facts.naturalHeight],
			`${description}: honest intrinsic attributes`,
		);
	}
	for (const obstacle of loaded.obstacles) {
		assert.ok(
			image.x + image.w <= obstacle.x + 0.5 ||
				obstacle.x + obstacle.w <= image.x + 0.5 ||
				image.y + image.h <= obstacle.y + 0.5 ||
				obstacle.y + obstacle.h <= image.y + 0.5,
			`${description}: no overlap with text, heading or navigation`,
		);
	}
	const sources = await evaluate(
		`(() => {const images=[...document.querySelector(${JSON.stringify(scope)}).querySelectorAll('.admin-logo img')];return images.map(i=>{const src=i.getAttribute('src');i.removeAttribute('src');return src})})()`,
	);
	const unloaded = await evaluate(inspect);
	await evaluate(
		`[...document.querySelector(${JSON.stringify(scope)}).querySelectorAll('.admin-logo img')].forEach((i,n)=>i.setAttribute('src',${JSON.stringify(sources)}[n]))`,
	);
	await visibleLogo(`document.querySelector(${JSON.stringify(scope)})`);
	const reloaded = await evaluate(inspect);
	assert.deepEqual(
		[unloaded.mark, unloaded.text, unloaded.obstacles],
		[loaded.mark, loaded.text, loaded.obstacles],
		`${description}: mark and name reserve space without image bytes`,
	);
	assert.deepEqual(
		[reloaded.mark, reloaded.text, reloaded.obstacles],
		[loaded.mark, loaded.text, loaded.obstacles],
		`${description}: loading never moves the surrounding layout`,
	);
}

async function signInDetails(description, accessibilityNodes = null) {
	await ready(styledPage);
	// Inspect the received no-store response without another request to the PHP fixture.
	const {
		frameTree: { frame },
	} = await command("Page.getFrameTree");
	assert.equal(new URL(frame.url).pathname, "/login");
	const deadline = Date.now() + 30000;
	while (!signInResponse?.loaded && Date.now() < deadline)
		await new Promise((resolve) => setTimeout(resolve, 10));
	assert.equal(
		signInResponse?.loaded,
		true,
		`${description}: sign-in response finished`,
	);
	assert.equal(
		signInResponse.loaderId,
		frame.loaderId,
		`${description}: inspect this document's response`,
	);
	assert.equal(signInResponse.status, 200);
	const { body: markup, base64Encoded } = await command("Network.getResponseBody", {
		requestId: signInResponse.requestId,
	});
	assert.equal(base64Encoded, false);
	assert.equal(
		markup.includes(engineVersion),
		false,
		`${description}: no anonymous engine version`,
	);
	assert.equal(
		markup.includes(sourceRevision),
		false,
		`${description}: no anonymous source revision`,
	);
	const facts = await evaluate(`(() => {
		const headings=[...document.querySelectorAll('h1')], heading=headings[0], rect=heading.getBoundingClientRect();
		const footer=document.querySelector('.admin-auth__footer'), range=document.createRange();range.selectNodeContents(footer);
		return {headings:headings.length,title:heading.textContent,clip:getComputedStyle(heading).clipPath,
			width:rect.width,height:rect.height,submit:document.querySelector('button[type=submit]').textContent,
			footer:footer.textContent,lines:range.getClientRects().length,
			overflow:document.documentElement.scrollWidth>innerWidth};
	})()`);
	assert.equal(facts.headings, 1, `${description}: one level-one heading`);
	assert.equal(facts.title, "Sign in");
	assert.equal(facts.clip, "inset(50%)");
	assert.ok(
		facts.width <= 1 && facts.height <= 1,
		`${description}: heading is visually hidden`,
	);
	assert.equal(facts.submit, "Sign in");
	assert.equal(facts.footer, `Portable CMS · Site ${siteVersion}`);
	assert.equal(facts.lines, 1, `${description}: small print fits one line`);
	assert.equal(facts.overflow, false);
	const nodes =
		accessibilityNodes ?? (await command("Accessibility.getFullAXTree")).nodes;
	const headings = nodes.filter(
		(node) =>
			!node.ignored &&
			node.role?.value === "heading" &&
			node.properties?.some(
				(property) => property.name === "level" && property.value.value === 1,
			),
	);
	assert.equal(
		headings.length,
		1,
		`${description}: assistive technology retains one level-one heading`,
	);
	assert.equal(headings[0].name.value, "Sign in");
}

async function accountVersions(description) {
	await ready(styledPage);
	const closed = await evaluate(`(() => {
		const account=document.querySelector('.admin-account'), panel=account.querySelector('.admin-account__panel'), line=account.querySelector('.admin-account__versions'), sidebar=document.querySelector('.admin-sidebar');
		const dimensions=()=>[sidebar.getBoundingClientRect().height,sidebar.scrollHeight];
		const before=dimensions(), hidden=!line.checkVisibility(), last=panel.lastElementChild===line;
		line.remove();const without=dimensions();panel.append(line);
		return {open:account.open,hidden,last,before,without};
	})()`);
	assert.equal(closed.open, false);
	assert.equal(
		closed.hidden,
		true,
		`${description}: versions are hidden in the closed account menu`,
	);
	assert.equal(closed.last, true, `${description}: versions finish the account menu`);
	assert.deepEqual(
		closed.before,
		closed.without,
		`${description}: closed versions add no sidebar height or scrolling`,
	);
	const closedTree = (await command("Accessibility.getFullAXTree")).nodes;
	assert.equal(
		closedTree.some(
			(node) =>
				!node.ignored && node.name?.value?.includes(`Portable CMS ${engineVersion}`),
		),
		false,
		`${description}: closed account versions stay out of the accessibility tree`,
	);
	await evaluate("document.querySelector('.admin-account summary').click()");
	await ready("document.querySelector('.admin-account').open");
	const open = await evaluate(`(() => {
		const line=document.querySelector('.admin-account__versions'), summary=document.querySelector('.admin-account summary');
		return {text:line.textContent,visible:line.checkVisibility(),font:parseFloat(getComputedStyle(line).fontSize),
			summaryFont:parseFloat(getComputedStyle(summary).fontSize),subdued:getComputedStyle(line).color!==getComputedStyle(summary).color,
			overflow:document.documentElement.scrollWidth>innerWidth};
	})()`);
	assert.equal(open.text, `Portable CMS ${engineVersion} · Site ${siteVersion}`);
	assert.equal(open.visible, true);
	assert.ok(
		open.font < open.summaryFont && open.subdued,
		`${description}: account versions stay small and subdued`,
	);
	assert.equal(open.overflow, false);
	await evaluate("document.querySelector('.admin-account summary').click()");
}

async function logoIdentity(scope, paired, description) {
	const signIn = scope === ".admin-auth__brand";
	const facts = await evaluate(`(() => {
		const root=document.querySelector(${JSON.stringify(scope)}), mark=root.querySelector('.admin-logo');
		return {text:root.querySelector('.admin-brand')?.textContent??root.textContent,
			alternatives:[...mark.querySelectorAll('img')].map(i=>i.alt),
			background:getComputedStyle(mark).backgroundColor,
			overflow:document.documentElement.scrollWidth>innerWidth};
	})()`);
	assert.equal(facts.text, "", `${description}: logo is the only visible brand`);
	assert.ok(facts.alternatives.every((name) => name === websiteName));
	assert.equal(facts.background, paired ? "rgba(0, 0, 0, 0)" : "rgb(255, 255, 255)");
	assert.equal(facts.overflow, false, `${description}: no sideways scrolling`);
	const { nodes } = await command("Accessibility.getFullAXTree");
	const named = nodes.filter(
		(node) => !node.ignored && node.name?.value === websiteName,
	);
	assert.equal(
		named.filter((node) => node.role?.value === "image").length,
		1,
		`${description}: one visible image names the website`,
	);
	if (signIn) {
		await signInDetails(description, nodes);
	} else {
		assert.equal(
			named.filter((node) => node.role?.value === "link").length,
			1,
			`${description}: brand link has the website's accessible name`,
		);
		assert.equal(
			await evaluate(
				"document.querySelectorAll('.admin-brand__name,.admin-brand__meta').length",
			),
			0,
			`${description}: name and version leave both shell brands`,
		);
	}
}

async function shapeMatrix(shape, ids) {
	for (const paired of [false, true]) {
		await navigate("/settings/publication", "[name=adminLogoMediaId]");
		await evaluate(
			`document.querySelector('[name=adminLogoMediaId][value="${ids[0]}"]').checked=true;document.querySelector('[name=darkAdminLogoMediaId][value="${paired ? ids[1] : ""}"]').checked=true`,
		);
		await submit("form[action='/settings/publication']");
		await navigate("/", ".admin-sidebar");
		for (const appearance of ["light", "dark"]) {
			await theme(appearance);
			for (const width of [1440, 360]) {
				const description = `${shape.name}, ${paired ? "paired" : "single"}, ${appearance}, ${width}px`;
				await command("Emulation.setDeviceMetricsOverride", {
					width,
					height: 900,
					deviceScaleFactor: 1,
					mobile: false,
				});
				if (width === 1440) {
					await fit(".admin-sidebar", shape, `${description} sidebar`);
					await logoIdentity(".admin-sidebar", paired, description);
					await screenshot(
						`${shape.name}-${paired ? "paired" : "single"}-${appearance}-${width}-shell`,
					);
				} else {
					await fit(".admin-topbar", shape, `${description} top bar`);
					await logoIdentity(".admin-topbar", paired, description);
					await screenshot(
						`${shape.name}-${paired ? "paired" : "single"}-${appearance}-${width}-shell`,
					);
					await evaluate(
						"document.querySelector('[popovertarget=admin-menu]').click()",
					);
					await ready(
						"document.querySelector('.admin-sidebar').matches(':popover-open')",
					);
					// The phone menu deliberately hides its duplicate brand; the top bar owns the visible mark.
					assert.equal(
						await evaluate(
							"document.querySelector('.admin-sidebar .admin-brand').getClientRects().length",
						),
						0,
					);
				}
				await accountVersions(description);
				await submit("form[action='/logout']");
				await fit(".admin-auth__brand", shape, `${description} sign-in`);
				await logoIdentity(".admin-auth__brand", paired, description);
				await screenshot(
					`${shape.name}-${paired ? "paired" : "single"}-${appearance}-${width}-login`,
				);
				assert.equal(
					await evaluate("document.documentElement.scrollWidth<=innerWidth"),
					true,
					`${description}: viewport fits`,
				);
				await signIn();
			}
		}
	}
	console.log(
		`PASS administration logo ${shape.name}: single/paired, both themes, 1440/360px, accessible identity, containment, ratio, alignment and stable loading.`,
	);
}

async function fallbackMatrix(iconId) {
	for (const icon of ["", iconId]) {
		await navigate("/settings/publication", "[name=adminLogoMediaId]");
		await evaluate(
			`for(const [name,value] of Object.entries({adminLogoMediaId:'',darkAdminLogoMediaId:'',iconMediaId:${JSON.stringify(icon)}}))document.querySelector('[name='+name+'][value="'+value+'"]').checked=true`,
		);
		await submit("form[action='/settings/publication']");
		assert.equal(
			await evaluate("!!document.querySelector('[role=alert]')"),
			false,
			"fallback settings save successfully",
		);
		await navigate("/", ".admin-sidebar");
		for (const appearance of ["light", "dark"]) {
			await theme(appearance);
			await ready(styledPage);
			for (const width of [1440, 360]) {
				await command("Emulation.setDeviceMetricsOverride", {
					width,
					height: 900,
					deviceScaleFactor: 1,
					mobile: false,
				});
				const scope = width === 1440 ? ".admin-sidebar" : ".admin-topbar";
				const facts = await evaluate(
					`(() => {const brand=document.querySelector('${scope} .admin-brand');return {name:brand.querySelector('.admin-brand__name').textContent,version:brand.querySelector('.admin-brand__meta').textContent,icon:!!brand.querySelector('.admin-logo--icon'),monogram:!!brand.querySelector('.admin-mark'),overflow:document.documentElement.scrollWidth>innerWidth}})()`,
				);
				assert.equal(facts.name, websiteName);
				assert.match(facts.version, /^Site \d+\.\d+\.\d+$/);
				assert.equal(facts.icon, Boolean(icon));
				assert.equal(facts.monogram, !icon);
				assert.equal(facts.overflow, false);
				if (width === 360) {
					await evaluate(
						"document.querySelector('[popovertarget=admin-menu]').click()",
					);
					await ready(
						"document.querySelector('.admin-sidebar').matches(':popover-open')",
					);
				}
				await accountVersions(
					`fallback ${icon ? "icon" : "monogram"}, ${appearance}, ${width}px`,
				);
				await submit("form[action='/logout']");
				await signInDetails(
					`fallback ${icon ? "icon" : "monogram"}, ${appearance}, ${width}px`,
				);
				assert.equal(
					await evaluate(
						"document.querySelector('.admin-auth__brand strong').textContent",
					),
					websiteName,
				);
				assert.equal(
					await evaluate(
						"document.querySelector('.admin-auth__brand small').textContent",
					),
					"Private editing area",
				);
				await ready(styledPage);
				assert.equal(
					await evaluate("document.documentElement.scrollWidth<=innerWidth"),
					true,
				);
				await signIn();
			}
		}
	}
}

try {
	await command("Page.enable");
	await command("Network.enable", {
		maxTotalBufferSize: 4194304,
		maxResourceBufferSize: 1048576,
	});
	await command("Runtime.enable");
	await command("Log.enable");
	await command("Emulation.setScriptExecutionDisabled", { value: true });
	await command("Emulation.setDeviceMetricsOverride", {
		width: 1440,
		height: 900,
		deviceScaleFactor: 1,
		mobile: false,
	});
	await signIn();
	await navigate("/settings/publication", "[name=adminLogoMediaId]");
	const original = await evaluate(
		"Object.fromEntries(['adminLogoMediaId','darkAdminLogoMediaId','iconMediaId'].map(n=>[n,document.querySelector('[name='+n+']:checked')?.value??'']))",
	);
	websiteName = await evaluate("document.querySelector('[name=websiteName]').value");
	const oldTheme = await evaluate(
		"document.querySelector('[name=theme]:checked').value",
	);
	const ids = await uploadPair(shapes[0]);
	await navigate("/settings/publication", "[name=adminLogoMediaId]");
	await evaluate(
		`document.querySelector('[name=adminLogoMediaId][value="${ids[0]}"]').checked=true;document.querySelector('[name=darkAdminLogoMediaId][value="${ids[1]}"]').checked=true`,
	);
	await submit("form[action='/settings/publication']");
	assert.equal(await evaluate("!!document.querySelector('[role=alert]')"), false);
	await navigate("/", ".admin-sidebar");
	const expected = await evaluate(
		"[...document.querySelectorAll('.admin-sidebar .admin-logo img')].map(i=>new URL(i.src).pathname)",
	);
	assert.equal(expected.length, 2);
	await command("Emulation.setEmulatedMedia", {
		features: [{ name: "prefers-color-scheme", value: "dark" }],
	});
	await theme("system");
	assert.deepEqual(
		await visibleLogo("document.querySelector('.admin-sidebar')"),
		[expected[1]],
		"System follows the dark operating-system preference",
	);
	await command("Emulation.setEmulatedMedia", {
		features: [{ name: "prefers-color-scheme", value: "light" }],
	});
	assert.deepEqual(
		await visibleLogo("document.querySelector('.admin-sidebar')"),
		[expected[0]],
		"System also follows a changed light preference",
	);
	await command("Emulation.setEmulatedMedia", {
		features: [{ name: "prefers-color-scheme", value: "dark" }],
	});
	await theme("light");
	assert.deepEqual(
		await visibleLogo("document.querySelector('.admin-sidebar')"),
		[expected[0]],
		"explicit light overrides a dark system theme",
	);
	await screenshot("light");
	await theme("dark");
	assert.deepEqual(
		await visibleLogo("document.querySelector('.admin-sidebar')"),
		[expected[1]],
		"the dark theme uses its real variant",
	);
	await screenshot("dark");
	await evaluate("document.querySelector('.admin-sidebar .admin-brand').focus()");
	for (const modifiers of [0, 8]) {
		await command("Input.dispatchKeyEvent", {
			type: "keyDown",
			key: "Tab",
			code: "Tab",
			windowsVirtualKeyCode: 9,
			modifiers,
		});
		await command("Input.dispatchKeyEvent", {
			type: "keyUp",
			key: "Tab",
			code: "Tab",
			windowsVirtualKeyCode: 9,
			modifiers,
		});
	}
	assert.equal(
		await evaluate(
			"document.activeElement.matches('.admin-brand:focus-visible') && getComputedStyle(document.activeElement).outlineStyle!=='none'",
		),
		true,
		"brand keeps visible keyboard focus",
	);
	await command("Emulation.setDeviceMetricsOverride", {
		width: 360,
		height: 800,
		deviceScaleFactor: 1,
		mobile: false,
	});
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
	);
	assert.deepEqual(
		await visibleLogo("document.querySelector('.admin-topbar')"),
		[expected[1]],
		"the phone top bar uses the same bounded branding",
	);
	await evaluate("document.querySelector('[popovertarget=admin-menu]').click()");
	await ready("document.querySelector('.admin-sidebar').matches(':popover-open')");
	assert.equal(
		await evaluate(
			"document.querySelector('.admin-sidebar .admin-brand').getBoundingClientRect().width<=innerWidth",
		),
		true,
		"the native phone menu keeps its brand within the viewport",
	);
	await submit("form[action='/logout']");
	assert.deepEqual(
		await visibleLogo("document.querySelector('.admin-auth__brand')"),
		[expected[1]],
		"sign-in retains the dark theme and selected image without scripts",
	);
	assert.equal(
		await evaluate("document.documentElement.scrollWidth<=innerWidth"),
		true,
	);
	for (const path of expected) {
		const response = await fetch(origin + path);
		assert.equal(response.status, 200);
		assert.equal(response.headers.get("content-type"), "image/webp");
		assert.equal(response.headers.get("set-cookie"), null);
	}
	assert.equal(
		(await fetch(`${origin}/administration-logo/${"f".repeat(64)}.webp`)).status,
		404,
	);
	await signIn();
	await theme("light");
	await submit("form[action='/logout']");
	assert.deepEqual(
		await visibleLogo("document.querySelector('.admin-auth__brand')"),
		[expected[0]],
		"light sign-in has the standard logo too",
	);
	await signIn();
	let squareId;
	for (const shape of shapes.slice(1)) {
		const shapeIds = await uploadPair(shape);
		if (shape.name === "square") squareId = shapeIds[0];
		await shapeMatrix(shape, shapeIds);
	}
	await shapeMatrix(shapes[0], ids);
	await fallbackMatrix(squareId);
	await navigate("/settings/publication", "[name=adminLogoMediaId]");
	await evaluate(
		`for(const [n,v] of Object.entries(${JSON.stringify(original)}))document.querySelector('[name='+n+'][value="'+v+'"]').checked=true`,
	);
	await submit("form[action='/settings/publication']");
	assert.equal(
		(await fetch(origin + expected[0])).status,
		404,
		"clearing the selection revokes its anonymous URL",
	);
	await theme(oldTheme);
	await submit("form[action='/logout']");
	assert.deepEqual(browserErrors, []);
	console.log(
		"PASS administration logos: two-line wordmark and square/tall/wide single and paired shapes, accessible names, native uploads, prepared-only delivery, revocation, themes, focus, stable loading and no-script sign-in/sidebar/top bar with named icon/monogram fallbacks at 1440/360px.",
	);
} finally {
	await browser.close();
	await rm(directory, { recursive: true, force: true });
}
