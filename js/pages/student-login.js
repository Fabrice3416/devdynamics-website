// ============================================
// STUDENT LOGIN PAGE SCRIPT
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  setupLoginForm();
  checkIfLoggedIn();
});

function checkIfLoggedIn() {
  const token = localStorage.getItem('auth_token');
  const userData = localStorage.getItem('user_data');

  if (token && userData) {
    const user = JSON.parse(userData);
    if (user.role === 'student') {
      // Already logged in, redirect to student dashboard
      window.location.href = 'student-dashboard.html';
    }
  }
}

function setupLoginForm() {
  const form = document.getElementById('login-form');
  form.addEventListener('submit', handleLogin);
}

async function handleLogin(e) {
  e.preventDefault();
  clearFormErrors(this);

  const email = this.querySelector('#email').value;
  const password = this.querySelector('#password').value;

  const submitButton = this.querySelector('button[type="submit"]');
  submitButton.disabled = true;
  submitButton.textContent = 'Connexion en cours...';

  try {
    const response = await api.studentLogin(email, password);

    if (response.success) {
      // Save token and user data
      api.setToken(response.data.token);
      localStorage.setItem('user_data', JSON.stringify(response.data.user));

      showNotification('Connexion réussie ! Redirection...', 'success');

      // Redirect to student dashboard after 1 second
      setTimeout(() => {
        window.location.href = 'student-dashboard.html';
      }, 1000);
    }
  } catch (error) {
    console.error('Erreur connexion:', error);
    showNotification(error.message || 'Email ou mot de passe incorrect', 'error');
    submitButton.disabled = false;
    submitButton.textContent = 'Se connecter';
  }
}
