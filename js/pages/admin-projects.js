// ============================================
// ADMIN — Projets
// ============================================

const PROJECT_TYPE_LABELS = { formation: 'Formation', produit: 'Produit', mission: 'Mission', autre: 'Autre' };
let projectsById = {};

async function loadProjects() {
  const container = document.getElementById('projects-list');
  container.textContent = 'Chargement…';

  try {
    const response = await api.request('/projects/all');
    projectsById = Object.fromEntries(response.data.map(project => [project.id, project]));

    container.innerHTML = response.data.length === 0 ? '<p style="padding:var(--spacing-md);">Aucun projet pour le moment.</p>' : `
      <table>
        <thead>
          <tr>
            <th>Nom</th>
            <th>Type</th>
            <th>Période</th>
            <th>Participants</th>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          ${response.data.map(project => `
            <tr>
              <td>${escapeHtml(project.name)}</td>
              <td>${PROJECT_TYPE_LABELS[project.type] || escapeHtml(project.type)}</td>
              <td>${escapeHtml(project.period_label || project.start_date || '-')}</td>
              <td>${project.participants === null ? '-' : escapeHtml(project.participants)}</td>
              <td><span class="badge badge-${project.status === 'published' ? 'success' : 'secondary'}">${project.status === 'published' ? 'Publié' : 'Brouillon'}</span></td>
              <td>
                <div class="action-buttons">
                  <button class="btn btn-primary btn-sm" onclick="editProject(${Number(project.id)})">Modifier</button>
                  <button class="btn btn-secondary btn-sm" onclick="deleteProject(${Number(project.id)})">Supprimer</button>
                </div>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  } catch (error) {
    container.textContent = error.message || 'Lecture impossible. Recharge la page.';
  }
}

function openProjectForm(project) {
  const form = document.getElementById('project-form');
  form.reset();
  form.dataset.id = project ? project.id : '';
  document.getElementById('project-modal-title').textContent = project ? 'Modifier le projet' : 'Ajouter un projet';

  for (const field of ['name', 'type', 'status', 'start_date', 'end_date', 'period_label', 'summary', 'description', 'participants', 'speakers', 'external_url', 'image_url']) {
    if (project && project[field] !== null && project[field] !== undefined) {
      form.elements[field].value = project[field];
    } else if (field === 'image_url') {
      form.elements[field].value = '';
    }
  }
  document.getElementById('project-image-state').textContent = project && project.image_url ? 'Une image est enregistrée.' : '';
  openModal('project-modal');
}

function editProject(id) {
  openProjectForm(projectsById[id]);
}

async function deleteProject(id) {
  if (!confirm(`Supprimer définitivement « ${projectsById[id].name} » ?`)) return;

  try {
    await api.request(`/projects/${id}`, { method: 'DELETE' });
    loadProjects();
  } catch (error) {
    alert(error.message || 'La suppression a échoué.');
  }
}

document.getElementById('add-project-btn').addEventListener('click', () => openProjectForm(null));

// L'envoi passe par fetch sans Content-Type : le navigateur doit poser lui-même
// la frontière du multipart.
document.getElementById('project-image-file').addEventListener('change', async (event) => {
  const file = event.target.files[0];
  if (!file) return;

  const state = document.getElementById('project-image-state');
  const save = document.getElementById('project-save');
  state.textContent = 'Envoi de l\'image…';
  save.disabled = true;

  try {
    const body = new FormData();
    body.append('file', file);
    const response = await fetch(`${API_BASE_URL}/uploads`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${api.token}` },
      body
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.message);

    document.getElementById('project-image').value = result.data.path;
    state.textContent = 'Image envoyée.';
  } catch (error) {
    event.target.value = '';
    state.textContent = error.message || "L'envoi de l'image a échoué.";
  } finally {
    save.disabled = false;
  }
});

document.getElementById('project-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.target;
  const data = Object.fromEntries(new FormData(form).entries());
  const id = form.dataset.id;

  try {
    await api.request(id ? `/projects/${id}` : '/projects', {
      method: id ? 'PUT' : 'POST',
      body: JSON.stringify(data)
    });
    closeModal('project-modal');
    loadProjects();
  } catch (error) {
    alert(error.message || "L'enregistrement a échoué.");
  }
});
