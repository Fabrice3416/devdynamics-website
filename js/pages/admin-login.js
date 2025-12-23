// ============================================
// ADMIN LOGIN SCRIPT
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.getElementById('login-form');
  loginForm.addEventListener('submit', handleLogin);

  // Check if already logged in
  if (api.token) {
    window.location.href = 'admin-dashboard.html';
  }
});

async function handleLogin(e) {
  e.preventDefault();
  clearFormErrors(this);

  const email = this.querySelector('#email').value;
  const password = this.querySelector('#password').value;

  console.log('[AUTH] Tentative de connexion...', { email, password: '***' });
  console.log('[API] URL:', 'http://localhost/api/auth/login');

  try {
    console.log('[REQUEST] Envoi de la requête...');
    const response = await api.login(email, password);
    console.log('[SUCCESS] Réponse reçue:', response);

    if (response.success) {
      // Extract data from response
      const { token, user } = response.data;

      // Check if user is admin
      if (user.role !== 'admin' && user.role !== 'instructor') {
        showNotification('Accès refusé. Seuls les administrateurs peuvent se connecter ici.', 'error');
        return;
      }

      api.setToken(token);
      setStorage('user', user);
      showNotification('Connexion réussie!', 'success');
      setTimeout(() => {
        window.location.href = 'admin-dashboard.html';
      }, 1000);
    }
  } catch (error) {
    console.error('❌ Erreur lors de la connexion:', error);
    console.error('Détails:', error.message, error.stack);
    showNotification('Email ou mot de passe incorrect', 'error');
  }
}
