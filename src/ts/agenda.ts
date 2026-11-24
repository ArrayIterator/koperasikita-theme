/**
 * Koperasi Kita — agenda submission form.
 *
 * Standalone module, auto-initialised on DOMContentLoaded. It only runs
 * when a `[kk_agenda_submit]` form is on the page; anything else on the
 * site never loads this file, and even if it did, there is nothing for it
 * to do.
 *
 * The form works without JavaScript — the server validates everything and
 * redirects after submit — so this is progressive enhancement only:
 * client-side checks with inline errors, today as the earliest date, the
 * end time kept after the start time, and the submit button disabled while
 * a submission is in flight so a double tap cannot send the form twice.
 * All controls are real inputs and buttons with 44px+ touch targets; no
 * hover-only behaviour anywhere.
 */

export {};

function $all<T extends Element>(selector: string, root: ParentNode = document): T[] {
	return Array.from(root.querySelectorAll<T>(selector));
}

function fieldErrorId(input: HTMLInputElement | HTMLTextAreaElement): string {
	return `${input.id}-error`;
}

function showError(input: HTMLInputElement | HTMLTextAreaElement, message: string): void {
	input.setAttribute('aria-invalid', 'true');
	const existing = document.getElementById(fieldErrorId(input));
	if (existing && existing.classList.contains('kk-agenda-error')) {
		existing.textContent = message;
		return;
	}
	const error = document.createElement('span');
	error.className = 'kk-agenda-error';
	error.id = fieldErrorId(input);
	error.textContent = message;
	input.setAttribute('aria-describedby', error.id);
	input.insertAdjacentElement('afterend', error);
}

function clearError(input: HTMLInputElement | HTMLTextAreaElement): void {
	input.removeAttribute('aria-invalid');
	const describedBy = input.getAttribute('aria-describedby');
	if (describedBy) {
		input.removeAttribute('aria-describedby');
	}
	const error = document.getElementById(fieldErrorId(input));
	if (error && error.classList.contains('kk-agenda-error')) {
		error.remove();
	}
}

function initAgendaForm(form: HTMLFormElement): void {
	const title = form.querySelector<HTMLInputElement>('#kk-agenda-title');
	const date = form.querySelector<HTMLInputElement>('#kk-agenda-date');
	const startTime = form.querySelector<HTMLInputElement>('#kk-agenda-start-time');
	const endTime = form.querySelector<HTMLInputElement>('#kk-agenda-end-time');
	const location = form.querySelector<HTMLInputElement>('#kk-agenda-location');
	const description = form.querySelector<HTMLTextAreaElement>('#kk-agenda-description');
	const submitButton = form.querySelector<HTMLButtonElement>('[data-kk-agenda-submit]');

	if (!title || !date || !startTime || !location || !description) {
		return;
	}

	// Aliases with the narrowed (non-null) types: the guard above proves
	// these exist, but TypeScript does not carry that narrowing into the
	// closures below, so the aliases carry it instead.
	const titleInput = title;
	const dateInput = date;
	const startTimeInput = startTime;
	const locationInput = location;
	const descriptionInput = description;

	// Today is the earliest date: the server rejects past events, and
	// the picker should not offer them in the first place.
	const today = new Date();
	const year = today.getFullYear();
	const month = String(today.getMonth() + 1).padStart(2, '0');
	const day = String(today.getDate()).padStart(2, '0');
	dateInput.min = `${year}-${month}-${day}`;

	// Keep the end time after the start time when both are set.
	function syncEndTime(): void {
		if (!endTime || endTime.value === '' || startTimeInput.value === '') {
			return;
		}
		if (endTime.value <= startTimeInput.value) {
			endTime.value = '';
		}
	}
	startTimeInput.addEventListener('change', syncEndTime);

	$all<HTMLInputElement | HTMLTextAreaElement>('input, textarea', form).forEach((input) => {
		input.addEventListener('input', () => clearError(input));
	});

	form.addEventListener('submit', (event) => {
		// A holder object, not a plain variable: assignments made inside
		// the check() closure below are visible to the code after it.
		const firstInvalid: { element: HTMLInputElement | HTMLTextAreaElement | null } = { element: null };

		function check(input: HTMLInputElement | HTMLTextAreaElement, valid: boolean, message: string): void {
			if (valid) {
				return;
			}
			showError(input, message);
			if (firstInvalid.element === null) {
				firstInvalid.element = input;
			}
		}

		check(titleInput, titleInput.value.trim().length >= 3, 'Please give the event a title of at least 3 characters.');
		check(titleInput, titleInput.value.trim().length <= 200, 'The title is too long. Please keep it under 200 characters.');

		const datePattern = /^\d{4}-\d{2}-\d{2}$/;
		check(dateInput, datePattern.test(dateInput.value), 'Please pick a valid date.');
		if (datePattern.test(dateInput.value) && dateInput.value < dateInput.min) {
			check(dateInput, false, 'The event date is in the past. Please pick a future date.');
		}

		const timePattern = /^([01]\d|2[0-3]):[0-5]\d$/;
		check(startTimeInput, timePattern.test(startTimeInput.value), 'Please pick a valid start time.');
		if (endTime && endTime.value !== '') {
			check(endTime, timePattern.test(endTime.value), 'Please pick a valid end time.');
			if (timePattern.test(endTime.value) && timePattern.test(startTimeInput.value) && endTime.value <= startTimeInput.value) {
				check(endTime, false, 'The end time must be after the start time.');
			}
		}

		check(locationInput, locationInput.value.trim().length >= 3, 'Please tell us where the event takes place (at least 3 characters).');
		check(locationInput, locationInput.value.trim().length <= 200, 'The location is too long. Please keep it under 200 characters.');
		check(descriptionInput, descriptionInput.value.trim().length > 0, 'Please write a short description of the event.');
		check(descriptionInput, descriptionInput.value.trim().length <= 5000, 'The description is too long. Please keep it under 5000 characters.');

		if (firstInvalid.element !== null) {
			event.preventDefault();
			firstInvalid.element.focus();
			return;
		}

		// Valid: lock the button so a double tap (or an impatient second
		// tap on Android) cannot submit the form twice. The server-side
		// rate limit is the backstop if this is bypassed.
		if (submitButton) {
			submitButton.disabled = true;
		}
	});
}

function initAgenda(): void {
	$all<HTMLFormElement>('[data-kk-agenda-form]').forEach((form) => initAgendaForm(form));
}

document.addEventListener('DOMContentLoaded', initAgenda);
