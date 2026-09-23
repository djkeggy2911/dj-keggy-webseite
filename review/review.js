'use strict';
// Fragments are never sent in HTTP requests. Clear before navigating/submitting.
const fragment = new URLSearchParams(location.hash.slice(1));
const guestCode = fragment.get('code');
if (guestCode && /^[a-f0-9]{64}$/.test(guestCode)) {
    history.replaceState(null, '', location.pathname + location.search);
    const form = document.getElementById('guest-code-form');
    if (form) {
        form.elements.code.value = guestCode;
        form.requestSubmit();
    }
}
