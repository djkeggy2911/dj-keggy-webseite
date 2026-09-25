'use strict';
// Only consented, approved feed entries; guest text is always textContent.
(() => {
    const section = document.getElementById('testimonials');
    const grid = section?.querySelector('.testimonial-grid');
    if (!grid) return;
    const labels = {
        de: ['Mehr lesen', 'Weniger anzeigen', 'Vorherige Bewertungen', 'Weitere Bewertungen', 'Bewertungsseite', 'Bewertungen'],
        hr: ['Pročitaj više', 'Prikaži manje', 'Prethodne recenzije', 'Sljedeće recenzije', 'Stranica recenzija', 'Recenzije'],
        en: ['Read more', 'Show less', 'Previous reviews', 'Next reviews', 'Review page', 'Reviews'],
        it: ['Leggi di più', 'Mostra meno', 'Recensioni precedenti', 'Recensioni successive', 'Pagina delle recensioni', 'Recensioni']
    };
    const mobile = window.matchMedia('(max-width: 640px)');
    const tablet = window.matchMedia('(max-width: 960px)');
    const size = () => mobile.matches ? 1 : tablet.matches ? 2 : 3;
    const language = () => labels[document.documentElement.lang] ? document.documentElement.lang : 'de';
    const make = (tag, cls) => { const el = document.createElement(tag); el.className = cls; return el; };
    const button = (cls) => { const el = make('button', cls); el.type = 'button'; return el; };
    const draw = (reviews) => {
        grid.replaceChildren();
        const cards = [], toggles = [];
        for (const review of reviews) {
            const stars = Number(review.stars);
            if (!Number.isInteger(stars) || stars < 1 || stars > 5 || typeof review.body !== 'string' || typeof review.display_name !== 'string') continue;
            const card = make('article', 'testimonial-card');
            if (['de', 'hr', 'en', 'it'].includes(review.language)) card.lang = review.language;
            const rating = make('p', 'testimonial-stars');
            rating.textContent = '★'.repeat(stars) + '☆'.repeat(5 - stars);
            rating.setAttribute('aria-label', `${stars} / 5`);
            const quote = make('p', 'review-quote'); quote.textContent = review.body;
            const author = make('p', 'testimonial-author'); author.textContent = review.display_name;
            card.append(rating, quote);
            const chars = Array.from(review.body.replace(/\s+/g, ' ').trim());
            if (chars.length > 280 || review.body.split('\n').length > 5) {
                const toggle = button('review-expand');
                quote.id = `review-quote-${cards.length}`;
                toggle.setAttribute('aria-controls', quote.id);
                let expanded = false;
                const update = () => {
                    quote.textContent = expanded ? review.body : chars.slice(0, 280).join('').trimEnd() + (chars.length > 280 ? '…' : '');
                    toggle.textContent = labels[language()][expanded ? 1 : 0];
                    toggle.lang = language();
                    toggle.setAttribute('aria-expanded', String(expanded));
                };
                toggle.addEventListener('click', () => { expanded = !expanded; update(); });
                toggles.push(update); update(); card.append(toggle);
            }
            card.append(author); grid.append(card); cards.push(card);
        }
        section.hidden = cards.length === 0;
        if (!cards.length) return;
        grid.id = 'review-slides';
        const shell = make('div', 'review-slider');
        grid.before(shell); shell.append(grid);
        const prev = button('review-arrow review-prev'), next = button('review-arrow review-next');
        prev.textContent = '‹'; next.textContent = '›';
        for (const el of [prev, next]) el.setAttribute('aria-controls', grid.id);
        shell.append(prev, next);
        const dots = make('div', 'review-dots'); section.append(dots);
        const status = make('p', 'review-status'); status.setAttribute('aria-live', 'polite'); status.setAttribute('aria-atomic', 'true'); section.append(status);
        let page = 0;
        const pages = () => Math.ceil(cards.length / size());
        const update = () => {
            page = Math.min(page, pages() - 1);
            const t = labels[language()];
            grid.setAttribute('data-visible', String(Math.min(size(), cards.length - page * size())));
            cards.forEach((card, i) => { card.hidden = i < page * size() || i >= (page + 1) * size(); });
            prev.disabled = page === 0; next.disabled = page === pages() - 1;
            prev.hidden = next.hidden = pages() === 1;
            prev.setAttribute('aria-label', t[2]); next.setAttribute('aria-label', t[3]);
            dots.replaceChildren(); dots.hidden = pages() === 1;
            for (let i = 0; i < pages(); i++) {
                const dot = button('review-dot'); dot.setAttribute('aria-label', `${t[4]} ${i + 1} / ${pages()}`);
                dot.setAttribute('aria-current', i === page ? 'true' : 'false');
                dot.addEventListener('click', () => { page = i; update(); dots.children[i].focus(); });
                dots.append(dot);
            }
            status.textContent = `${t[5]} ${page * size() + 1}–${Math.min((page + 1) * size(), cards.length)} / ${cards.length}`;
            toggles.forEach(fn => fn());
        };
        const move = delta => { page = Math.max(0, Math.min(pages() - 1, page + delta)); update(); };
        prev.addEventListener('click', () => move(-1)); next.addEventListener('click', () => move(1));
        shell.tabIndex = 0; shell.setAttribute('role', 'region'); shell.setAttribute('aria-labelledby', 'review-heading');
        shell.addEventListener('keydown', e => {
            if (e.target !== shell) return;
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { e.preventDefault(); move(e.key === 'ArrowRight' ? 1 : -1); }
        });
        let touch = null;
        grid.addEventListener('touchstart', e => { touch = e.touches.length === 1 ? [e.touches[0].clientX, e.touches[0].clientY] : null; }, {passive: true});
        grid.addEventListener('touchend', e => {
            if (!touch || !e.changedTouches.length) return;
            const dx = e.changedTouches[0].clientX - touch[0], dy = e.changedTouches[0].clientY - touch[1];
            touch = null;
            if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) move(dx < 0 ? 1 : -1);
        }, {passive: true});
        grid.addEventListener('touchcancel', () => { touch = null; });
        for (const media of [mobile, tablet]) media.addEventListener('change', () => { page = 0; update(); });
        document.addEventListener('languagechange', update);
        update();
    };
    fetch('/review/feed.php', { credentials: 'omit', cache: 'no-store' })
        .then(response => { if (!response.ok) throw new Error('Reviews unavailable'); return response.json(); })
        .then(data => { if (Array.isArray(data.reviews)) draw(data.reviews.slice(0, 30)); })
        .catch(() => { section.hidden = true; });
})();
