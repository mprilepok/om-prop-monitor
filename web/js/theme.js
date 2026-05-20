function toggleTheme() {
  const isDark = document.documentElement.classList.toggle('dark');
  localStorage.setItem('theme', isDark ? 'dark' : 'light');
  const btn = document.getElementById('theme-btn');
  btn.textContent = isDark ? ('☀ ' + btn.dataset.light) : ('☾ ' + btn.dataset.dark);
  if (typeof updateGaugeThemes === 'function') updateGaugeThemes(isDark);
}

(function() {
  const btn = document.getElementById('theme-btn');
  const isDark = document.documentElement.classList.contains('dark');
  btn.textContent = isDark ? ('☀ ' + btn.dataset.light) : ('☾ ' + btn.dataset.dark);
})();
