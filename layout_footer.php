</main>
<footer class="text-center text-muted small mt-5 mb-3">CAT 2 &middot; Server Administration &middot; PHP on Apache + MariaDB</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($loadTurnstileScript) && function_exists('captcha_enabled') && captcha_enabled()): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
</body>
</html>
