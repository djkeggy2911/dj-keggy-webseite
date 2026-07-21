const menuToggle = document.querySelector(".menu-toggle");
const siteNav = document.querySelector(".site-nav");
const navbar = document.querySelector(".navbar");
const year = document.getElementById("year");
const revealItems = document.querySelectorAll(".reveal");
const contactForm = document.querySelector(".contact-form");
const formNote = document.querySelector(".form-note");

if (year) {
    year.textContent = new Date().getFullYear();
}

const closeMenu = () => {
    if (!menuToggle || !siteNav) {
        return;
    }

    siteNav.classList.remove("open");
    document.body.classList.remove("nav-open");
    menuToggle.setAttribute("aria-expanded", "false");
};

if (menuToggle && siteNav) {
    menuToggle.addEventListener("click", () => {
        const isOpen = siteNav.classList.toggle("open");
        document.body.classList.toggle("nav-open", isOpen);
        menuToggle.setAttribute("aria-expanded", String(isOpen));
    });

    siteNav.querySelectorAll("a").forEach((link) => {
        link.addEventListener("click", closeMenu);
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            closeMenu();
        }
    });
}

const updateNavbar = () => {
    if (!navbar) {
        return;
    }

    navbar.classList.toggle("scrolled", window.scrollY > 20);
};

updateNavbar();
window.addEventListener("scroll", updateNavbar, { passive: true });

if ("IntersectionObserver" in window) {
    const revealObserver = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add("visible");
                    revealObserver.unobserve(entry.target);
                }
            });
        },
        {
            threshold: 0.16,
            rootMargin: "0px 0px -40px 0px",
        }
    );

    revealItems.forEach((item) => revealObserver.observe(item));
} else {
    revealItems.forEach((item) => item.classList.add("visible"));
}

if (contactForm && formNote) {
    const getCheckedValues = (formData, name) => formData.getAll(name).filter(Boolean).map(String);
    const submitButton = contactForm.querySelector('button[type="submit"]');

    const setStatus = (message, isError = false) => {
        formNote.textContent = message;
        formNote.style.color = isError ? 'rgba(255, 125, 97, 0.95)' : 'var(--gold-soft)';
    };

    const clearStatus = () => {
        formNote.textContent = '';
        formNote.style.color = 'var(--gold-soft)';
    };

    const serializeForm = (form) => {
        const data = {};
        const formData = new FormData(form);

        for (const [key, value] of formData.entries()) {
            if (key.endsWith('[]')) {
                data[key] = data[key] || [];
                data[key].push(value);
            } else {
                data[key] = value;
            }
        }

        return data;
    };

    const markInvalidFields = () => {
        const fields = Array.from(contactForm.querySelectorAll('input,textarea,select'));
        fields.forEach((field) => {
            if (field instanceof HTMLElement) {
                field.classList.toggle('invalid-field', !field.checkValidity());
            }
        });
    };

    contactForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        markInvalidFields();
        const invalidFields = Array.from(contactForm.querySelectorAll('input,textarea,select')).filter((field) => !field.checkValidity());

        if (invalidFields.length > 0) {
            const firstInvalid = invalidFields[0];
            if (firstInvalid instanceof HTMLElement) {
                firstInvalid.focus({ preventScroll: true });
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            contactForm.reportValidity();
            setStatus(window.getTranslation('feedback.requiredFields', 'Bitte fülle alle roten Pflichtfelder aus.'), true);

            return;
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = window.getTranslation('quote.submitSending', 'Sende Anfrage...');
        }

        const payload = serializeForm(contactForm);

        try {
            const response = await fetch('send-offer.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || window.getTranslation('feedback.error', 'Beim Senden ist ein Fehler aufgetreten.'));
            }

            setStatus(result.message || window.getTranslation('feedback.success', 'Deine Anfrage wurde erfolgreich gesendet.'), false);
            contactForm.reset();
        } catch (error) {
            setStatus(error.message || window.getTranslation('feedback.error', 'Beim Senden ist ein Fehler aufgetreten.'), true);
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = window.getTranslation('quote.submit', 'Jetzt unverbindliche Offerte anfordern');
            }
        }
    });

    contactForm.addEventListener('input', (event) => {
        if (event.target instanceof HTMLElement && typeof event.target.checkValidity === 'function') {
            if (event.target.checkValidity()) {
                event.target.classList.remove('invalid-field');
            }
        }
        clearStatus();
    });
}
