// Idioma de la página: el elegido antes, si no el del navegador, y si no español.
(function () {
    var langs = ['es', 'en', 'pt'];
    var saved = null;
    try { saved = localStorage.getItem('lang'); } catch (e) {}
    var guess = (navigator.language || 'es').slice(0, 2);

    function set(lang) {
        if (langs.indexOf(lang) < 0) lang = 'es';
        document.documentElement.lang = lang;
        document.querySelectorAll('[data-l]').forEach(function (el) {
            el.hidden = el.getAttribute('data-l') !== lang;
        });
        document.querySelectorAll('[data-set-lang]').forEach(function (b) {
            b.classList.toggle('current', b.getAttribute('data-set-lang') === lang);
        });
        try { localStorage.setItem('lang', lang); } catch (e) {}
    }

    document.querySelectorAll('[data-set-lang]').forEach(function (b) {
        b.addEventListener('click', function () { set(b.getAttribute('data-set-lang')); });
    });
    set(saved || guess);
})();
