// ============================================
// PROGRAMMES PAGE SCRIPT
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  initNavigation();
  loadAllPrograms();
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

// Load all programs
async function loadAllPrograms() {
  try {
    const response = await api.getPrograms();
    if (response.success) {
      renderPrograms(response.data);
    }
  } catch (error) {
    console.error('Erreur chargement programmes:', error);
    showNotification('Erreur lors du chargement des programmes', 'error');
  }
}

function renderPrograms(programs) {
  const container = document.getElementById('programmes-grid');
  container.innerHTML = '';

  if (programs.length === 0) {
    container.innerHTML = '<p class="text-center text-gray">Aucun programme disponible pour le moment</p>';
    return;
  }

  programs.forEach(program => {
    const card = document.createElement('article');
    card.className = 'programme-card';
    card.innerHTML = `
      ${program.category ? `<span class="programme-category">${program.category}</span>` : ''}
      <h3>${program.title}</h3>
      <p class="programme-description">${program.description}</p>
      <div class="programme-meta">
        <span class="text-gray">Voir les détails</span>
        <a href="../index.html#programmes" class="programme-link">En savoir plus →</a>
      </div>
    `;
    container.appendChild(card);
  });
}
