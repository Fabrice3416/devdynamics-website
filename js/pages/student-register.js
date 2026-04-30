// ============================================
// STUDENT REGISTRATION PAGE SCRIPT
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  setupRegistrationForm();
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

function setupRegistrationForm() {
  const form = document.getElementById('register-form');
  form.addEventListener('submit', handleRegistration);
}

async function handleRegistration(e) {
  e.preventDefault();
  clearFormErrors(this);

  const password = this.querySelector('#password').value;
  const confirmPassword = this.querySelector('#confirm_password').value;

  // Validate password match
  if (password !== confirmPassword) {
    showNotification('Les mots de passe ne correspondent pas', 'error');
    return;
  }

  // Validate terms acceptance
  const termsAccepted = this.querySelector('#terms').checked;
  if (!termsAccepted) {
    showNotification('Vous devez accepter les conditions d\'utilisation', 'error');
    return;
  }

  const data = {
    full_name: this.querySelector('#full_name').value,
    email: this.querySelector('#email').value,
    phone_number: this.querySelector('#phone_number').value || null,
    date_of_birth: this.querySelector('#date_of_birth').value || null,
    password: password
  };

  const submitButton = this.querySelector('button[type="submit"]');
  submitButton.disabled = true;
  submitButton.textContent = 'Création en cours...';

  try {
    const response = await api.studentRegister(data);

    if (response.success) {
      // Save token and user data
      api.setToken(response.data.token);
      localStorage.setItem('user_data', JSON.stringify(response.data.user));

      showNotification('Compte créé avec succès ! Redirection...', 'success');

      // Redirect to student dashboard after 1.5 seconds
      setTimeout(() => {
        window.location.href = 'student-dashboard.html';
      }, 1500);
    }
  } catch (error) {
    console.error('Erreur inscription:', error);
    showNotification(error.message || 'Erreur lors de la création du compte', 'error');
    submitButton.disabled = false;
    submitButton.textContent = 'Créer mon compte';
  }
}
