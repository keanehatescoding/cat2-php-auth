(function () {
    var input = document.getElementById('password');
    var bar = document.getElementById('pw-strength-bar');
    var text = document.getElementById('pw-strength-text');
    if (!input || !bar || !text) return;

    function score(pw) {
        var s = 0;
        if (!pw) return 0;
        if (pw.length >= 8) s++;
        if (pw.length >= 12) s++;
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s++;
        if (/\d/.test(pw)) s++;
        if (/[^A-Za-z0-9]/.test(pw)) s++;
        return Math.min(s, 5);
    }

    function render() {
        var s = score(input.value);
        var labels = ['—', 'Weak', 'Fair', 'Good', 'Strong', 'Very strong'];
        var classes = ['bg-secondary', 'bg-danger', 'bg-warning', 'bg-info', 'bg-primary', 'bg-success'];
        var widths = ['0%', '20%', '40%', '60%', '80%', '100%'];
        bar.style.width = widths[s];
        bar.className = 'progress-bar ' + classes[s];
        text.textContent = 'Password strength: ' + labels[s];
        text.className = 'small mt-1 ' + (s <= 1 ? 'text-danger' : s <= 3 ? 'text-muted' : 'text-success');
    }

    input.addEventListener('input', render);
    render();
})();
