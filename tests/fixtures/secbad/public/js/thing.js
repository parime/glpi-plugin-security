// SPDX-License-Identifier: GPL-3.0-or-later
// Fixture : XSS dans le navigateur, CodeQL (js/xss-through-dom ou js/xss) doit la trouver :
// le fragment de l'URL est écrit tel quel comme HTML.
document.addEventListener('DOMContentLoaded', function () {
    var target = document.getElementById('secbad-preview');
    target.innerHTML = decodeURIComponent(window.location.hash.substring(1));
});
