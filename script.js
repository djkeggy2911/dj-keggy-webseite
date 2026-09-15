const menuToggle = document.querySelector(".menu-toggle");
const siteNav = document.querySelector(".site-nav");
const navbar = document.querySelector(".navbar");
const year = document.getElementById("year");
const revealItems = document.querySelectorAll(".reveal");
const offerForm = document.getElementById("offer-form");
const formStatus = document.getElementById("offer-status");
const progressBar = document.getElementById("form-progress");
const progressValue = document.getElementById("form-progress-value");

// Open the compact privacy notice when reached from the form or footer.
const openPrivacyNotice = () => {
    const section = document.getElementById('privacy');
    if (!section) return;
    section.querySelector('details').open = true;
};
document.querySelectorAll('a[href="#privacy"]').forEach(link => {
    link.addEventListener('click', openPrivacyNotice);
});
window.addEventListener('hashchange', () => {
    if (window.location.hash === '#privacy') openPrivacyNotice();
});
if (window.location.hash === '#privacy') openPrivacyNotice();

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

const animateCounter = (element) => {
    if (!element) {
        return;
    }

    const target = Number(element.dataset.count || 0);
    const suffix = element.dataset.suffix || "";
    const duration = 1400;
    const startTime = performance.now();

    const tick = (timestamp) => {
        const progress = Math.min((timestamp - startTime) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        const currentValue = Math.round(target * eased);
        element.textContent = `${currentValue}${suffix}`;

        if (progress < 1) {
            window.requestAnimationFrame(tick);
        } else {
            element.textContent = `${target}${suffix}`;
        }
    };

    window.requestAnimationFrame(tick);
};

if ("IntersectionObserver" in window) {
    const revealObserver = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add("visible");

                    if (entry.target.querySelector(".stat-number")) {
                        entry.target.querySelectorAll(".stat-number").forEach((counter) => animateCounter(counter));
                    }

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

const lightbox = document.createElement("div");
lightbox.className = "lightbox";
lightbox.setAttribute('role', 'dialog');
lightbox.setAttribute('aria-modal', 'true');
lightbox.setAttribute('aria-hidden', 'true');
lightbox.dataset.i18nAriaLabel = 'gallery.heading';
lightbox.innerHTML = '<button class="lightbox-close" type="button" data-i18n-aria-label="a11y.close" aria-label="Bild schließen">×</button><img class="lightbox-image" alt="">';
document.body.appendChild(lightbox);
let lightboxOrigin = null;
const lightboxBackground = Array.from(document.body.children).filter(element => element !== lightbox && !['SCRIPT', 'STYLE'].includes(element.tagName));

const lightboxImage = lightbox.querySelector(".lightbox-image");
const lightboxClose = lightbox.querySelector(".lightbox-close");

document.querySelectorAll('[data-lightbox="gallery"]').forEach((link) => {
    link.addEventListener("click", (event) => {
        event.preventDefault();
        const image = link.querySelector("img");
        if (!image) {
            return;
        }

        lightboxImage.src = link.getAttribute("href") || image.src;
        lightboxImage.alt = image.alt || "Gallery image";
        lightboxImage.dataset.i18nAlt = image.dataset.i18nAlt;
        lightboxOrigin = link;
        lightbox.classList.add("open");
        lightbox.setAttribute('aria-hidden', 'false');
        lightboxBackground.forEach(element => { element.inert = true; });
        document.body.classList.add("lightbox-open");
        lightboxClose.focus();
    });
});

const closeLightbox = () => {
    lightbox.classList.remove("open");
    lightbox.setAttribute('aria-hidden', 'true');
    lightboxBackground.forEach(element => { element.inert = false; });
    document.body.classList.remove("lightbox-open");
    lightboxOrigin?.focus({ preventScroll: true });
};

lightboxClose.addEventListener("click", closeLightbox);
lightbox.addEventListener("click", (event) => {
    if (event.target === lightbox) {
        closeLightbox();
    }
});

document.addEventListener("keydown", (event) => {
    if (event.key === 'Tab' && lightbox.classList.contains('open')) {
        event.preventDefault();
        lightboxClose.focus();
    }
    if (event.key === "Escape" && lightbox.classList.contains("open")) {
        closeLightbox();
    }
});

const setStatus = (message, isError = false) => {
    if (!formStatus) {
        return;
    }

    formStatus.textContent = message;
    if (!message) formStatus.removeAttribute('data-i18n');
    else formStatus.dataset.i18n = isError
        ? (message === getFormLabels().requiredFields ? 'feedback.requiredFields' : 'feedback.error')
        : 'feedback.success';
    formStatus.style.color = isError ? "#ff8a80" : "#f0d79b";
};

const getFormLabels = () => ({
    requiredFields: window.getTranslation ? window.getTranslation("feedback.requiredFields", "Please complete all required fields.") : "Please complete all required fields.",
    sending: window.getTranslation ? window.getTranslation("quote.submitSending", "Sending request...") : "Sending request...",
    success: window.getTranslation ? window.getTranslation("feedback.success", "Your request has been sent successfully.") : "Your request has been sent successfully.",
    error: window.getTranslation ? window.getTranslation("feedback.error", "An error occurred while sending your request.") : "An error occurred while sending your request.",
    submit: window.getTranslation ? window.getTranslation("quote.submit", "Request a free quote now") : "Request a free quote now",
});

const serializeForm = (form) => {
    const data = {};
    const formData = new FormData(form);

    for (const [key, value] of formData.entries()) {
        if (key.endsWith("[]")) {
            data[key] = data[key] || [];
            data[key].push(value);
        } else {
            data[key] = value;
        }
    }

    // The existing PHP handler consumes message and services[]. Fold the added
    // optional fields into these supported fields so no information is lost.
    const details = [];
    if (data.singer) details.push(`Live singer / Sänger: ${data.singer === 'yes' ? 'Yes / Ja' : 'No / Nein'}`);
    if (data.special_wishes?.trim()) details.push(`Special requests / Besondere Wünsche: ${data.special_wishes.trim()}`);
    if (details.length) data.message = [data.message?.trim(), ...details].filter(Boolean).join('\n\n');
    if (data.singer === 'yes') data['services[]'] = [...(data['services[]'] || []), 'Live-Sänger'];
    data.language = window.currentLanguage || 'de';
    return data;
};

const accordionTriggers = document.querySelectorAll(".accordion-trigger");

accordionTriggers.forEach((trigger) => {
    trigger.addEventListener("click", () => {
        const item = trigger.closest(".accordion-item");
        if (!item) {
            return;
        }

        const isOpen = item.classList.contains("active");
        const items = document.querySelectorAll(".accordion-item");

        items.forEach((accordionItem) => {
            accordionItem.classList.remove("active");
            const button = accordionItem.querySelector(".accordion-trigger");
            if (button) {
                button.setAttribute("aria-expanded", "false");
            }
        });

        if (!isOpen) {
            item.classList.add("active");
            trigger.setAttribute("aria-expanded", "true");
        }
    });
});

const updateProgress = () => {
    if (!offerForm || !progressBar || !progressValue) {
        return;
    }

    const requiredFields = Array.from(offerForm.querySelectorAll("input[required], select[required], textarea[required]"));
    let filled = 0;

    requiredFields.forEach((field) => {
        if (field.type === "checkbox") {
            if (field.checked) {
                filled += 1;
            }
            return;
        }

        if (field.value.trim() !== "" && field.checkValidity()) {
            filled += 1;
        }
    });

    const progress = Math.round((filled / requiredFields.length) * 100);
    progressBar.style.width = `${progress}%`;
    progressBar.setAttribute("aria-valuenow", String(progress));
    progressValue.textContent = `${progress}%`;
};

const validateField = (field) => {
    field.setCustomValidity('');
    if (field.required && typeof field.value === 'string' && field.type !== 'checkbox' && !field.value.trim()) {
        field.setCustomValidity(getFormLabels().requiredFields);
    }
    if (field.name === 'phone' && field.value) {
        const digits = field.value.replace(/\D/g, '');
        if (!/^[+\d\s().\/-]+$/.test(field.value) || digits.length < 7 || digits.length > 15) {
            field.setCustomValidity(getFormLabels().requiredFields);
        }
    }
    // Localize all browser constraint messages to the selected website language.
    if (!field.validity.valid) field.setCustomValidity(getFormLabels().requiredFields);
    const isValid = field.checkValidity();
    field.classList.toggle("invalid-field", !isValid);
    field.setAttribute("aria-invalid", String(!isValid));

    if (isValid) {
        field.classList.remove("invalid-field");
    }
};

if (offerForm) {
    const submitButton = offerForm.querySelector('button[type="submit"]');
    const eventDate = offerForm.elements.namedItem('event_date');
    const setMinimumDate = () => {
        const today = new Date();
        eventDate.min = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    };
    setMinimumDate();

    updateProgress();

    offerForm.addEventListener("input", (event) => {
        const target = event.target;
        if (target instanceof HTMLElement && typeof target.checkValidity === "function") {
            validateField(target);
        }
        setStatus("");
        updateProgress();
    });

    offerForm.addEventListener("change", (event) => {
        const target = event.target;
        if (target instanceof HTMLElement && typeof target.checkValidity === "function") {
            validateField(target);
        }
        updateProgress();
    });

    offerForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (submitButton?.disabled) return;

        const fields = Array.from(offerForm.querySelectorAll("input, select, textarea"));
        setMinimumDate();
        fields.forEach((field) => validateField(field));
        const invalidFields = fields.filter((field) => !field.checkValidity());

        if (invalidFields.length > 0) {
            const firstInvalid = invalidFields[0];
            if (firstInvalid instanceof HTMLElement) {
                firstInvalid.focus({ preventScroll: true });
                firstInvalid.scrollIntoView({ behavior: "smooth", block: "center" });
            }
            setStatus(getFormLabels().requiredFields, true);
            return;
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = getFormLabels().sending;
        }

        const payload = serializeForm(offerForm);

        try {
            const response = await fetch("send-offer.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                },
                body: JSON.stringify(payload),
                signal: AbortSignal.timeout(30000),
            });

            const result = await response.json();

            if (!response.ok || !result.success || result.email_sent !== true) {
                if (response.status === 422) {
                    setStatus(getFormLabels().requiredFields, true);
                    return;
                }
                throw new Error('Inquiry was not accepted.');
            }

            offerForm.classList.add("success-state");
            setStatus(getFormLabels().success, false);
            offerForm.reset();
            updateProgress();
        } catch (error) {
            setStatus(getFormLabels().error, true);
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = getFormLabels().submit;
            }
        }
    });
}

// Close the mobile menu when changing language or returning to desktop.
document.querySelectorAll('.lang-btn').forEach(button => button.addEventListener('click', closeMenu));
window.addEventListener('resize', () => { if (window.innerWidth > 1100) closeMenu(); });

// One active video at a time; background playback stops outside the hero.
const pageVideos = Array.from(document.querySelectorAll('video'));
const heroVideo = document.querySelector('.hero-video');
const heroVideoToggle = document.querySelector('.hero-video-toggle');
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
let heroPausedByUser = reducedMotion.matches;
const updateVideoButton = () => {
    if (!heroVideoToggle || !heroVideo) return;
    const key = heroVideo.paused ? 'a11y.play' : 'a11y.pause';
    heroVideoToggle.dataset.i18n = key;
    heroVideoToggle.textContent = window.getTranslation(key);
};
heroVideoToggle?.addEventListener('click', () => {
    if (heroVideo.paused) { heroPausedByUser = false; heroVideo.play().catch(updateVideoButton); }
    else { heroPausedByUser = true; heroVideo.pause(); }
});
heroVideo?.addEventListener('play', updateVideoButton);
heroVideo?.addEventListener('pause', updateVideoButton);
heroVideo?.addEventListener('error', () => { if (heroVideoToggle) heroVideoToggle.hidden = true; });
reducedMotion.addEventListener('change', event => {
    if (event.matches) { heroPausedByUser = true; heroVideo?.pause(); }
    updateVideoButton();
});
pageVideos.forEach(video => {
    video.addEventListener('play', () => {
        pageVideos.forEach(other => { if (other !== video) other.pause(); });
    });
});
document.querySelectorAll('.video-frame').forEach(frame => {
    const video = frame.querySelector('video');
    const button = frame.querySelector('.video-play');
    if (!video || !button) return;
    button.hidden = false;
    button.addEventListener('click', async () => {
        try {
            await video.play();
        } catch {
            // Native controls remain usable if playback is denied or media fails.
            button.hidden = true;
        }
    });
    video.addEventListener('play', () => { button.hidden = true; });
    video.addEventListener('ended', () => { button.hidden = false; });
    video.addEventListener('error', () => { button.hidden = true; });
});
if (heroVideo && 'IntersectionObserver' in window) {
    const heroPlaybackObserver = new IntersectionObserver(entries => {
        const visible = entries[0].isIntersecting;
        if (!visible) heroVideo.pause();
        else if (!document.hidden && !heroPausedByUser && !reducedMotion.matches
            && !pageVideos.some(video => video !== heroVideo && !video.paused)) {
            heroVideo.play().catch(() => {});
        }
    }, { threshold: 0.1 });
    heroPlaybackObserver.observe(heroVideo);
}
if (heroVideo && window.matchMedia('(prefers-reduced-motion: reduce)').matches) heroVideo.pause();
document.addEventListener('visibilitychange', () => {
    if (document.hidden) pageVideos.forEach(video => video.pause());
});

document.addEventListener('languagechange', () => {
    offerForm?.querySelectorAll('[aria-invalid="true"]').forEach(validateField);
    updateVideoButton();
});
window.addEventListener('DOMContentLoaded', updateVideoButton);
