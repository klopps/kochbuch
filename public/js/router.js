/**
 * Tiny hash-based router - no build step, no external router library, so
 * navigation works the same whether the app is loaded as a plain web page,
 * an installed PWA, or inside a Capacitor WebView. Routes are matched
 * against location.hash (e.g. "#/recipes/42/edit"); a "?" suffix is parsed
 * as a query string.
 */
const Router = (() => {
    const routes = [];

    function on(pattern, handler) {
        const keys = [];
        const regex = new RegExp(
            '^' + pattern.replace(/:[^/]+/g, (segment) => {
                keys.push(segment.slice(1));

                return '([^/]+)';
            }) + '$'
        );
        routes.push({ regex, keys, handler });
    }

    async function resolve() {
        const raw = (location.hash || '#/').slice(1);
        const [path, queryString] = raw.split('?');
        const query = Object.fromEntries(new URLSearchParams(queryString || ''));

        for (const route of routes) {
            const match = path.match(route.regex);
            if (!match) {
                continue;
            }
            const params = {};
            route.keys.forEach((key, i) => { params[key] = decodeURIComponent(match[i + 1]); });

            window.scrollTo(0, 0);
            await route.handler(params, query);

            return;
        }

        Views.notFound();
    }

    function navigate(path) {
        location.hash = '#' + path;
    }

    /**
     * Delegated click handler for every internal `<a href="#/...">` link,
     * not just ones inside the offcanvas menu - Bootstrap's own
     * `data-bs-dismiss="offcanvas"` click handler (attached to the nav
     * links so the drawer closes after picking a destination) calls
     * event.preventDefault() on that same click, which silently swallows
     * the anchor's normal browser-default hash navigation before it can
     * happen (confirmed via event.defaultPrevented). Rather than depend on
     * listener registration order against Bootstrap's bundle, navigate
     * explicitly here on every such click - it's a plain reassignment of
     * location.hash, identical in effect to the browser's own default
     * action, so it works whether or not something else already
     * prevented that default.
     */
    function interceptInternalLinks() {
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href^="#/"]');
            if (!link) {
                return;
            }
            event.preventDefault();
            navigate(link.getAttribute('href').slice(1));
        });
    }

    function start() {
        interceptInternalLinks();
        window.addEventListener('hashchange', resolve);
        resolve();
    }

    return { on, start, navigate };
})();
