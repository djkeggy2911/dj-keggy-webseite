'use strict';
// Guest text is never HTML and is not machine-translated into an invented quote.
(() => {
    const section = document.getElementById('testimonials');
    const grid = section?.querySelector('.testimonial-grid');
    if (!grid) return;
    const draw = (reviews) => {
        grid.replaceChildren();
        for (const review of reviews) {
            const stars = Number(review.stars);
            if (!Number.isInteger(stars) || stars < 1 || stars > 5 || typeof review.body !== 'string' || typeof review.display_name !== 'string') continue;
            const card = document.createElement('article');
            card.className = 'testimonial-card';
            if (['de', 'hr', 'en', 'it'].includes(review.language)) card.lang = review.language;
            const rating = document.createElement('p'); rating.className = 'testimonial-stars';
            rating.textContent = '★'.repeat(stars) + '☆'.repeat(5 - stars);
            rating.setAttribute('aria-label', `${stars} / 5`);
            const quote = document.createElement('p'); quote.className = 'review-quote'; quote.textContent = review.body;
            const author = document.createElement('p'); author.className = 'testimonial-author'; author.textContent = review.display_name;
            card.append(rating, quote, author); grid.append(card);
        }
        section.hidden = grid.childElementCount === 0;
    };
    fetch('/review/feed.php', { credentials: 'omit', cache: 'no-store' })
        .then(response => { if (!response.ok) throw new Error('Reviews unavailable'); return response.json(); })
        .then(data => { if (Array.isArray(data.reviews)) draw(data.reviews.slice(0, 30)); })
        .catch(() => { section.hidden = true; });
})();
