(() => {
  const form = document.querySelector('[data-allegro-finish]');
  if (!form || form.getAttribute('action') !== '/admin/allegro/' || form.method.toLowerCase() !== 'post') return;
  // Only the opaque handle and existing session CSRF are posted. No OAuth code,
  // verifier, access/refresh token or Client Secret is present in this document.
  history.replaceState(null, '', '/admin/allegro/');
  form.requestSubmit();
})();
