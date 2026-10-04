/**
 * jQuery effects that sit beside React without touching React-owned markup:
 * they only read the DOM, set data-* attributes React never renders, and
 * animate scroll position.
 *
 *  - sticky header shrink   → <body data-scrolled>
 *  - back-to-top visibility → <body data-scrolled-far>
 *  - smooth in-page anchors → a[href^="#"]
 *  - scroll reveal          → [data-reveal] gets data-revealed="1" when seen
 */
import $ from 'jquery';

const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
let observer = null;

export function initEffects() {
  const $win = $(window);
  const $body = $(document.body);

  const onScroll = () => {
    const y = $win.scrollTop();
    $body.attr('data-scrolled', y > 40 ? '1' : null);
    $body.attr('data-scrolled-far', y > 700 ? '1' : null);
  };
  $win.on('scroll.vc', onScroll);
  onScroll();

  // Smooth scroll for same-page anchors (React Router handles real links)
  $(document).on('click.vc', 'a[href^="#"]:not([href="#"])', function (e) {
    const target = $(this.getAttribute('href'));
    if (!target.length) return;
    e.preventDefault();
    $('html, body').stop().animate({ scrollTop: target.offset().top - 80 }, reduceMotion() ? 0 : 500);
  });

  $(document).on('click.vc', '[data-back-to-top]', (e) => {
    e.preventDefault();
    $('html, body').stop().animate({ scrollTop: 0 }, reduceMotion() ? 0 : 600);
  });

  // Content is only hidden for the reveal once JS is running (never stuck invisible)
  if (!reduceMotion() && 'IntersectionObserver' in window) {
    document.documentElement.setAttribute('data-reveal-ready', '1');
    observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          $(entry.target).attr('data-revealed', '1');
          observer.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
  }
}

/** Call after each route render so new [data-reveal] blocks are watched. */
export function refreshReveal() {
  if (!observer) return;
  // Anything already on screen when the page loads is shown immediately
  $('[data-reveal]:not([data-revealed])').each(function () { observer.observe(this); });
}

/** Jump to top on page change (instant: the new page shouldn't scroll-animate). */
export function scrollToTop() {
  $('html, body').stop().scrollTop(0);
}
