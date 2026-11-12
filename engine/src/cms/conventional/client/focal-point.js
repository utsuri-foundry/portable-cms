import { messages } from "./messages.js";

/** Enhances one native revision-protected form; changes stay unsaved until submit. */
export function mountFocalPoint(fieldset) {
	const x = fieldset.querySelector('[name="focal_x"]');
	const y = fieldset.querySelector('[name="focal_y"]');
	const reset = fieldset.querySelector("[data-focal-reset]");
	const pointer = fieldset.querySelector("[data-focal-pointer]");
	const surface = fieldset.querySelector("[data-focal-surface]");
	const marker = fieldset.querySelector("[data-focal-marker]");
	const crop = fieldset.querySelector("[data-focal-crop]");
	const output = fieldset.querySelector("[data-focal-output]");
	const t = messages(fieldset);
	const update = () => {
		if (!x.validity.valid || !y.validity.valid) return;
		const horizontal = Number(x.value);
		const vertical = Number(y.value);
		marker?.setAttribute("cx", String(horizontal));
		marker?.setAttribute("cy", String(vertical));
		if (crop) {
			const width = Number(crop.dataset.width);
			const height = Number(crop.dataset.height);
			const scale = Math.max(400 / width, 240 / height);
			crop.setAttribute("width", String(width * scale));
			crop.setAttribute("height", String(height * scale));
			crop.setAttribute("x", String(((400 - width * scale) * horizontal) / 100));
			crop.setAttribute("y", String(((240 - height * scale) * vertical) / 100));
		}
		output.textContent = t("focal.position", { x: horizontal, y: vertical });
	};
	const change = () => {
		reset.checked = false;
		update();
	};
	const choose = (horizontal, vertical) => {
		x.value = String(Math.max(0, Math.min(100, Math.round(horizontal))));
		y.value = String(Math.max(0, Math.min(100, Math.round(vertical))));
		change();
	};
	x.addEventListener("input", change);
	y.addEventListener("input", change);
	reset.addEventListener("change", () => {
		if (reset.checked) {
			x.value = "50";
			y.value = "50";
			update();
		}
	});
	if (pointer) {
		pointer.hidden = false;
		pointer.addEventListener("click", (event) => {
			if (event.detail === 0) return;
			const rect = surface.getBoundingClientRect();
			if (rect.width > 0 && rect.height > 0)
				choose(
					((event.clientX - rect.left) / rect.width) * 100,
					((event.clientY - rect.top) / rect.height) * 100,
				);
		});
		pointer.addEventListener("keydown", (event) => {
			const moves = {
				ArrowLeft: [-1, 0],
				ArrowRight: [1, 0],
				ArrowUp: [0, -1],
				ArrowDown: [0, 1],
			};
			const move = moves[event.key];
			if (move) {
				event.preventDefault();
				choose(Number(x.value) + move[0], Number(y.value) + move[1]);
			} else if (event.key === "Home") {
				event.preventDefault();
				choose(50, 50);
			}
		});
	}
}
