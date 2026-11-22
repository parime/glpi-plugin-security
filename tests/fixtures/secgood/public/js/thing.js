// SPDX-License-Identifier: GPL-3.0-or-later
// Fixture : le fragment de l'URL est affiché comme texte, jamais interprété comme HTML.
document.addEventListener('DOMContentLoaded', function () {
    var target = document.getElementById('secgood-preview');
    target.textContent = decodeURIComponent(window.location.hash.substring(1));
});
