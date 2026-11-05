// ============================================
// ADMIN — Formulaires : liste, constructeur, réponses et graphiques
// ============================================

const FIELD_TYPES = {
  text: 'Texte court',
  textarea: 'Texte long',
  number: 'Nombre',
  date: 'Date',
  single: 'Choix unique',
  multiple: 'Choix multiple',
  select: 'Liste déroulante',
  scale: 'Échelle de 1 à 5',
  yesno: 'Oui / non'
};
const OPTION_TYPES = ['single', 'multiple', 'select'];
const CHART_TYPES = ['single', 'multiple', 'select', 'scale', 'yesno', 'number'];
const FORM_STATUS = { draft: 'Brouillon', open: 'Ouvert', suspended: 'Suspendu' };
// Sarcelles et corail de la charte, lisibles sur fond clair comme sombre
const PIE_COLORS = ['#008080', '#FF6B6B', '#4DB6B6', '#B3541E', '#7A9E9F', '#E0A030', '#5C6BC0', '#8D6E63'];

let formsList = [];
let builderFields = [];
let builderFormId = null;
let builderHasResponses = false;

function formsContainer() { return document.getElementById('forms-content'); }

function setFormsHeader(title, actionsHtml) {
  document.getElementById('forms-heading').textContent = title;
  document.getElementById('forms-actions').innerHTML = actionsHtml || '';
}

function formPublicLink(form) {
  return `${window.location.origin}/pages/formulaire.html?f=${encodeURIComponent(form.slug)}`;
}

// ---------- Liste ----------

async function loadForms() {
  setFormsHeader('Formulaires', '<button type="button" class="btn btn-primary" onclick="openFormBuilder(null)"><i class="ti ti-plus"></i> Créer un formulaire</button>');
  const container = formsContainer();
  container.textContent = 'Chargement…';

  try {
    const { data } = await api.request('/forms');
    formsList = data;

    container.innerHTML = data.length === 0 ? '<div class="fb-card">Aucun formulaire pour le moment.</div>' : `
      <div class="table-container">
        <table>
          <thead>
            <tr><th>Titre</th><th>Projet</th><th>État</th><th>Réponses</th><th>Actions</th></tr>
          </thead>
          <tbody>
            ${data.map(form => `
              <tr>
                <td>${escapeHtml(form.title)}</td>
                <td>${escapeHtml(form.project_name)}</td>
                <td><span class="badge badge-${form.status === 'open' ? 'success' : 'secondary'}">${FORM_STATUS[form.status] || escapeHtml(form.status)}</span></td>
                <td>${Number(form.response_count)}</td>
                <td>
                  <div class="action-buttons">
                    <button class="btn btn-primary btn-sm" onclick="openFormBuilder(${Number(form.id)})">Modifier</button>
                    <button class="btn btn-primary btn-sm" onclick="openFormResults(${Number(form.id)})">Réponses</button>
                    ${form.status === 'open'
                      ? `<button class="btn btn-secondary btn-sm" onclick="setFormStatus(${Number(form.id)}, 'suspended')">Suspendre</button>
                         <button class="btn btn-secondary btn-sm" onclick="copyFormLink(${Number(form.id)}, this)">Copier le lien</button>`
                      : `<button class="btn btn-primary btn-sm" onclick="setFormStatus(${Number(form.id)}, 'open')">Ouvrir</button>`}
                    <button class="btn btn-secondary btn-sm" onclick="deleteForm(${Number(form.id)})">Supprimer</button>
                  </div>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>`;
  } catch (error) {
    container.textContent = error.message || 'Lecture impossible. Recharge la page.';
  }
}

async function setFormStatus(id, status) {
  try {
    await api.request(`/forms/${id}/status`, { method: 'PUT', body: JSON.stringify({ status }) });
    loadForms();
  } catch (error) {
    alert(error.message || 'Le changement a échoué.');
  }
}

async function copyFormLink(id, button) {
  const link = formPublicLink(formsList.find(form => Number(form.id) === id));
  try {
    await navigator.clipboard.writeText(link);
    button.textContent = 'Lien copié';
  } catch (error) {
    prompt('Copie ce lien :', link);
  }
}

async function deleteForm(id) {
  const form = formsList.find(item => Number(item.id) === id);
  const count = Number(form.response_count);
  const warning = count > 0 ? ` Ses ${count} réponse(s) seront perdues.` : '';
  if (!confirm(`Supprimer définitivement « ${form.title} » ?${warning}`)) return;

  try {
    await api.request(`/forms/${id}`, { method: 'DELETE' });
    loadForms();
  } catch (error) {
    alert(error.message || 'La suppression a échoué.');
  }
}

// ---------- Constructeur ----------

async function openFormBuilder(id) {
  const container = formsContainer();
  container.textContent = 'Chargement…';
  setFormsHeader(id ? 'Modifier le formulaire' : 'Créer un formulaire', '<button type="button" class="btn btn-secondary" onclick="loadForms()">Retour à la liste</button>');

  try {
    const [{ data: projects }, form] = await Promise.all([
      api.request('/projects/all'),
      id ? api.request(`/forms/${id}`).then(response => response.data) : Promise.resolve(null)
    ]);

    if (projects.length === 0) {
      container.innerHTML = '<div class="fb-card">Créez d\'abord un projet : chaque formulaire appartient à un projet.</div>';
      return;
    }

    builderFormId = id;
    builderHasResponses = !!form && form.response_count > 0;
    builderFields = form ? form.fields.map(field => ({
      ...field,
      required: Number(field.required),
      hidden: Number(field.hidden),
      options: field.options || [],
      savedOptions: field.options || [],
      saved: true
    })) : [newBuilderField('text')];

    const closes = form && form.closes_at ? form.closes_at.slice(0, 16).replace(' ', 'T') : '';

    container.innerHTML = `
      <form id="form-builder">
        <div class="fb-card">
          <h3>Informations générales</h3>
          <div class="fb-grid">
            <div class="form-group">
              <label for="fb-project">Projet</label>
              <select id="fb-project" name="project_id" required>
                ${projects.map(project => `<option value="${Number(project.id)}"${form && Number(form.project_id) === Number(project.id) ? ' selected' : ''}>${escapeHtml(project.name)}${project.status === 'published' ? '' : ` (${project.status === 'hidden' ? 'masqué' : 'brouillon'})`}</option>`).join('')}
              </select>
            </div>
            <div class="form-group">
              <label for="fb-closes">Fermeture automatique (heure d'Haïti, facultatif)</label>
              <input type="datetime-local" id="fb-closes" name="closes_at" value="${escapeHtml(closes)}">
            </div>
          </div>
          <div class="form-group">
            <label for="fb-title">Titre</label>
            <input type="text" id="fb-title" name="title" maxlength="200" value="${escapeHtml(form ? form.title : '')}" required>
          </div>
          <div class="form-group">
            <label for="fb-intro">Introduction</label>
            <textarea id="fb-intro" name="intro" rows="3">${escapeHtml(form ? form.intro : '')}</textarea>
          </div>
          <div class="form-group">
            <label for="fb-thanks">Message de remerciement</label>
            <input type="text" id="fb-thanks" name="thanks_message" maxlength="500" value="${escapeHtml(form ? form.thanks_message : '')}">
          </div>
          <label class="fb-inline">
            <input type="checkbox" id="fb-device" name="one_per_device"${!form || Number(form.one_per_device) ? ' checked' : ''}>
            Une seule réponse par appareil
          </label>
          <p class="fb-note">À décocher si plusieurs personnes répondent depuis le même téléphone.</p>
        </div>

        <div id="fb-fields"></div>

        <div class="fb-card fb-actions">
          <select id="fb-new-type" aria-label="Type de la nouvelle question">
            ${Object.entries(FIELD_TYPES).map(([value, label]) => `<option value="${value}">${label}</option>`).join('')}
          </select>
          <button type="button" class="btn btn-secondary" id="fb-add"><i class="ti ti-plus"></i> Ajouter la question</button>
          <button type="submit" class="btn btn-primary">Enregistrer</button>
          ${id ? `<a class="btn btn-secondary" href="formulaire.html?apercu=${Number(id)}" target="_blank" rel="noopener"><i class="ti ti-eye"></i> Aperçu</a>` : ''}
        </div>
      </form>`;

    renderBuilderFields();
    document.getElementById('fb-add').addEventListener('click', () => {
      syncBuilderFields();
      builderFields.push(newBuilderField(document.getElementById('fb-new-type').value));
      renderBuilderFields();
    });
    document.getElementById('form-builder').addEventListener('submit', saveFormBuilder);
  } catch (error) {
    container.textContent = error.message || 'Lecture impossible. Recharge la page.';
  }
}

function newBuilderField(type) {
  return { type, label: '', help_text: '', required: 0, hidden: 0, chart: 'none', options: OPTION_TYPES.includes(type) ? ['', ''] : [], savedOptions: [], saved: false };
}

// Le DOM porte les saisies en cours : à recopier avant tout nouveau rendu
function syncBuilderFields() {
  document.querySelectorAll('[data-fb-index]').forEach((card) => {
    const field = builderFields[Number(card.dataset.fbIndex)];
    const value = (name) => card.querySelector(`[data-fb="${name}"]`);
    field.label = value('label').value;
    field.help_text = value('help').value;
    field.required = value('required').checked ? 1 : 0;
    field.hidden = value('hidden') && value('hidden').checked ? 1 : 0;
    if (!field.saved || !builderHasResponses) field.type = value('type').value;
    field.chart = value('chart') ? value('chart').value : 'none';
    field.options = value('options') ? value('options').value.split('\n').map(option => option.trim()) : [];
  });
}

function renderBuilderFields() {
  document.getElementById('fb-fields').innerHTML = builderFields.map((field, index) => {
    const locked = field.saved && builderHasResponses;
    return `
      <div class="fb-card" data-fb-index="${index}">
        <div class="fb-field-head">
          <strong>Question ${index + 1}</strong>
          <div class="fb-actions">
            <button type="button" class="btn btn-secondary btn-sm" aria-label="Monter" onclick="moveBuilderField(${index}, -1)"${index === 0 ? ' disabled' : ''}><i class="ti ti-arrow-up"></i></button>
            <button type="button" class="btn btn-secondary btn-sm" aria-label="Descendre" onclick="moveBuilderField(${index}, 1)"${index === builderFields.length - 1 ? ' disabled' : ''}><i class="ti ti-arrow-down"></i></button>
            ${locked ? '' : `<button type="button" class="btn btn-secondary btn-sm" onclick="removeBuilderField(${index})">Retirer</button>`}
          </div>
        </div>
        <div class="fb-grid">
          <div class="form-group">
            <label>Type</label>
            <select data-fb="type" onchange="syncBuilderFields(); renderBuilderFields();"${locked ? ' disabled' : ''}>
              ${Object.entries(FIELD_TYPES).map(([value, label]) => `<option value="${value}"${field.type === value ? ' selected' : ''}>${label}</option>`).join('')}
            </select>
          </div>
          ${CHART_TYPES.includes(field.type) ? `
            <div class="form-group">
              <label>Graphique des réponses</label>
              <select data-fb="chart">
                <option value="none"${field.chart === 'none' ? ' selected' : ''}>Aucun</option>
                <option value="bar"${field.chart === 'bar' ? ' selected' : ''}>Barres</option>
                <option value="pie"${field.chart === 'pie' ? ' selected' : ''}>Secteurs</option>
              </select>
            </div>` : ''}
        </div>
        <div class="form-group">
          <label>Question</label>
          <input type="text" data-fb="label" maxlength="500" value="${escapeHtml(field.label)}" required>
        </div>
        <div class="form-group">
          <label>Aide (facultatif)</label>
          <input type="text" data-fb="help" maxlength="500" value="${escapeHtml(field.help_text)}">
        </div>
        ${OPTION_TYPES.includes(field.type) ? `
          <div class="form-group">
            <label>Choix, un par ligne</label>
            <textarea data-fb="options" rows="4">${escapeHtml(field.options.join('\n'))}</textarea>
            ${locked ? '<p class="fb-note">Des réponses existent : vous pouvez ajouter des choix, pas en retirer.</p>' : ''}
          </div>` : ''}
        <label class="fb-inline"><input type="checkbox" data-fb="required"${field.required ? ' checked' : ''}> Obligatoire</label>
        ${field.saved ? `<label class="fb-inline"><input type="checkbox" data-fb="hidden"${field.hidden ? ' checked' : ''}> Masquer cette question</label>` : ''}
      </div>`;
  }).join('');
}

function moveBuilderField(index, step) {
  syncBuilderFields();
  const [field] = builderFields.splice(index, 1);
  builderFields.splice(index + step, 0, field);
  renderBuilderFields();
}

function removeBuilderField(index) {
  syncBuilderFields();
  builderFields.splice(index, 1);
  renderBuilderFields();
}

async function saveFormBuilder(event) {
  event.preventDefault();
  syncBuilderFields();

  const form = event.target;
  const payload = {
    project_id: form.elements.project_id.value,
    title: form.elements.title.value,
    intro: form.elements.intro.value,
    thanks_message: form.elements.thanks_message.value,
    closes_at: form.elements.closes_at.value,
    one_per_device: form.elements.one_per_device.checked ? 1 : 0,
    fields: builderFields.map(field => ({
      id: field.saved ? field.id : undefined,
      type: field.type,
      label: field.label,
      help_text: field.help_text,
      required: field.required,
      hidden: field.hidden,
      chart: field.chart,
      options: field.options
    }))
  };

  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;

  try {
    await api.request(builderFormId ? `/forms/${builderFormId}` : '/forms', {
      method: builderFormId ? 'PUT' : 'POST',
      body: JSON.stringify(payload)
    });
    showNotification('Formulaire enregistré', 'success');
    loadForms();
  } catch (error) {
    alert(error.message || "L'enregistrement a échoué.");
    button.disabled = false;
  }
}

// ---------- Réponses et graphiques ----------

function barChart(stat) {
  const max = Math.max(1, ...stat.series.map(point => point.count));
  return stat.series.map(point => `
    <div class="fr-bar">
      <span>${escapeHtml(point.label)}</span>
      <span class="fr-bar-track"><span class="fr-bar-fill" style="width:${(point.count / max) * 100}%"></span></span>
      <strong>${Number(point.count)}</strong>
    </div>`).join('');
}

function pieChart(stat) {
  const total = stat.series.reduce((sum, point) => sum + point.count, 0);
  if (total === 0) return '<p class="fb-note">Pas encore de réponse.</p>';

  // Secteurs dessinés par des traits : rayon 15,915 pour un périmètre de 100
  let offset = 25;
  const slices = stat.series.map((point, index) => {
    const share = (point.count / total) * 100;
    const slice = `<circle cx="21" cy="21" r="15.915" fill="none" stroke="${PIE_COLORS[index % PIE_COLORS.length]}" stroke-width="8" stroke-dasharray="${share} ${100 - share}" stroke-dashoffset="${offset}"></circle>`;
    offset -= share;
    return slice;
  }).join('');

  return `
    <div class="fr-pie">
      <svg viewBox="0 0 42 42" role="img" aria-label="${escapeHtml(stat.label)}">${slices}</svg>
      <ul class="fr-legend">
        ${stat.series.map((point, index) => `
          <li><span class="fr-swatch" style="background:${PIE_COLORS[index % PIE_COLORS.length]}"></span>${escapeHtml(point.label)} : <strong>${Number(point.count)}</strong> (${Math.round((point.count / total) * 100)} %)</li>
        `).join('')}
      </ul>
    </div>`;
}

function statCard(stat) {
  const summary = [];
  if (stat.average !== undefined && stat.average !== null) summary.push(`Moyenne : ${stat.average}`);
  if (stat.min !== undefined) summary.push(`de ${stat.min} à ${stat.max}`);

  let body = '';
  if (stat.chart === 'pie' && stat.series) body = pieChart(stat);
  else if (stat.chart === 'bar' && stat.series) body = barChart(stat);
  else return '';

  return `
    <div class="fb-card">
      <h3>${escapeHtml(stat.label)}</h3>
      <p class="fb-note">${Number(stat.n)} réponse(s)${summary.length ? ' · ' + escapeHtml(summary.join(', ')) : ''}</p>
      ${body}
    </div>`;
}

async function openFormResults(id) {
  const container = formsContainer();
  container.textContent = 'Chargement…';
  setFormsHeader('Réponses', '<button type="button" class="btn btn-secondary" onclick="loadForms()">Retour à la liste</button>');

  try {
    const { data } = await api.request(`/forms/${id}/responses`);
    setFormsHeader(`Réponses : ${data.title}`, '<button type="button" class="btn btn-secondary" onclick="loadForms()">Retour à la liste</button>');

    const charts = data.stats.map(statCard).join('');
    container.innerHTML = `
      <div class="fr-toolbar">
        <button type="button" class="btn btn-secondary btn-sm" onclick="exportForm(${Number(id)}, 'csv', this)"><i class="ti ti-file-type-csv"></i> CSV</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="exportForm(${Number(id)}, 'excel', this)"><i class="ti ti-file-spreadsheet"></i> Excel</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="exportForm(${Number(id)}, 'pdf', this)"><i class="ti ti-file-text"></i> PDF</button>
      </div>
      ${charts ? `<div class="fr-grid">${charts}</div>` : ''}
      <div class="fb-card fr-table">
        ${data.rows.length === 0 ? 'Aucune réponse pour le moment.' : `
          <table>
            <thead><tr><th>Date</th>${data.columns.map(column => `<th>${escapeHtml(column)}</th>`).join('')}<th>Actions</th></tr></thead>
            <tbody>
              ${data.rows.map(row => `
                <tr>
                  <td>${escapeHtml(row.created_at)}</td>
                  ${row.cells.map(cell => `<td>${escapeHtml(cell)}</td>`).join('')}
                  <td><button class="btn btn-secondary btn-sm" onclick="deleteFormResponse(${Number(row.id)}, ${Number(id)})">Supprimer</button></td>
                </tr>
              `).join('')}
            </tbody>
          </table>`}
      </div>`;
  } catch (error) {
    container.textContent = error.message || 'Lecture impossible. Recharge la page.';
  }
}

async function deleteFormResponse(responseId, formId) {
  if (!confirm('Supprimer définitivement cette réponse ?')) return;
  try {
    await api.request(`/forms/responses/${responseId}`, { method: 'DELETE' });
    openFormResults(formId);
  } catch (error) {
    alert(error.message || 'La suppression a échoué.');
  }
}

// Fetch plutôt qu'un lien : le téléchargement doit porter le jeton admin
async function exportForm(id, format, button) {
  const original = button.innerHTML;
  button.disabled = true;
  button.textContent = 'Préparation…';

  try {
    const response = await fetch(`${API_BASE_URL}/forms/${id}/export?format=${format}`, {
      headers: { 'Authorization': `Bearer ${api.token}` }
    });
    if (!response.ok) {
      let message = `Export impossible (${response.status})`;
      try { message = (await response.json()).message || message; } catch (e) {}
      throw new Error(message);
    }

    const match = (response.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/);
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url;
    link.download = match ? match[1] : `reponses.${format === 'excel' ? 'xls' : format}`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (error) {
    alert(error.message || "L'export a échoué.");
  } finally {
    button.disabled = false;
    button.innerHTML = original;
  }
}
