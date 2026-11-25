/**
 * Koperasi Kita — PWA service worker registration (standalone).
 *
 * This module does ONE thing: register the service worker served by the
 * theme endpoint. The install flow (beforeinstallprompt, the install
 * button, the install prompt modal) already lives in main.ts and is not
 * duplicated here — two prompt handlers would fight over the same event.
 *
 * Guards itself on `window.kkData.isPwaEnabled`: when PWA is disabled
 * this file is not enqueued at all, and even if it were loaded it would
 * run zero code. Registration failure is swallowed on purpose: the site
 * must keep working exactly as before, just without offline caching.
 */

export {};

interface KkData {
	isPwaEnabled?: boolean;
}

// No global augmentation here: src/ts/main.ts already declares
// window.kkData, and a second declaration with a different shape breaks
// the build. Read it through a narrow cast instead.

function getKkData(): KkData {
	return (window as unknown as { kkData?: KkData }).kkData ?? {};
}

function serviceWorkerUrl(): string {
	// The install button carries the real endpoint URL as a data
	// attribute, so subdirectory installs resolve it correctly too.
	const button = document.querySelector<HTMLButtonElement>('#kk-pwa-install');
	const fromButton = button?.dataset.kkSwUrl;
	if (fromButton) {
		return fromButton;
	}
	return `${window.location.origin}/kk-service-worker.js`;
}

async function registerServiceWorker(): Promise<void> {
	if (!('serviceWorker' in navigator)) {
		return;
	}
	if (!window.isSecureContext) {
		return;
	}
	try {
		await navigator.serviceWorker.register(serviceWorkerUrl(), { scope: '/' });
	} catch {
		// Registration failed (offline, bad response, …): the page keeps
		// working without a service worker. Never throw here.
	}
}

document.addEventListener('DOMContentLoaded', () => {
	if (getKkData().isPwaEnabled === false) {
		return;
	}
	window.addEventListener('appinstalled', () => {
		const wrap = document.querySelector('[data-kk-pwa-button]');
		if (wrap instanceof HTMLElement) {
			wrap.hidden = true;
		}
		const button = document.querySelector('#kk-pwa-install');
		if (button instanceof HTMLButtonElement) {
			button.hidden = true;
		}
	});
	void registerServiceWorker();
});
