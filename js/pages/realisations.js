// ============================================
// RÉALISATIONS — liste, fiche et sélection de l'accueil
// ============================================

const PROJECT_TYPES = { formation: 'Formation', produit: 'Produit', mission: 'Mission', autre: 'Autre' };
const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

function projectMonth(date) {
  const [year, month] = date.split('-');
  return `${MONTHS[Number(month) - 1]} ${year}`;
}

function projectPeriod(project) {
  if (project.period_label) return project.period_label;
  if (!project.start_date) return '';
  const start = projectMonth(project.start_date);
  const text = project.end_date ? `${start} - ${projectMonth(project.end_date)}` : start;
  return text.charAt(0).toUpperCase() + text.slice(1);
}

function projectHref(project, base) {
  // Essor a sa propre page, plus complète qu'une fiche
  return project.slug === 'essor' ? `${base}essor.html` : `${base}realisation.html?p=${encodeURIComponent(project.slug)}`;
}

function projectCard(project, base) {
  const period = projectPeriod(project);
  return `
    <article class="card card-link fade-in">
      <span class="tag">${PROJECT_TYPES[project.type] || 'Autre'}</span>
      <h3>${escapeHtml(project.name)}</h3>
      ${period ? `<p class="card-meta"><i class="ti ti-calendar" aria-hidden="true"></i> ${escapeHtml(period)}</p>` : ''}
      <p>${escapeHtml(project.summary)}</p>
      <a class="more" href="${projectHref(project, base)}">Plus d'infos<span class="sr-only"> sur ${escapeHtml(project.name)}</span></a>
    </article>`;
}

function showProjectError(container, message) {
  container.innerHTML = `<p class="state-message">${escapeHtml(message)}</p>`;
}

// ---------- Liste ----------

async function loadProjectList() {
  const container = document.getElementById('projects-grid');
  const filters = document.getElementById('project-filters');

  try {
    const { data: projects } = await api.request('/projects');
    if (projects.length === 0) {
      showProjectError(container, 'Aucune réalisation à afficher pour le moment.');
      return;
    }

    const render = (type) => {
      const shown = type ? projects.filter(project => project.type === type) : projects;
      container.innerHTML = shown.map(project => projectCard(project, '')).join('');
    };

    const types = Object.keys(PROJECT_TYPES).filter(type => projects.some(project => project.type === type));
    filters.innerHTML = [['', 'Tout'], ...types.map(type => [type, `${PROJECT_TYPES[type]}s`])].map(([value, label]) =>
      `<button type="button" class="filter${value === '' ? ' is-active' : ''}" aria-pressed="${value === ''}" data-type="${value}">${label}</button>`
    ).join('');
    filters.hidden = false;

    filters.addEventListener('click', (event) => {
      const button = event.target.closest('[data-type]');
      if (!button) return;
      filters.querySelectorAll('[data-type]').forEach((other) => {
        other.classList.toggle('is-active', other === button);
        other.setAttribute('aria-pressed', String(other === button));
      });
      render(button.dataset.type);
    });

    render('');
  } catch (error) {
    showProjectError(container, 'Les réalisations ne peuvent pas être affichées pour le moment. Réessayez dans un instant.');
  }
}

// ---------- Fiche ----------

async function loadProjectDetail() {
  const container = document.getElementById('project-detail');
  const slug = new URLSearchParams(window.location.search).get('p');
  const notFound = () => {
    document.title = 'Réalisation introuvable | DevDynamics';
    document.getElementById('project-title').textContent = 'Réalisation introuvable';
    document.getElementById('project-crumb').textContent = 'Introuvable';
    showProjectError(container, "Cette réalisation n'existe pas ou n'est plus publiée.");
  };

  if (!slug) {
    notFound();
    return;
  }

  try {
    const { data: project } = await api.request(`/projects/${encodeURIComponent(slug)}`);
    const period = projectPeriod(project);
    const facts = [
      ['ti-category', 'Type', PROJECT_TYPES[project.type] || 'Autre'],
      ['ti-calendar', 'Période', period],
      ['ti-users', 'Participants', project.participants === null ? '' : String(project.participants)],
      ['ti-user-star', 'Intervenants', project.speakers || ''],
    ].filter(([, , value]) => value);

    document.title = `${project.name} | DevDynamics`;
    document.getElementById('project-title').textContent = project.name;
    document.getElementById('project-crumb').textContent = project.name;
    document.getElementById('project-lead').textContent = project.summary;

    container.innerHTML = `
      <div class="detail-grid fade-in">
        <div class="reading">
          ${project.image_url ? `<img class="detail-image" src="../${escapeHtml(project.image_url)}" alt="">` : ''}
          ${(project.description || '').split(/\n{2,}/).filter(Boolean).map(text => `<p>${escapeHtml(text)}</p>`).join('')}
          ${project.external_url ? `<a class="btn btn-outline" href="${escapeHtml(project.external_url)}" target="_blank" rel="noopener">Visiter le site <i class="ti ti-external-link" aria-hidden="true"></i></a>` : ''}
        </div>
        <aside class="card facts">
          <h2>En bref</h2>
          <dl>
            ${facts.map(([icon, label, value]) => `
              <div><dt><i class="ti ${icon}" aria-hidden="true"></i> ${label}</dt><dd>${escapeHtml(value)}</dd></div>
            `).join('')}
          </dl>
        </aside>
      </div>`;
    loadProjectTestimonials(slug, container);
  } catch (error) {
    if (error.status === 404) {
      notFound();
    } else {
      showProjectError(container, 'Cette réalisation ne peut pas être affichée pour le moment. Réessayez dans un instant.');
    }
  }
}

async function loadProjectTestimonials(slug, container) {
  try {
    const { data } = await api.request(`/project-testimonials/project/${encodeURIComponent(slug)}`);
    if (data.length === 0) return;
    container.insertAdjacentHTML('beforeend', `
      <div class="detail-section">
        <h2>Témoignages</h2>
        <div class="grid">${data.map(item => testimonialCard(item)).join('')}</div>
      </div>`);
  } catch (error) {
    // Les témoignages accompagnent la fiche : leur absence ne l'empêche pas de s'afficher
  }
}

// ---------- Accueil ----------

async function loadHomeProjects() {
  const container = document.getElementById('home-projects');
  try {
    const { data: projects } = await api.request('/projects');
    if (projects.length === 0) {
      container.closest('section').hidden = true;
      return;
    }
    container.innerHTML = projects.slice(0, 3).map(project => projectCard(project, 'pages/')).join('');
  } catch (error) {
    container.closest('section').hidden = true;
  }
}

if (document.getElementById('projects-grid')) loadProjectList();
if (document.getElementById('project-detail')) loadProjectDetail();
if (document.getElementById('home-projects')) loadHomeProjects();
