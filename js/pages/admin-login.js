// ============================================
// ADMIN LOGIN SCRIPT
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.getElementById('login-form');
  loginForm.addEventListener('submit', handleLogin);

  // Une session en cours ne renvoie au tableau de bord que si elle y donne
  // vraiment acces. Se fier au seul jeton provoquait un aller-retour sans fin
  // avec le tableau de bord, qui refusait ensuite cette meme session.
  const session = getStorage('user');
  if (api.token && session && (session.role === 'admin' || session.role === 'editor')) {
    window.location.href = 'admin-dashboard.html';
    return;
  }

  if (new URLSearchParams(window.location.search).get('session') === 'invalide') {
    showNotification('Ta session a expire ou ne donne pas acces a l\'administration. Reconnecte-toi.', 'warning', 8000);
  }
});

async function handleLogin(e) {
  e.preventDefault();
  clearFormErrors(this);

  const email = this.querySelector('#email').value;
  const password = this.querySelector('#password').value;

  console.log('🔐 Tentative de connexion...', { email, password: '***' });
  console.log('📡 API URL:', 'http://localhost/api/auth/login');

  try {
    console.log('⏳ Envoi de la requête...');
    const response = await api.login(email, password);
    console.log('✅ Réponse reçue:', response);

    if (response.success) {
      // Extract data from response
      const { token, user } = response.data;

      // Check if user is admin
      if (user.role !== 'admin' && user.role !== 'editor') {
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
