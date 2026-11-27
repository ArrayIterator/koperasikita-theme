/**
 * Koperasi Kita - shared AJAX client.
 *
 * Every admin-ajax call in the theme goes through here, so every call
 * speaks the same envelope the server answers with (see
 * inc/Api/Response.php):
 *
 *   success: { "success": true,  "data": {...}, "error": null }
 *   failure: { "success": false, "data": null,  "error": { "code", "message" } }
 *
 * postAjax resolves with the `data` payload on success and rejects
 * with an Error carrying the server's `error.message` on failure, so
 * callers never have to inspect the envelope themselves. The error
 * code is stable and machine-readable; the message is safe to show.
 * A non-JSON answer (a server error page, say) falls back to a plain
 * "Request failed (status)" message instead of crashing on the parse.
 */

export interface ApiEnvelope<T> {
	success: boolean;
	data: T | null;
	error: { code: string; message: string } | null;
}

export interface AjaxConfig {
	ajaxUrl: string;
	nonce: string;
}

export async function postAjax<T>(
	config: AjaxConfig,
	action: string,
	params: Record<string, string> = {},
	signal?: AbortSignal,
): Promise<T> {
	const body = new URLSearchParams({ action, nonce: config.nonce, ...params });
	const response = await fetch(config.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body,
		signal,
	});
	const json = (await response.json().catch(() => null)) as ApiEnvelope<T> | null;
	if (!response.ok || !json?.success || json.data === null) {
		throw new Error(json?.error?.message ?? `Request failed (${response.status}).`);
	}
	return json.data;
}
