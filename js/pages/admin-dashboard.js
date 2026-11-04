// ============================================
// ADMINISTRATION — navigation, tableau de bord, utilisateurs
// ============================================

// Avant l'affichage : une session invalide ne doit rien voir de l'interface
(function() {
  if (!sessionAdminValide()) {
    renvoyerVersConnexion();
    throw new Error('Access denied - redirecting');
  }
})();

const PAGE_TITLES = {
  dashboard: 'Tableau de bord',
  projects: 'Projets',
  forms: 'Formulaires',
  testimonials: 'Témoignages',
  contact: 'Messages',
  partners: 'Partenaires',
  organization: 'Organisation',
  presence: 'Feuille de présence',
  users: 'Utilisateurs'
};

const PAGE_LOADERS = {
  dashboard: () => loadDashboard(),
  projects: () => loadProjects(),
  forms: () => loadForms(),
  testimonials: () => loadProjectTestimonials(),
  contact: () => loadContactMessages(),
  partners: () => loadPartners(),
  organization: () => loadOrganizationForm(),
  presence: () => loadPresence(),
  users: () => loadUsers()
};

function escapeHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function setSidebarOpen(open) {
  document.getElementById('admin-sidebar').classList.toggle('active', open);
  document.getElementById('sidebar-backdrop').hidden = !open;
  const toggle = document.querySelector('.mobile-menu-toggle');
  toggle.setAttribute('aria-expanded', String(open));
  toggle.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
}

function switchPage(page) {
  if (!PAGE_TITLES[page]) page = 'dashboard';

  document.querySelectorAll('.page-content').forEach(section => {
    section.classList.toggle('active', section.id === `${page}-page`);
  });
  document.querySelectorAll('.menu-item[data-page]').forEach((item) => {
    const active = item.dataset.page === page;
    item.classList.toggle('active', active);
    if (active) item.setAttribute('aria-current', 'page');
    else item.removeAttribute('aria-current');
  });
  document.getElementById('page-title').textContent = PAGE_TITLES[page];
  document.title = `${PAGE_TITLES[page]} | Administration DevDynamics`;
  setSidebarOpen(false);
  PAGE_LOADERS[page]();
}

// ---------- Tableau de bord ----------

async function loadDashboard() {
  const container = document.getElementById('dashboard-stats');
  const tiles = [
    ['messages_new', 'Messages non lus', 'contact'],
    ['testimonials_pending', 'Témoignages en attente', 'testimonials'],
    ['forms_open', 'Formulaires ouverts', 'forms'],
    ['responses_week', 'Réponses sur 7 jours', 'forms'],
    ['projects_published', 'Projets publiés', 'projects']
  ];

  try {
    const { data } = await api.request('/admin/dashboard/stats');
    container.innerHTML = tiles.map(([key, label, page]) => `
      <button type="button" class="stat-card" onclick="switchPage('${page}')">
        <span class="stat-value">${data[key] === null ? '-' : Number(data[key])}</span>
        <span class="stat-label">${label}</span>
      </button>`).join('');
  } catch (error) {
    container.textContent = error.message || 'Lecture impossible. Recharge la page.';
  }
}

// ---------- Utilisateurs ----------

async function loadUsers() {
  const container = document.getElementById('users-list');
  container.textContent = 'Chargement…';

  try {
    const { data } = await api.request('/admin/users');
    const current = getStorage('user');

    // Les comptes étudiants sont retirés avec les cours : ils ne s'administrent plus ici
    const users = data.filter(user => user.role !== 'student');

    container.innerHTML = `
      <table class="table-stack">
        <thead><tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>Actions</th></tr></thead>
        <tbody>
          ${users.map(user => `
            <tr>
              <td data-label="Nom">${escapeHtml(user.full_name)}</td>
              <td data-label="E-mail">${escapeHtml(user.email)}</td>
              <td data-label="Rôle"><span class="badge badge-primary">${escapeHtml(user.role)}</span></td>
              <td>${current && current.id === user.id ? 'Votre compte' : `<button class="btn btn-secondary btn-sm" onclick="deleteUser(${Number(user.id)})">Supprimer</button>`}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>`;
  } catch (error) {
    container.textContent = error.message || 'Lecture impossible. Recharge la page.';
  }
}

async function deleteUser(id) {
  if (!confirm('Supprimer définitivement ce compte ?')) return;
  try {
    await api.request(`/admin/users/${id}`, { method: 'DELETE' });
    loadUsers();
  } catch (error) {
    alert(error.message || 'La suppression a échoué.');
  }
}

// ---------- Démarrage ----------

document.addEventListener('DOMContentLoaded', () => {
  const user = getStorage('user');
  if (user) document.getElementById('user-name').textContent = user.full_name;

  document.querySelectorAll('.menu-item[data-page]').forEach((item) => {
    item.addEventListener('click', (event) => {
      event.preventDefault();
      history.replaceState(null, '', `#${item.dataset.page}`);
      switchPage(item.dataset.page);
    });
  });

  document.querySelector('.mobile-menu-toggle').addEventListener('click', () => {
    setSidebarOpen(!document.getElementById('admin-sidebar').classList.contains('active'));
  });
  document.getElementById('sidebar-backdrop').addEventListener('click', () => setSidebarOpen(false));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setSidebarOpen(false);
  });

  document.getElementById('logout-btn').addEventListener('click', () => {
    api.logout();
    removeStorage('user');
    window.location.href = 'admin-login.html';
  });

  switchPage(window.location.hash.slice(1) || 'dashboard');
});
