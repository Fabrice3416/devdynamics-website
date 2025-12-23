// ============================================
// COURS PAGE SCRIPT
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  initNavigation();
  loadAllCourses();
});

// Navigation
function initNavigation() {
  const hamburger = document.querySelector('.hamburger');
  const navMenu = document.querySelector('.nav-menu');
  const navLinks = document.querySelectorAll('.nav-link');

  hamburger.addEventListener('click', () => {
    navMenu.classList.toggle('active');
  });

  navLinks.forEach(link => {
    link.addEventListener('click', () => {
      navMenu.classList.remove('active');
    });
  });
}

// Load all courses
async function loadAllCourses() {
  try {
    const response = await api.getCourses();
    if (response.success) {
      renderCourses(response.data);
    }
  } catch (error) {
    console.error('Erreur chargement cours:', error);
    showNotification('Erreur lors du chargement des cours', 'error');
  }
}

function renderCourses(courses) {
  const container = document.getElementById('cours-grid');
  container.innerHTML = '';

  if (courses.length === 0) {
    container.innerHTML = '<p class="text-center text-gray">Aucun cours disponible pour le moment</p>';
    return;
  }

  courses.forEach(course => {
    const card = document.createElement('article');
    card.className = 'cours-card';

    const courseTypeLabel = course.course_type === 'online' ? 'En ligne' : 'Présentiel';
    const courseTypeClass = course.course_type === 'online' ? 'online' : 'physical';

    card.innerHTML = `
      <div class="cours-card-content">
        <span class="cours-type ${courseTypeClass}">${courseTypeLabel}</span>
        <h3>${course.title}</h3>
        <p class="cours-description">${course.description}</p>
        <div class="cours-meta">
          ${course.duration ? `<div class="cours-meta-item">⏱️ ${course.duration}</div>` : ''}
          ${course.level ? `<div class="cours-meta-item">${course.level}</div>` : ''}
          ${course.price && parseFloat(course.price) > 0 ? `<div class="cours-meta-item">${course.price} HTG</div>` : '<div class="cours-meta-item">Gratuit</div>'}
        </div>
        <div class="cours-footer">
          <span class="text-gray">Inscrivez-vous</span>
          <a href="student-login.html" class="cours-link">S'inscrire →</a>
        </div>
      </div>
    `;
    container.appendChild(card);
  });
}
