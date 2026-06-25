function openNav() {
    var sidebar = document.getElementById("sidebar");

    if (sidebar.classList.contains('is-open')) {
        sidebar.classList.remove('is-open');
    } else {
        sidebar.classList.add('is-open');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // Tracking removido na versao de portfolio.
});
