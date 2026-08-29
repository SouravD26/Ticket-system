    </main>
<?php if (is_logged_in()): ?>
    <footer class="border-t border-slate-200 px-6 py-4 text-center text-xs text-slate-400">
      &copy; <?= date('Y') ?> <?= APP_NAME ?> · Ticketing System
    </footer>
  </div>
</div>
<?php endif; ?>
</body>
</html>
