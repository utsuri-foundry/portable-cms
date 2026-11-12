export function slugFromTitle(title) {
	return title
		.normalize("NFKD")
		.replace(/\p{Mark}/gu, "")
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, "-")
		.replace(/^-+|-+$/g, "");
}

export function shouldSyncSlug({ workStatus, currentTitle, currentSlug }) {
	if (!currentSlug) return true;
	return workStatus === "draft" && currentSlug === slugFromTitle(currentTitle);
}
