// ============================================
// SPONSORS PAGE SCRIPT
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  initNavigation();
  loadAllSponsors();
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

// Load all sponsors
async function loadAllSponsors() {
  try {
    const response = await api.getSponsors();
    if (response.success) {
      renderSponsors(response.data);
    }
  } catch (error) {
    console.error('Erreur chargement sponsors:', error);
    showNotification('Erreur lors du chargement des sponsors', 'error');
  }
}

function renderSponsors(sponsors) {
  if (sponsors.length === 0) {
    document.getElementById('no-sponsors').style.display = 'block';
    return;
  }

  // Group sponsors by tier
  const grouped = {
    platinum: sponsors.filter(s => s.tier === 'platinum'),
    gold: sponsors.filter(s => s.tier === 'gold'),
    silver: sponsors.filter(s => s.tier === 'silver'),
    bronze: sponsors.filter(s => s.tier === 'bronze')
  };

  // Render each tier
  Object.keys(grouped).forEach(tier => {
    const tierSponsors = grouped[tier];
    if (tierSponsors.length > 0) {
      const sectionId = `${tier}-section`;
      const containerId = `${tier}-sponsors`;

      document.getElementById(sectionId).style.display = 'block';
      const container = document.getElementById(containerId);
      container.innerHTML = '';

      tierSponsors.forEach(sponsor => {
        const card = document.createElement('div');
        card.className = `sponsor-card ${tier}`;

        card.innerHTML = `
          <img src="${sponsor.logo_url}" alt="${sponsor.name}" class="sponsor-logo" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 200 100%27%3E%3Crect fill=%27%23e0e0e0%27 width=%27200%27 height=%27100%27/%3E%3Ctext x=%27100%27 y=%2755%27 text-anchor=%27middle%27 fill=%27%23999%27 font-size=%2720%27%3E${sponsor.name}%3C/text%3E%3C/svg%3E'">
          <h3 class="sponsor-name">${sponsor.name}</h3>
          ${sponsor.description ? `<p class="sponsor-description">${sponsor.description}</p>` : ''}
          ${sponsor.website_url ? `<a href="${sponsor.website_url}" target="_blank" rel="noopener noreferrer" class="sponsor-website">🔗 Visiter le site</a>` : ''}
        `;

        container.appendChild(card);
      });
    }
  });
}
