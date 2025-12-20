// ============================================
// COURSE DETAILS PAGE SCRIPT
// ============================================

let currentCourse = null;
let isLoggedIn = false;
let currentUser = null;

document.addEventListener('DOMContentLoaded', () => {
  checkAuthStatus();
  loadCourseDetails();
  setupEnrollButton();
  setupNavigation();
});

function checkAuthStatus() {
  const token = localStorage.getItem('auth_token');
  const userData = localStorage.getItem('user_data');

  if (token && userData) {
    isLoggedIn = true;
    currentUser = JSON.parse(userData);

    // Update auth button
    const authBtn = document.getElementById('auth-btn');
    if (currentUser.role === 'student') {
      authBtn.textContent = 'Mon espace';
      authBtn.href = 'student-dashboard.html';
    } else {
      authBtn.textContent = 'Dashboard';
      authBtn.href = 'admin-dashboard.html';
    }
  }
}

async function loadCourseDetails() {
  // Get course ID from URL
  const urlParams = new URLSearchParams(window.location.search);
  const courseId = urlParams.get('id');

  if (!courseId) {
    showNotFoundState();
    return;
  }

  try {
    const response = await api.getCourse(courseId);

    if (response.success) {
      currentCourse = response.data;
      displayCourseDetails(currentCourse);
      hideLoadingState();
    } else {
      showNotFoundState();
    }
  } catch (error) {
    console.error('Erreur chargement cours:', error);
    showNotFoundState();
  }
}

function displayCourseDetails(course) {
  // Breadcrumb and title
  document.getElementById('breadcrumb-title').textContent = course.title;
  document.getElementById('course-title').textContent = course.title;
  document.getElementById('course-description').textContent = course.description;

  // Full description
  document.getElementById('full-description').innerHTML = `<p>${course.description}</p>`;

  // Meta information
  document.getElementById('course-instructor').textContent = course.instructor || 'DevDynamics';
  document.getElementById('instructor-name').textContent = course.instructor || 'DevDynamics';

  const levelLabels = {
    'debutant': '🟢 Débutant',
    'intermediaire': '🟡 Intermédiaire',
    'avance': '🔴 Avancé'
  };
  document.getElementById('course-level').textContent = levelLabels[course.level] || course.level;
  document.getElementById('course-duration').textContent = course.duration || 'Non spécifié';

  // Dates
  if (course.start_date) {
    document.getElementById('course-start-date').textContent = formatDate(course.start_date);
    document.getElementById('start-date-full').textContent = formatDate(course.start_date);
  }

  if (course.end_date) {
    document.getElementById('end-date-full').textContent = formatDate(course.end_date);
  }

  if (course.enrollment_deadline) {
    document.getElementById('enrollment-deadline').textContent = formatDate(course.enrollment_deadline);
    document.getElementById('enrollment-deadline-item').style.display = 'block';
  }

  // Price
  const priceElement = document.getElementById('course-price');
  if (course.price && course.price > 0) {
    priceElement.innerHTML = `
      <span class="price-amount">${course.price} USD</span>
    `;
  } else {
    priceElement.innerHTML = '<span class="price-free">✨ Gratuit</span>';
  }

  // Thumbnail
  const thumbnailContainer = document.getElementById('course-thumbnail');
  if (course.thumbnail_url) {
    thumbnailContainer.innerHTML = `<img src="${course.thumbnail_url}" alt="${course.title}">`;
  }

  // Category and type
  document.getElementById('course-category').textContent = course.category || 'Général';

  const typeLabels = {
    'physical': '📍 Cours en présentiel',
    'online': '💻 Cours en ligne'
  };
  document.getElementById('course-type').textContent = typeLabels[course.course_type] || course.course_type;

  // Max students (for physical courses)
  if (course.course_type === 'physical' && course.max_students) {
    document.getElementById('max-students').textContent = course.max_students;
    document.getElementById('max-students-info').style.display = 'block';
  }

  // Schedule (for physical courses)
  if (course.schedule) {
    document.getElementById('course-schedule').textContent = course.schedule;
    document.getElementById('schedule-section').style.display = 'block';
  }

  // Course type specific content
  if (course.course_type === 'online') {
    // Show curriculum section for online courses
    document.getElementById('curriculum-section').style.display = 'block';
    loadCourseCurriculum(course.id);

    // Update includes list
    const includesList = document.getElementById('course-includes-list');
    includesList.innerHTML = `
      <li>✓ Accès à vie au contenu</li>
      <li>✓ Modules et leçons structurés</li>
      <li>✓ Vidéos et ressources</li>
      <li>✓ Support de l'instructeur</li>
      <li>✓ Certificat de complétion</li>
    `;
  } else {
    // Physical course
    document.getElementById('schedule-section').style.display = 'block';
  }

  // Setup share buttons
  setupShareButtons(course);
}

async function loadCourseCurriculum(courseId) {
  // This will be implemented when we add course modules/lessons management
  // For now, show a placeholder
  const curriculumContainer = document.getElementById('course-curriculum');
  curriculumContainer.innerHTML = `
    <p class="info-message">
      Le programme détaillé sera disponible après votre inscription au cours.
    </p>
  `;
}

function setupEnrollButton() {
  const enrollBtn = document.getElementById('enroll-btn');
  enrollBtn.addEventListener('click', handleEnrollClick);
}

async function handleEnrollClick(e) {
  e.preventDefault();

  if (!currentCourse) {
    showNotification('Erreur: cours non chargé', 'error');
    return;
  }

  // Check if user is logged in
  if (!isLoggedIn) {
    // For non-logged users, open modal to login/register
    // Modal will handle enrollment after authentication
    openEnrollmentModal();
    return;
  }

  // Check if user is a student
  if (currentUser.role !== 'student') {
    showNotification('Seuls les étudiants peuvent s\'inscrire aux cours', 'error');
    return;
  }

  // If course is free, enroll directly
  if (!currentCourse.price || currentCourse.price === 0) {
    await enrollInFreeCourse();
  } else {
    // For paid courses, show payment options
    openEnrollmentModal();
    showPaymentOptions();
  }
}

async function enrollInFreeCourse() {
  try {
    const enrollData = {
      user_id: currentUser.id,
      payment_method: 'free',
      payment_status: 'completed',
      access_granted: true
    };

    // This endpoint needs to be updated to support authenticated enrollment
    const response = await api.enrollInCourse(currentCourse.id, enrollData);

    if (response.success) {
      showNotification('Inscription réussie ! Redirection vers votre espace...', 'success');
      setTimeout(() => {
        window.location.href = 'student-dashboard.html';
      }, 2000);
    }
  } catch (error) {
    console.error('Erreur inscription:', error);
    showNotification('Erreur lors de l\'inscription', 'error');
  }
}

function setupShareButtons(course) {
  const currentUrl = window.location.href;
  const title = course.title;

  document.getElementById('share-facebook').href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(currentUrl)}`;
  document.getElementById('share-twitter').href = `https://twitter.com/intent/tweet?text=${encodeURIComponent(title)}&url=${encodeURIComponent(currentUrl)}`;
  document.getElementById('share-whatsapp').href = `https://wa.me/?text=${encodeURIComponent(title + ' - ' + currentUrl)}`;

  // Open in new window
  document.querySelectorAll('.share-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      window.open(btn.href, '_blank', 'width=600,height=400');
    });
  });
}

function setupNavigation() {
  const hamburger = document.querySelector('.hamburger');
  const navMenu = document.querySelector('.nav-menu');

  if (hamburger) {
    hamburger.addEventListener('click', () => {
      navMenu.classList.toggle('active');
    });
  }
}

function hideLoadingState() {
  document.getElementById('loading-state').style.display = 'none';
  document.getElementById('course-content').style.display = 'block';
}

function showNotFoundState() {
  document.getElementById('loading-state').style.display = 'none';
  document.getElementById('not-found-state').style.display = 'block';
}

// ============================================
// ENROLLMENT MODAL FUNCTIONS
// ============================================

function openEnrollmentModal() {
  document.getElementById('enrollment-modal').classList.add('active');
  showEnrollmentChoice();
}

function closeEnrollmentModal() {
  document.getElementById('enrollment-modal').classList.remove('active');
}

function showEnrollmentChoice() {
  hideAllSteps();
  document.getElementById('enrollment-choice').style.display = 'block';
}

function showLoginForm() {
  hideAllSteps();
  document.getElementById('login-form-container').style.display = 'block';
}

function showRegisterForm() {
  hideAllSteps();
  document.getElementById('register-form-container').style.display = 'block';
}

function showPaymentOptions() {
  hideAllSteps();
  document.getElementById('payment-options-container').style.display = 'block';
}

function showConfirmation() {
  hideAllSteps();
  document.getElementById('confirmation-container').style.display = 'block';
}

function hideAllSteps() {
  document.querySelectorAll('.enrollment-step').forEach(step => {
    step.style.display = 'none';
  });
}

function goToDashboard() {
  window.location.href = 'student-dashboard.html';
}

// ============================================
// QUICK LOGIN/REGISTER HANDLERS
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  // Quick Login Form
  const loginForm = document.getElementById('quick-login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', handleQuickLogin);
  }

  // Quick Register Form
  const registerForm = document.getElementById('quick-register-form');
  if (registerForm) {
    registerForm.addEventListener('submit', handleQuickRegister);
  }
});

async function handleQuickLogin(e) {
  e.preventDefault();

  const email = document.getElementById('login-email').value;
  const password = document.getElementById('login-password').value;

  try {
    const response = await api.studentLogin(email, password);

    if (response.success) {
      // Store token and user data
      localStorage.setItem('auth_token', response.data.token);
      localStorage.setItem('user_data', JSON.stringify(response.data.user));
      api.setToken(response.data.token);

      // Enroll in course
      await enrollAfterAuth();
    }
  } catch (error) {
    showNotification(error.message || 'Erreur de connexion', 'error');
  }
}

async function handleQuickRegister(e) {
  e.preventDefault();

  const full_name = document.getElementById('register-name').value;
  const email = document.getElementById('register-email').value;
  const password = document.getElementById('register-password').value;
  const phone_number = document.getElementById('register-phone').value;

  try {
    const response = await api.studentRegister({
      full_name,
      email,
      password,
      phone_number: phone_number || null
    });

    if (response.success) {
      // Store token and user data
      localStorage.setItem('auth_token', response.data.token);
      localStorage.setItem('user_data', JSON.stringify(response.data.user));
      api.setToken(response.data.token);

      // Enroll in course
      await enrollAfterAuth();
    }
  } catch (error) {
    showNotification(error.message || 'Erreur lors de l\'inscription', 'error');
  }
}

async function enrollAfterAuth() {
  if (!currentCourse) return;

  // Get user data from localStorage
  const userData = localStorage.getItem('user_data');
  const user = userData ? JSON.parse(userData) : null;

  if (!user) {
    showNotification('Erreur: utilisateur non trouvé', 'error');
    return;
  }

  // For free courses, enroll directly
  if (!currentCourse.price || currentCourse.price === 0) {
    try {
      await api.enrollInCourse(currentCourse.id, {
        user_id: user.id,
        payment_method: 'free',
        payment_status: 'completed',
        access_granted: true
      });

      // Close modal and redirect to dashboard
      closeEnrollmentModal();
      showNotification('Inscription réussie ! Redirection...', 'success');
      setTimeout(() => {
        window.location.href = 'student-dashboard.html';
      }, 1500);
    } catch (error) {
      showNotification(error.message || 'Erreur lors de l\'inscription', 'error');
    }
  } else {
    // For paid courses, show payment options
    showPaymentOptions();
  }
}

// ============================================
// PAYMENT METHOD SELECTION
// ============================================

async function selectPaymentMethod(method) {
  if (!currentCourse) return;

  // Get user data from localStorage
  const userData = localStorage.getItem('user_data');
  const user = userData ? JSON.parse(userData) : null;

  if (!user) {
    showNotification('Erreur: utilisateur non trouvé', 'error');
    return;
  }

  try {
    // Create enrollment with pending payment
    const response = await api.enrollInCourse(currentCourse.id, {
      user_id: user.id,
      payment_method: method,
      payment_status: 'pending',
      amount: currentCourse.price,
      access_granted: false
    });

    if (response.success) {
      // Redirect to checkout page with enrollment ID
      window.location.href = `checkout.html?course=${currentCourse.id}&enrollment=${response.data.id}&method=${method}`;
    }
  } catch (error) {
    showNotification(error.message || 'Erreur lors de l\'inscription', 'error');
  }
}

// Make functions globally available
window.closeEnrollmentModal = closeEnrollmentModal;
window.showEnrollmentChoice = showEnrollmentChoice;
window.showLoginForm = showLoginForm;
window.showRegisterForm = showRegisterForm;
window.selectPaymentMethod = selectPaymentMethod;
window.goToDashboard = goToDashboard;
