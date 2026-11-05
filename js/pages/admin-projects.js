// ============================================
// ADMIN — Projets
// ============================================

const PROJECT_TYPE_LABELS = { formation: 'Formation', produit: 'Produit', mission: 'Mission', autre: 'Autre' };
const PROJECT_STATUS_LABELS = { draft: 'Brouillon', published: 'Publié', hidden: 'Masqué' };
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
              <td><span class="badge badge-${project.status === 'published' ? 'success' : 'secondary'}">${PROJECT_STATUS_LABELS[project.status] || escapeHtml(project.status)}</span></td>
              <td>
                <div class="action-buttons">
                  <button class="btn btn-primary btn-sm" onclick="editProject(${Number(project.id)})">Modifier</button>
                  ${project.status === 'published'
                    ? `<button class="btn btn-secondary btn-sm" onclick="setProjectStatus(${Number(project.id)}, 'hidden')">Masquer du site</button>`
                    : `<button class="btn btn-secondary btn-sm" onclick="setProjectStatus(${Number(project.id)}, 'published')">Afficher sur le site</button>`}
                  <button class="btn btn-secondary btn-sm" onclick="toggleTestimonials(${Number(project.id)})">${Number(project.testimonials_open) ? 'Fermer la collecte' : 'Ouvrir la collecte'}</button>
                  ${Number(project.testimonials_open) ? `<button class="btn btn-secondary btn-sm" onclick="copyTestimonialLink(${Number(project.id)}, this)">Lien témoignage</button>` : ''}
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

async function setProjectStatus(id, status) {
  try {
    await api.request(`/projects/${id}/status`, { method: 'PUT', body: JSON.stringify({ status }) });
    loadProjects();
  } catch (error) {
    alert(error.message || 'Le changement a échoué.');
  }
}

async function toggleTestimonials(id) {
  try {
    await api.request(`/projects/${id}/testimonials`, {
      method: 'PUT',
      body: JSON.stringify({ open: !Number(projectsById[id].testimonials_open) })
    });
    loadProjects();
  } catch (error) {
    alert(error.message || 'Le changement a échoué.');
  }
}

async function copyTestimonialLink(id, button) {
  const link = `${window.location.origin}/pages/temoignage.html?p=${encodeURIComponent(projectsById[id].slug)}`;
  try {
    await navigator.clipboard.writeText(link);
    button.textContent = 'Lien copié';
  } catch (error) {
    prompt('Copie ce lien :', link);
  }
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

document.getElementById('project-image-file').addEventListener('change', async (event) => {
  const file = event.target.files[0];
  if (!file) return;

  const state = document.getElementById('project-image-state');
  const save = document.getElementById('project-save');
  state.textContent = 'Envoi de l\'image…';
  save.disabled = true;

  try {
    document.getElementById('project-image').value = await uploadAdminImage(file);
    state.textContent = 'Image envoyée.';
  } catch (error) {
    event.target.value = '';
    state.textContent = error.message;
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
