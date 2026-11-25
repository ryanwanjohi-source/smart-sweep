// ============================================================
//  guard.js — the doorkeeper for the role pages.
//
//  Every role page (admin.html, dispatcher.html) loads this
//  file and sets data-role on its <body> to the role that
//  page belongs to. This script asks the server who is
//  logged in (api/session.php) and:
//
//    - nobody logged in  -> back to login.html
//    - wrong role         -> to that role's own home page
//    - right role         -> fill in the name and role
//                            shown at the top of the page
//
//  The server APIs check the session again on every call,
//  so this script is the friendly face of the guard — the
//  real lock is in PHP, not here.
// ============================================================

(function () {
    var home = {
        'Administrator': 'admin.html',
        'Dispatcher': 'dispatcher.html',
        'Collection Driver': 'routes.html',
        'Accountant': 'reports.html',
        'Resident': 'dashboard.html'
    };

    function showWho(user) {
        var names = document.querySelectorAll('[data-user-name]');
        for (var i = 0; i < names.length; i++) names[i].textContent = user.full_name;
        var roles = document.querySelectorAll('[data-user-role]');
        for (var j = 0; j < roles.length; j++) roles[j].textContent = user.role;
    }

    fetch('api/session.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (s) {
            if (!s.logged_in) {
                window.location.href = 'login.html';
                return;
            }
            var needed = document.body.getAttribute('data-role');
            if (needed && s.role !== needed) {
                window.location.href = home[s.role] || 'dashboard.html';
                return;
            }
            showWho(s);
        })
        .catch(function () {
            window.location.href = 'login.html';
        });
})();
