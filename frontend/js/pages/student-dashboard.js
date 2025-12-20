// ============================================
// STUDENT DASHBOARD PAGE SCRIPT
// ============================================

let currentUser = null;
let allEnrollments = [];
let allCourses = [];
let currentMyCoursesFilter = 'all';
let currentBrowseFilter = 'all';

document.addEventListener('DOMContentLoaded', () => {
  checkAuthentication();
  setupNavigation();
  setupMobileMenu();
  setupLogout();
  setupFilters();
});

function checkAuthentication() {
  const token = localStorage.getItem('auth_token');
  const userData = localStorage.getItem('user_data');

  if (!token || !userData) {
    window.location.href = 'student-login.html';
    return;
  }

  currentUser = JSON.parse(userData);

  if (currentUser.role !== 'student') {
    showNotification('Accès non autorisé', 'error');
    setTimeout(() => {
      window.location.href = '../index.html';
    }, 2000);
    return;
  }

  loadUserProfile();
  loadDashboardData();
}

async function loadUserProfile() {
  try {
    const response = await api.getStudentProfile();
    if (response.success) {
      currentUser = { ...currentUser, ...response.data };
      updateUserDisplay();
    }
  } catch (error) {
    console.error('Erreur chargement profil:', error);
  }
}

function updateUserDisplay() {
  document.getElementById('user-name').textContent = currentUser.full_name;
  document.getElementById('user-greeting').textContent = `Bonjour, ${currentUser.full_name.split(' ')[0]}!`;

  // Update avatar
  const initial = currentUser.full_name.charAt(0).toUpperCase();
  document.getElementById('user-avatar-container').innerHTML = `
    <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='50' fill='%234A90E2'/%3E%3Ctext x='50' y='70' font-size='50' fill='white' text-anchor='middle'%3E${initial}%3C/text%3E%3C/svg%3E" alt="User" class="user-avatar">
  `;

  // Update profile form
  if (document.getElementById('profile-full-name')) {
    document.getElementById('profile-full-name').value = currentUser.full_name || '';
    document.getElementById('profile-email').value = currentUser.email || '';
    document.getElementById('profile-phone').value = currentUser.phone_number || '';
    document.getElementById('profile-dob').value = currentUser.date_of_birth || '';
  }
}

async function loadDashboardData() {
  await Promise.all([
    loadEnrollments(),
    loadAvailableCourses(),
    loadCertificatesCount()
  ]);
}

async function loadCertificatesCount() {
  try {
    const response = await api.getMyCertificates();
    if (response.success) {
      const count = response.data.length;
      document.getElementById('certificates-count').textContent = count;
    }
  } catch (error) {
    console.error('Erreur chargement certificats:', error);
    document.getElementById('certificates-count').textContent = '0';
  }
}

async function loadEnrollments() {
  try {
    const response = await api.getStudentEnrollments();
    if (response.success) {
      allEnrollments = response.data;

      const enrolledCount = allEnrollments.length;
      const completedCount = allEnrollments.filter(e => e.status === 'completed').length;
      const inProgressCount = allEnrollments.filter(e => e.status === 'approved' && e.access_granted).length;

      document.getElementById('enrolled-courses-count').textContent = enrolledCount;
      document.getElementById('completed-courses-count').textContent = completedCount;
      document.getElementById('in-progress-count').textContent = inProgressCount;

      displayEnrollments(allEnrollments);
      filterMyCourses();
    }
  } catch (error) {
    console.error('Erreur chargement inscriptions:', error);
  }
}

function displayEnrollments(enrollments) {
  const continueContainer = document.getElementById('continue-learning-container');

  if (enrollments.length === 0) {
    continueContainer.innerHTML = '<p class="empty-state">Vous n\'avez pas de cours en cours. <a href="#" data-section="browse-courses">Parcourir les cours</a></p>';
    return;
  }

  const inProgress = enrollments.filter(e => e.access_granted && e.status === 'approved');
  if (inProgress.length > 0) {
    continueContainer.innerHTML = inProgress.map(enrollment => createEnrollmentCard(enrollment, true)).join('');
  } else {
    continueContainer.innerHTML = '<p class="empty-state">Aucun cours en cours</p>';
  }

  // Setup click on "Parcourir les cours" link
  continueContainer.querySelectorAll('[data-section]').forEach(link => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const section = link.dataset.section;
      document.querySelector(`[data-section="${section}"]`).click();
    });
  });
}

function filterMyCourses() {
  const container = document.getElementById('my-courses-container');

  if (allEnrollments.length === 0) {
    container.innerHTML = '<p class="empty-state">Vous n\'êtes inscrit à aucun cours. <a href="#" data-section="browse-courses">Parcourir les cours</a></p>';
    container.querySelectorAll('[data-section]').forEach(link => {
      link.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelector('.nav-item[data-section="browse-courses"]').click();
      });
    });
    return;
  }

  let filtered = allEnrollments;

  if (currentMyCoursesFilter !== 'all') {
    filtered = allEnrollments.filter(e => e.course_type === currentMyCoursesFilter);
  }

  if (filtered.length === 0) {
    container.innerHTML = `<p class="empty-state">Aucun cours ${currentMyCoursesFilter === 'online' ? 'en ligne' : 'présentiel'}</p>`;
    return;
  }

  container.innerHTML = filtered.map(enrollment => createEnrollmentCard(enrollment, false)).join('');
}

function createEnrollmentCard(enrollment, showProgress = false) {
  const statusBadge = {
    'pending': '<span class="badge badge-warning">En attente</span>',
    'approved': '<span class="badge badge-success">Approuvé</span>',
    'rejected': '<span class="badge badge-danger">Rejeté</span>'
  };

  const paymentBadge = {
    'pending': '<span class="badge badge-warning">Paiement en attente</span>',
    'completed': '<span class="badge badge-success">Payé</span>',
    'failed': '<span class="badge badge-danger">Paiement échoué</span>',
    'refunded': '<span class="badge badge-secondary">Remboursé</span>'
  };

  const courseTypeLabel = {
    'online': '💻 En ligne',
    'physical': '📍 Présentiel'
  };

  return `
    <div class="course-card">
      <div class="course-thumbnail">
        ${enrollment.thumbnail_url
          ? `<img src="${enrollment.thumbnail_url}" alt="${enrollment.title}">`
          : '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:60px;">🎓</div>'}
        ${enrollment.course_type ? `<div class="course-type-badge">${courseTypeLabel[enrollment.course_type] || enrollment.course_type}</div>` : ''}
      </div>
      <div class="course-info">
        <h3>${enrollment.title || 'Cours'}</h3>
        <p>${truncate(enrollment.description || '', 100)}</p>
        <div class="course-meta">
          ${statusBadge[enrollment.status] || ''}
          ${enrollment.payment_status ? paymentBadge[enrollment.payment_status] : ''}
        </div>
        ${showProgress ? `
          <div class="progress-bar">
            <div class="progress-fill" style="width: ${enrollment.progress_percentage || 0}%"></div>
          </div>
          <p class="progress-text">${enrollment.progress_percentage || 0}% complété</p>
        ` : ''}
      </div>
      <div class="course-actions">
        ${enrollment.status === 'approved' && enrollment.course_type === 'online' && (enrollment.payment_status === 'completed' || !enrollment.price || enrollment.price === 0)
          ? `<a href="course-content.html?id=${enrollment.course_id}" class="btn btn-primary btn-sm">Continuer</a>`
          : enrollment.status === 'approved' && enrollment.course_type === 'physical'
            ? '<span class="badge badge-success">Inscrit - Cours en présentiel</span>'
            : enrollment.status === 'approved' && enrollment.payment_status === 'pending' && enrollment.price > 0
              ? `<a href="checkout.html?course=${enrollment.course_id}&enrollment=${enrollment.id}" class="btn btn-warning btn-sm">Payer</a>`
              : enrollment.status === 'pending'
                ? '<span style="color: var(--color-text-light); font-size: var(--font-size-sm);">Inscription en attente</span>'
                : enrollment.status === 'rejected'
                  ? '<span style="color: var(--color-secondary); font-size: var(--font-size-sm);">Inscription refusée</span>'
                  : '<span style="color: var(--color-text-light); font-size: var(--font-size-sm);">Accès en attente</span>'}
      </div>
    </div>
  `;
}

async function loadAvailableCourses() {
  try {
    const response = await api.getCourses();
    if (response.success) {
      allCourses = response.data;
      filterBrowseCourses();
    }
  } catch (error) {
    console.error('Erreur chargement cours:', error);
  }
}

function filterBrowseCourses() {
  const container = document.getElementById('browse-courses-container');

  if (allCourses.length === 0) {
    container.innerHTML = '<p class="empty-state">Aucun cours disponible pour le moment</p>';
    return;
  }

  let filtered = allCourses;

  if (currentBrowseFilter === 'online') {
    filtered = allCourses.filter(c => c.course_type === 'online');
  } else if (currentBrowseFilter === 'physical') {
    filtered = allCourses.filter(c => c.course_type === 'physical');
  } else if (currentBrowseFilter === 'free') {
    filtered = allCourses.filter(c => !c.price || c.price === 0);
  }

  if (filtered.length === 0) {
    const filterLabels = {
      'online': 'en ligne',
      'physical': 'présentiel',
      'free': 'gratuits'
    };
    container.innerHTML = `<p class="empty-state">Aucun cours ${filterLabels[currentBrowseFilter]} disponible</p>`;
    return;
  }

  container.innerHTML = filtered.map(course => createCourseCard(course)).join('');
}

function createCourseCard(course) {
  const levelBadge = {
    'debutant': '🟢 Débutant',
    'intermediaire': '🟡 Intermédiaire',
    'avance': '🔴 Avancé'
  };

  const courseTypeLabel = {
    'online': '💻 En ligne',
    'physical': '📍 Présentiel'
  };

  return `
    <div class="course-card">
      <div class="course-thumbnail">
        ${course.thumbnail_url
          ? `<img src="${course.thumbnail_url}" alt="${course.title}">`
          : '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:60px;">🎓</div>'}
        ${course.course_type ? `<div class="course-type-badge">${courseTypeLabel[course.course_type] || course.course_type}</div>` : ''}
      </div>
      <div class="course-info">
        <h3>${course.title}</h3>
        <p>${truncate(course.description, 100)}</p>
        <div class="course-meta">
          <span class="badge badge-primary">${levelBadge[course.level] || course.level}</span>
          ${course.duration ? `<span class="badge badge-secondary">⏱️ ${course.duration}</span>` : ''}
          ${course.price > 0
            ? `<span class="badge badge-success">💰 ${course.price} USD</span>`
            : '<span class="badge badge-success">✨ Gratuit</span>'}
        </div>
      </div>
      <div class="course-actions">
        <a href="course-details.html?id=${course.id}" class="btn btn-primary btn-sm">Voir détails</a>
      </div>
    </div>
  `;
}

function setupNavigation() {
  const navItems = document.querySelectorAll('.nav-item[data-section]');

  navItems.forEach(item => {
    item.addEventListener('click', (e) => {
      e.preventDefault();
      const section = item.dataset.section;

      navItems.forEach(nav => nav.classList.remove('active'));
      item.classList.add('active');

      const titles = {
        'overview': 'Vue d\'ensemble',
        'my-courses': 'Mes cours',
        'browse-courses': 'Parcourir les cours',
        'profile': 'Mon profil'
      };
      document.getElementById('section-title').textContent = titles[section] || section;

      document.querySelectorAll('.content-section').forEach(sec => {
        sec.classList.remove('active');
      });
      document.getElementById(`${section}-section`).classList.add('active');
    });
  });

  const profileForm = document.getElementById('profile-form');
  if (profileForm) {
    profileForm.addEventListener('submit', handleProfileUpdate);
  }

  const passwordForm = document.getElementById('password-form');
  if (passwordForm) {
    passwordForm.addEventListener('submit', handlePasswordChange);
  }
}

function setupFilters() {
  // My Courses filters
  const myCoursesFilters = document.querySelectorAll('#my-courses-section .tab');
  myCoursesFilters.forEach(tab => {
    tab.addEventListener('click', () => {
      myCoursesFilters.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      currentMyCoursesFilter = tab.dataset.filter;
      filterMyCourses();
    });
  });

  // Browse Courses filters
  const browseFilters = document.querySelectorAll('#browse-courses-section .tab');
  browseFilters.forEach(tab => {
    tab.addEventListener('click', () => {
      browseFilters.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      currentBrowseFilter = tab.dataset.filter;
      filterBrowseCourses();
    });
  });
}

async function handleProfileUpdate(e) {
  e.preventDefault();

  const data = {
    full_name: document.getElementById('profile-full-name').value,
    phone_number: document.getElementById('profile-phone').value,
    date_of_birth: document.getElementById('profile-dob').value
  };

  try {
    const response = await api.updateStudentProfile(data);
    if (response.success) {
      showNotification('Profil mis à jour avec succès', 'success');
      await loadUserProfile();
    }
  } catch (error) {
    showNotification('Erreur lors de la mise à jour du profil', 'error');
  }
}

async function handlePasswordChange(e) {
  e.preventDefault();

  const currentPassword = document.getElementById('current-password').value;
  const newPassword = document.getElementById('new-password').value;
  const confirmPassword = document.getElementById('confirm-password').value;

  if (newPassword !== confirmPassword) {
    showNotification('Les mots de passe ne correspondent pas', 'error');
    return;
  }

  try {
    const response = await api.changePassword(currentPassword, newPassword);
    if (response.success) {
      showNotification('Mot de passe changé avec succès', 'success');
      e.target.reset();
    }
  } catch (error) {
    showNotification(error.message || 'Erreur lors du changement de mot de passe', 'error');
  }
}

function setupLogout() {
  document.getElementById('logout-btn').addEventListener('click', (e) => {
    e.preventDefault();
    api.logout();
    window.location.href = '../index.html';
  });
}

// Setup mobile menu toggle
function setupMobileMenu() {
  const menuToggle = document.querySelector('.mobile-menu-toggle');
  const sidebar = document.querySelector('.student-sidebar');

  if (!menuToggle || !sidebar) return;

  menuToggle.addEventListener('click', () => {
    sidebar.classList.toggle('active');
  });

  // Close sidebar when clicking on a nav item (mobile only)
  const navItems = document.querySelectorAll('.nav-item');
  navItems.forEach(item => {
    item.addEventListener('click', () => {
      if (window.innerWidth <= 992) {
        sidebar.classList.remove('active');
      }
    });
  });
}
