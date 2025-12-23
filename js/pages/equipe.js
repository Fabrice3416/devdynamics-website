// ============================================
// EQUIPE PAGE SCRIPT
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  initNavigation();
  loadAllTeamMembers();
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

// Load all team members
async function loadAllTeamMembers() {
  try {
    const response = await api.getTeamMembers();
    if (response.success) {
      renderTeamMembers(response.data);
    }
  } catch (error) {
    console.error('Erreur chargement équipe:', error);
    showNotification('Erreur lors du chargement de l\'équipe', 'error');
  }
}

function renderTeamMembers(teamMembers) {
  const container = document.getElementById('equipe-grid');
  container.innerHTML = '';

  if (teamMembers.length === 0) {
    container.innerHTML = '<p class="text-center text-gray">Aucun membre de l\'équipe disponible pour le moment</p>';
    return;
  }

  teamMembers.forEach(member => {
    const card = document.createElement('article');
    card.className = 'team-card';

    // Get initials for avatar placeholder
    const initials = member.name
      .split(' ')
      .map(n => n[0])
      .join('')
      .toUpperCase()
      .substring(0, 2);

    // Parse expertise if it's a string
    let expertiseArray = [];
    if (member.expertise) {
      try {
        expertiseArray = typeof member.expertise === 'string'
          ? JSON.parse(member.expertise)
          : member.expertise;
      } catch (e) {
        expertiseArray = member.expertise.split(',').map(e => e.trim());
      }
    }

    card.innerHTML = `
      <div class="team-card-image">
        ${member.photo
          ? `<img src="${member.photo}" alt="${member.name}">`
          : `<div class="team-card-image-placeholder">${initials}</div>`
        }
      </div>
      <div class="team-card-content">
        <h3>${member.name}</h3>
        <p class="team-role">${member.role || 'Membre de l\'équipe'}</p>
        ${member.bio ? `<p class="team-bio">${member.bio}</p>` : ''}
        ${expertiseArray.length > 0 ? `
          <div class="team-expertise">
            ${expertiseArray.map(exp => `<span class="expertise-tag">${exp}</span>`).join('')}
          </div>
        ` : ''}
      </div>
    `;
    container.appendChild(card);
  });
}
