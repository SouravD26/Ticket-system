    </main>
<?php if (is_logged_in()): ?>
    <footer class="border-t border-zinc-200 px-6 py-3.5 text-center text-[11px] text-zinc-400">
      &copy; <?= date('Y') ?> <?= APP_NAME ?> · Ticketing System
    </footer>
  </div>
</div>
<?php endif; ?>
</body>
</html>
