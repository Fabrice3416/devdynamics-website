// ============================================
// CHECKOUT PAGE SCRIPT
// ============================================

let currentCourse = null;
let currentUser = null;
let selectedPaymentMethod = null;

document.addEventListener('DOMContentLoaded', () => {
  checkAuthentication();
  loadCourseData();
  setupPaymentMethodSelection();
  setupPaymentForms();
});

function checkAuthentication() {
  const token = localStorage.getItem('auth_token');
  const userData = localStorage.getItem('user_data');

  if (!token || !userData) {
    // Not logged in, redirect to login with return URL
    const courseId = new URLSearchParams(window.location.search).get('course');
    window.location.href = `student-login.html?return=${encodeURIComponent(window.location.href)}&course=${courseId}`;
    return;
  }

  currentUser = JSON.parse(userData);

  // Check if user is a student
  if (currentUser.role !== 'student') {
    showNotification('Accès non autorisé', 'error');
    setTimeout(() => {
      window.location.href = '../index.html';
    }, 2000);
  }
}

async function loadCourseData() {
  const urlParams = new URLSearchParams(window.location.search);
  const courseId = urlParams.get('course');

  if (!courseId) {
    showNotification('Aucun cours spécifié', 'error');
    setTimeout(() => {
      window.location.href = '../index.html';
    }, 2000);
    return;
  }

  try {
    const response = await api.getCourse(courseId);

    if (response.success) {
      currentCourse = response.data;
      displayCourseSummary(currentCourse);
    } else {
      showNotification('Cours non trouvé', 'error');
      setTimeout(() => {
        window.location.href = '../index.html';
      }, 2000);
    }
  } catch (error) {
    console.error('Erreur chargement cours:', error);
    showNotification('Erreur de chargement', 'error');
  }
}

function displayCourseSummary(course) {
  const summaryContainer = document.getElementById('course-summary');

  summaryContainer.innerHTML = `
    <div class="course-thumbnail-small">
      ${course.thumbnail_url
        ? `<img src="${course.thumbnail_url}" alt="${course.title}">`
        : '<div class="placeholder-thumbnail-small">🎓</div>'}
    </div>
    <div class="course-info-summary">
      <h3>${course.title}</h3>
      <p>${truncate(course.description, 80)}</p>
      <div class="course-meta-small">
        <span>📊 ${course.level}</span>
        <span>⏱️ ${course.duration || 'Non spécifié'}</span>
      </div>
    </div>
  `;

  // Display price
  const priceUSD = course.price || 0;
  const priceHTG = priceUSD * 135; // Approximate exchange rate

  document.getElementById('course-price-display').textContent = `${priceUSD} USD`;
  document.getElementById('total-price-display').textContent = `${priceUSD} USD`;

  // Update MonCash amount (in HTG)
  document.getElementById('moncash-amount').textContent = priceHTG.toFixed(2);

  // Generate reference for bank transfer
  const reference = `COURSE-${course.id}-${currentUser.id}-${Date.now()}`;
  document.getElementById('bank-reference').textContent = reference;
}

function setupPaymentMethodSelection() {
  const paymentMethods = document.querySelectorAll('.payment-method');
  const paymentForms = document.querySelectorAll('.payment-form');

  paymentMethods.forEach(method => {
    const radio = method.querySelector('input[type="radio"]');
    const methodType = method.dataset.method;

    method.addEventListener('click', () => {
      // Update radio selection
      document.querySelectorAll('input[name="payment_method"]').forEach(r => r.checked = false);
      radio.checked = true;

      // Update visual selection
      paymentMethods.forEach(m => m.classList.remove('selected'));
      method.classList.add('selected');

      // Show corresponding form
      paymentForms.forEach(form => form.style.display = 'none');
      const targetForm = document.getElementById(`${methodType}-form`);
      if (targetForm) {
        targetForm.style.display = 'block';
      }

      selectedPaymentMethod = radio.value;
    });
  });
}

function setupPaymentForms() {
  // MonCash form
  const moncashForm = document.getElementById('moncash-payment-form');
  if (moncashForm) {
    moncashForm.addEventListener('submit', handleMonCashPayment);
  }

  // PayPal button
  const paypalBtn = document.getElementById('paypal-submit-btn');
  if (paypalBtn) {
    paypalBtn.addEventListener('click', handlePayPalPayment);
  }

  // Bank transfer form
  const bankForm = document.getElementById('bank-transfer-form');
  if (bankForm) {
    bankForm.addEventListener('submit', handleBankTransfer);
  }
}

async function handleMonCashPayment(e) {
  e.preventDefault();

  if (!currentCourse || !currentUser) {
    showNotification('Erreur de configuration', 'error');
    return;
  }

  const phone = document.getElementById('moncash-phone').value;
  const submitBtn = e.target.querySelector('button[type="submit"]');

  submitBtn.disabled = true;
  submitBtn.textContent = 'Traitement en cours...';

  try {
    // Create enrollment with pending payment
    const enrollmentData = {
      user_id: currentUser.id,
      payment_method: 'moncash',
      payment_status: 'pending',
      access_granted: false
    };

    const enrollResponse = await api.enrollInCourse(currentCourse.id, enrollmentData);

    if (enrollResponse.success) {
      // In production, you would integrate with MonCash API here
      // For now, simulate payment initiation
      showNotification('Paiement MonCash initié. Vérifiez votre téléphone.', 'info');

      // Simulate payment processing
      setTimeout(() => {
        // In production, this would be handled by MonCash webhook
        showNotification('Paiement simulé (mode développement)', 'warning');
        setTimeout(() => {
          window.location.href = 'payment-success.html?method=moncash';
        }, 2000);
      }, 3000);
    }
  } catch (error) {
    console.error('Erreur paiement MonCash:', error);
    showNotification(error.message || 'Erreur lors du paiement', 'error');
    submitBtn.disabled = false;
    submitBtn.textContent = `Payer ${document.getElementById('moncash-amount').textContent} HTG`;
  }
}

async function handlePayPalPayment(e) {
  e.preventDefault();

  if (!currentCourse || !currentUser) {
    showNotification('Erreur de configuration', 'error');
    return;
  }

  const submitBtn = e.target;
  submitBtn.disabled = true;
  submitBtn.textContent = 'Redirection vers PayPal...';

  try {
    // Create enrollment with pending payment
    const enrollmentData = {
      user_id: currentUser.id,
      payment_method: 'paypal',
      payment_status: 'pending',
      access_granted: false
    };

    const enrollResponse = await api.enrollInCourse(currentCourse.id, enrollmentData);

    if (enrollResponse.success) {
      // In production, you would integrate with PayPal SDK here
      // For now, simulate PayPal redirect
      showNotification('Redirection vers PayPal...', 'info');

      setTimeout(() => {
        // Simulate PayPal success
        showNotification('Paiement simulé (mode développement)', 'warning');
        setTimeout(() => {
          window.location.href = 'payment-success.html?method=paypal';
        }, 2000);
      }, 2000);
    }
  } catch (error) {
    console.error('Erreur paiement PayPal:', error);
    showNotification(error.message || 'Erreur lors du paiement', 'error');
    submitBtn.disabled = false;
    submitBtn.textContent = 'Continuer avec PayPal';
  }
}

async function handleBankTransfer(e) {
  e.preventDefault();

  if (!currentCourse || !currentUser) {
    showNotification('Erreur de configuration', 'error');
    return;
  }

  const submitBtn = e.target.querySelector('button[type="submit"]');
  submitBtn.disabled = true;
  submitBtn.textContent = 'Traitement...';

  try {
    // Create enrollment with pending payment
    const enrollmentData = {
      user_id: currentUser.id,
      payment_method: 'bank_transfer',
      payment_status: 'pending',
      access_granted: false
    };

    const enrollResponse = await api.enrollInCourse(currentCourse.id, enrollmentData);

    if (enrollResponse.success) {
      showNotification('Commande enregistrée. Effectuez le virement avec la référence fournie.', 'success');

      setTimeout(() => {
        window.location.href = 'payment-pending.html?method=bank';
      }, 2000);
    }
  } catch (error) {
    console.error('Erreur virement bancaire:', error);
    showNotification(error.message || 'Erreur lors de l\'enregistrement', 'error');
    submitBtn.disabled = false;
    submitBtn.textContent = 'Confirmer la commande';
  }
}
