import assert from "node:assert/strict";
import test from "node:test";
import { mountCompositionEditor } from "../../engine/src/cms/conventional/client/composition-editor.js";

class Element extends EventTarget {
	constructor() {
		super();
		this.attributes = new Map();
		this.style = {};
		this.children = [];
	}
	setAttribute(name, value) {
		this.attributes.set(name, value);
	}
	getAttribute(name) {
		return this.attributes.get(name) ?? null;
	}
	removeAttribute(name) {
		this.attributes.delete(name);
	}
	append(child) {
		this.children.push(child);
	}
}

function fixture() {
	const timers = new Map();
	const navigations = [];
	let nextTimer = 0;
	let observers = 0;
	let transports = 0;
	const window = new Element();
	window.innerWidth = 1280;
	window.innerHeight = 900;
	window.setTimeout = (callback) => {
		timers.set(++nextTimer, callback);
		return nextTimer;
	};
	window.clearTimeout = (id) => timers.delete(id);
	window.FormData = class {
		constructor(form) {
			this.values = form.values;
		}
		[Symbol.iterator]() {
			return this.values[Symbol.iterator]();
		}
	};
	window.ResizeObserver = class {
		observe() {
			observers++;
		}
		disconnect() {
			observers--;
		}
	};
	const form = new Element();
	form.values = [
		["_csrf", "session-bound"],
		["layout__sequence", "0"],
		["layout__end", "1"],
	];
	form.action = "http://localhost/records/project/fixture";
	const frame = new Element();
	frame.name = "composition-layout";
	frame.contentDocument = { querySelector: () => null };
	const stage = new Element();
	stage.clientWidth = 640;
	const button = new Element();
	const status = new Element();
	const viewport = new Element();
	viewport.value = "1280";
	const box = new Element();
	box.style = {};
	const label = new Element();
	label.hidden = true;
	const root = new Element();
	root.dataset = { compositionName: "layout" };
	root.closest = () => form;
	root.hasAttribute = () => false;
	const cell = new Element();
	cell.dataset = { share: "7" };
	cell.style = {
		removeProperty(name) {
			if (name === "flex-grow") delete this.flexGrow;
		},
	};
	root.cells = [cell];
	root.querySelectorAll = (selector) => (selector === "[data-share]" ? [cell] : []);
	root.querySelector = (selector) =>
		({
			"[data-composition-frame]": frame,
			"[data-composition-preview-button]": button,
			"[data-composition-stage]": stage,
			"[data-composition-status]": status,
			'[name="layout__viewport"]:checked': viewport,
			"[data-composition-box]": box,
			"[data-composition-scale]": label,
		})[selector];
	root.ownerDocument = {
		defaultView: window,
		body: { append: () => transports++ },
		createElement: () => {
			const element = new Element();
			element.submit = () =>
				navigations.push(element.children.map((i) => [i.name, i.value]));
			element.remove = () => transports--;
			return element;
		},
	};
	const run = (id) => {
		const callback = timers.get(id);
		timers.delete(id);
		callback();
	};
	const request = () => {
		const event = new Event("input");
		Object.defineProperty(event, "target", {
			value: { name: "layout__width", type: "number" },
		});
		form.dispatchEvent(event);
		run(nextTimer);
	};
	const settle = (id) => {
		frame.contentDocument.querySelector = (selector) =>
			selector.startsWith("meta") ? { content: String(id) } : null;
		frame.dispatchEvent(new Event("load"));
	};
	return {
		root,
		form,
		frame,
		box,
		label,
		window,
		timers,
		navigations,
		run,
		request,
		settle,
		resources: () => ({ observers, transports }),
	};
}

test("composition island coalesces intent and disposal releases listeners observers timers and transports", () => {
	const f = fixture();
	const dispose = mountCompositionEditor(f.root);
	assert.equal(
		f.root.cells[0].style.flexGrow,
		"7",
		"row map cells are proportional to their share",
	);
	f.run(1);
	assert.equal(f.navigations.length, 1);
	assert.equal(f.frame.style.width, "1280px");
	assert.equal(f.frame.style.height, "900px");
	assert.equal(f.frame.style.transform, "scale(0.5)");
	assert.equal(f.box.style.width, "640px", "the visible box matches the scaled page");
	assert.equal(f.box.style.height, "450px");
	assert.equal(f.label.hidden, false, "the device width and scale are stated");
	f.request();
	f.request();
	assert.equal(
		f.navigations.length,
		1,
		"one navigation active, one replaceable pending intent",
	);
	f.settle(1);
	assert.equal(f.navigations.length, 2);
	assert.deepEqual(f.navigations[1].at(-1), ["layout__sequence", "3"]);
	f.settle(2);
	assert.equal(f.frame.hidden, true, "stale completion cannot show the old layout");
	dispose();
	dispose();
	assert.equal(f.timers.size, 0);
	assert.equal(
		f.root.cells[0].style.flexGrow,
		undefined,
		"disposal restores equal native cells",
	);
	assert.deepEqual(f.resources(), { observers: 0, transports: 0 });
	f.settle(3);
	f.form.dispatchEvent(new Event("input"));
	f.window.dispatchEvent(new Event("resize"));
	assert.equal(f.timers.size, 0);
	assert.equal(f.navigations.length, 2);
});

test("composition preview fits the whole page by the smaller of the width and height ratios", () => {
	const f = fixture();
	const dispose = mountCompositionEditor(f.root);
	f.run(1);
	f.frame.contentDocument = {
		querySelector: (selector) =>
			selector.startsWith("meta") ? { content: "1" } : null,
		documentElement: { scrollHeight: 2700 },
		body: { scrollHeight: 2600 },
	};
	f.frame.dispatchEvent(new Event("load"));
	assert.equal(f.frame.style.height, "2700px", "the frame grows to the measured page");
	assert.equal(
		f.frame.style.transform,
		"scale(0.24)",
		"a tall page is scaled to fit the height",
	);
	assert.equal(f.box.style.height, "648px");
	assert.equal(f.box.style.width, "307px");
	dispose();
	assert.equal(f.label.hidden, true, "disposal hides the scale label");
});

test("composition preview hides the old frame while keeping and reads the latest viewport after acknowledgement", async () => {
	const f = fixture();
	let acknowledge;
	const dispose = mountCompositionEditor(
		f.root,
		() =>
			new Promise((resolve) => {
				acknowledge = resolve;
			}),
	);
	f.run(1);
	assert.equal(f.frame.hidden, true);
	assert.equal(f.navigations.length, 0, "no preview is submitted before keeping");
	f.root.querySelector('[name="layout__viewport"]:checked').value = "390";
	f.request();
	acknowledge(true);
	await new Promise(setImmediate);
	assert.equal(f.navigations.length, 1);
	assert.equal(f.frame.style.width, "390px");
	f.settle(1);
	assert.equal(f.frame.hidden, false);
	f.request();
	assert.equal(f.frame.hidden, true, "an earlier ready frame is hidden synchronously");
	f.settle(1);
	assert.equal(
		f.frame.hidden,
		true,
		"old load events cannot advertise readiness while keeping",
	);
	acknowledge(true);
	await new Promise(setImmediate);
	assert.equal(f.navigations.length, 2);
	dispose();
});
