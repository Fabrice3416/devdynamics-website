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
  if (api.token && session && session.role === 'admin') {
    window.location.href = 'admin-dashboard.html';
    return;
  }

  if (new URLSearchParams(window.location.search).get('session') === 'invalide') {
    showNotification('Ta session a expiré ou ne donne pas accès à l\'administration. Reconnecte-toi.', 'warning', 8000);
  }
});

async function handleLogin(e) {
  e.preventDefault();
  clearFormErrors(this);

  const email = this.querySelector('#email').value;
  const password = this.querySelector('#password').value;

  try {
    const response = await api.login(email, password);

    if (response.success) {
      // Extract data from response
      const { token, user } = response.data;

      // Check if user is admin
      if (user.role !== 'admin') {
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
    showNotification('Email ou mot de passe incorrect', 'error');
  }
}
