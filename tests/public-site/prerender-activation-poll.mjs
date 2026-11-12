const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function isTargetReplacement(error) {
	const prefix = "Runtime.evaluate: ";
	if (!(error instanceof Error) || !error.message.startsWith(prefix)) return false;
	try {
		const rejection = JSON.parse(error.message.slice(prefix.length));
		return rejection?.message === "Inspected target navigated or closed";
	} catch {
		return false;
	}
}

// Only activation can replace the inspected target. Keep the original 15s budget.
export async function waitForPrerenderActivation(check, observations, wait = delay) {
	for (let attempt = 0; attempt < 150; attempt++) {
		try {
			const value = await check();
			if (value) return value;
		} catch (error) {
			if (!isTargetReplacement(error)) throw error;
		}
		await wait(100);
	}
	throw new Error(
		`click activates the prerendered document: ${JSON.stringify(observations)}`,
	);
}
