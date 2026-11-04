// ============================================
// ADMIN — Messages, témoignages, partenaires, organisation
// ============================================

/**
 * Envoie une image. Pas de Content-Type ici : le navigateur doit poser
 * lui-même la frontière du multipart.
 */
async function uploadAdminImage(file) {
  const body = new FormData();
  body.append('file', file);
  const response = await fetch(`${API_BASE_URL}/uploads`, {
    method: 'POST',
    headers: { 'Authorization': `Bearer ${api.token}` },
    body
  });
  const result = await response.json();
  if (!response.ok) throw new Error(result.message || "L'envoi de l'image a échoué.");
  return result.data.path;
}

// ---------- Messages ----------

const MESSAGE_STATUS = { new: 'Non lu', read: 'Lu', replied: 'Répondu' };
let contactMessages = [];

function updateMessagesBadge() {
  const badge = document.getElementById('contact-badge');
  if (!badge) return;
  const unread = contactMessages.filter(message => message.status === 'new').length;
  badge.textContent = unread;
  badge.hidden = unread === 0;
}

async function fetchMessages() {
  const { data } = await api.request('/contact');
  contactMessages = data;
  updateMessagesBadge();
  return data;
}

async function loadContactMessages() {
  const container = document.getElementById('contact-list');
  container.textContent = 'Chargement…';

  try {
    const messages = await fetchMessages();
    container.innerHTML = messages.length === 0 ? '<p class="fb-card">Aucun message pour le moment.</p>' : `
      <table>
        <thead>
          <tr><th>Date</th><th>Nom</th><th>E-mail</th><th>Sujet</th><th>Statut</th><th>Actions</th></tr>
        </thead>
        <tbody>
          ${messages.map(message => `
            <tr${message.status === 'new' ? ' class="row-unread"' : ''}>
              <td>${escapeHtml(formatDate(message.created_at))}</td>
              <td>${escapeHtml(message.name)}</td>
              <td>${escapeHtml(message.email)}</td>
              <td>${escapeHtml(message.subject || '-')}</td>
              <td><span class="badge badge-${message.status === 'new' ? 'primary' : 'secondary'}">${MESSAGE_STATUS[message.status] || escapeHtml(message.status)}</span></td>
              <td>
                <div class="action-buttons">
                  <button class="btn btn-primary btn-sm" onclick="viewMessage(${Number(message.id)})">Lire</button>
                  <button class="btn btn-secondary btn-sm" onclick="deleteContactMessage(${Number(message.id)})">Supprimer</button>
                </div>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>`;
  } catch (error) {
    container.textContent = error.message || 'Lecture impossible. Recharge la page.';
  }
}

async function setMessageStatus(id, status) {
  await api.request(`/contact/${id}/status`, { method: 'PUT', body: JSON.stringify({ status }) });
}

async function viewMessage(id) {
  const message = contactMessages.find(item => Number(item.id) === id);
  if (!message) return;

  const subject = encodeURIComponent(`Re : ${message.subject || 'votre message à DevDynamics'}`);
  document.getElementById('message-body').innerHTML = `
    <dl class="message-meta">
      <div><dt>Nom</dt><dd>${escapeHtml(message.name)}</dd></div>
      <div><dt>E-mail</dt><dd>${escapeHtml(message.email)}</dd></div>
      <div><dt>Téléphone</dt><dd>${escapeHtml(message.phone || 'Non indiqué')}</dd></div>
      <div><dt>Sujet</dt><dd>${escapeHtml(message.subject || 'Non indiqué')}</dd></div>
      <div><dt>Reçu le</dt><dd>${escapeHtml(message.created_at)}</dd></div>
    </dl>
    <p class="message-text">${escapeHtml(message.message)}</p>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="markMessageUnread(${Number(message.id)})">Marquer non lu</button>
      <a class="btn btn-primary" href="mailto:${encodeURIComponent(message.email).replace(/%40/g, '@')}?subject=${subject}"><i class="ti ti-mail-forward"></i> Répondre</a>
    </div>`;
  openModal('message-modal');

  if (message.status === 'new') {
    try {
      await setMessageStatus(id, 'read');
      loadContactMessages();
    } catch (error) {
      console.error('Statut du message non modifié :', error);
    }
  }
}

async function markMessageUnread(id) {
  try {
    await setMessageStatus(id, 'new');
    closeModal('message-modal');
    loadContactMessages();
  } catch (error) {
    alert(error.message || 'Le changement a échoué.');
  }
}

async function deleteContactMessage(id) {
  if (!confirm('Supprimer définitivement ce message ?')) return;
  try {
    await api.request(`/contact/${id}`, { method: 'DELETE' });
    loadContactMessages();
  } catch (error) {
    alert(error.message || 'La suppression a échoué.');
  }
}

// ---------- Témoignages ----------

const TESTIMONIAL_STATUS = { pending: 'En attente', approved: 'Publié', rejected: 'Refusé' };
let testimonialsById = {};

async function loadProjectTestimonials() {
  const container = document.getElementById('testimonials-list');
  container.textContent = 'Chargement…';

  try {
    const { data } = await api.request('/project-testimonials');
    testimonialsById = Object.fromEntries(data.map(item => [item.id, item]));

    container.innerHTML = data.length === 0
      ? '<p class="fb-card">Aucun témoignage. Ouvrez la collecte depuis l\'écran Projets et partagez le lien.</p>'
      : data.map(item => `
        <article class="fb-card">
          <div class="fb-field-head">
            <strong>${escapeHtml(item.name)}${item.role_title ? `, ${escapeHtml(item.role_title)}` : ''}</strong>
            <span class="badge badge-${item.status === 'approved' ? 'success' : item.status === 'pending' ? 'primary' : 'secondary'}">${TESTIMONIAL_STATUS[item.status] || escapeHtml(item.status)}</span>
          </div>
          <p class="fb-note">${escapeHtml(item.project_name)} · ${escapeHtml(item.created_at)}</p>
          <p class="message-text">${escapeHtml(item.content)}</p>
          ${item.video_id ? `<p><a href="https://www.youtube.com/watch?v=${encodeURIComponent(item.video_id)}" target="_blank" rel="noopener"><i class="ti ti-brand-youtube"></i> Voir la vidéo</a></p>` : ''}
          <div class="fb-actions testimonial-video" id="testimonial-video-${Number(item.id)}">
            ${item.video_file ? `
              <button class="btn btn-secondary btn-sm" onclick="playTestimonialVideo(${Number(item.id)})"><i class="ti ti-player-play"></i> Voir la vidéo envoyée</button>
              <button class="btn btn-secondary btn-sm" onclick="removeTestimonialVideo(${Number(item.id)})">Retirer la vidéo</button>` : ''}
            <label class="btn btn-secondary btn-sm">
              <i class="ti ti-upload"></i> ${item.video_file ? 'Remplacer la vidéo' : 'Ajouter une vidéo'}
              <input type="file" class="sr-only" accept="video/mp4,video/webm,video/quicktime" onchange="uploadTestimonialVideo(${Number(item.id)}, this)">
            </label>
          </div>
          <div class="fb-actions">
            ${item.status !== 'approved' ? `<button class="btn btn-primary btn-sm" onclick="setTestimonial(${Number(item.id)}, 'approved', false)">Publier</button>` : ''}
            ${item.status !== 'rejected' ? `<button class="btn btn-secondary btn-sm" onclick="setTestimonial(${Number(item.id)}, 'rejected', false)">Refuser</button>` : ''}
            ${item.status === 'approved' ? `<button class="btn btn-secondary btn-sm" onclick="setTestimonial(${Number(item.id)}, 'approved', ${Number(item.featured) ? 'false' : 'true'})">${Number(item.featured) ? "Retirer de l'accueil" : "Mettre sur l'accueil"}</button>` : ''}
            <button class="btn btn-secondary btn-sm" onclick="deleteTestimonial(${Number(item.id)})">Supprimer</button>
          </div>
        </article>`).join('');
  } catch (error) {
    container.textContent = error.message || 'Lecture impossible. Recharge la page.';
  }
}

async function setTestimonial(id, status, featured) {
  try {
    await api.request(`/project-testimonials/${id}`, { method: 'PUT', body: JSON.stringify({ status, featured }) });
    loadProjectTestimonials();
  } catch (error) {
    alert(error.message || 'Le changement a échoué.');
  }
}

// Par fetch : une vidéo non publiée n'est lisible qu'avec le jeton admin
async function playTestimonialVideo(id) {
  const zone = document.getElementById(`testimonial-video-${id}`);
  const existing = zone.parentElement.querySelector('video');
  if (existing) return;

  try {
    const response = await fetch(`${API_BASE_URL}/project-testimonials/${id}/video`, {
      headers: { 'Authorization': `Bearer ${api.token}` }
    });
    if (!response.ok) throw new Error('Vidéo introuvable.');
    const video = document.createElement('video');
    video.controls = true;
    video.className = 'admin-video';
    video.src = URL.createObjectURL(await response.blob());
    zone.after(video);
  } catch (error) {
    alert(error.message || 'La vidéo ne peut pas être lue.');
  }
}

function uploadTestimonialVideo(id, input) {
  const file = input.files[0];
  if (!file) return;
  if (file.size > 100 * 1024 * 1024) {
    alert('La vidéo dépasse 100 Mo.');
    input.value = '';
    return;
  }

  const label = input.closest('label');
  const body = new FormData();
  body.append('video', file);

  const request = new XMLHttpRequest();
  request.open('POST', `${API_BASE_URL}/project-testimonials/${id}/video`);
  request.setRequestHeader('Authorization', `Bearer ${api.token}`);
  request.upload.addEventListener('progress', (event) => {
    if (event.lengthComputable) label.textContent = `Envoi : ${Math.round((event.loaded / event.total) * 100)} %`;
  });
  request.addEventListener('load', () => {
    if (request.status >= 200 && request.status < 300) {
      loadProjectTestimonials();
      return;
    }
    let message = "L'envoi de la vidéo a échoué.";
    try { message = JSON.parse(request.responseText).message || message; } catch (error) {}
    alert(message);
    loadProjectTestimonials();
  });
  request.addEventListener('error', () => {
    alert("L'envoi de la vidéo a échoué. Vérifie ta connexion.");
    loadProjectTestimonials();
  });
  request.send(body);
}

async function removeTestimonialVideo(id) {
  if (!confirm('Retirer définitivement cette vidéo ?')) return;
  try {
    await api.request(`/project-testimonials/${id}/video`, { method: 'DELETE' });
    loadProjectTestimonials();
  } catch (error) {
    alert(error.message || 'Le retrait a échoué.');
  }
}

async function deleteTestimonial(id) {
  if (!confirm(`Supprimer définitivement le témoignage de ${testimonialsById[id].name} ?`)) return;
  try {
    await api.request(`/project-testimonials/${id}`, { method: 'DELETE' });
    loadProjectTestimonials();
  } catch (error) {
    alert(error.message || 'La suppression a échoué.');
  }
}

// ---------- Partenaires ----------

let partnersList = [];

async function loadPartners() {
  const container = document.getElementById('partners-list');
  container.textContent = 'Chargement…';

  try {
    const { data } = await api.request('/partners/all');
    partnersList = data;

    container.innerHTML = data.length === 0 ? '<p class="fb-card">Aucun partenaire pour le moment.</p>' : `
      <table>
        <thead><tr><th>Logo</th><th>Nom</th><th>Visible</th><th>Actions</th></tr></thead>
        <tbody>
          ${data.map((partner, index) => `
            <tr>
              <td>${partner.logo_url ? `<span class="logo-plate"><img src="../${escapeHtml(partner.logo_url)}" alt=""></span>` : 'Logo à ajouter'}</td>
              <td>${escapeHtml(partner.name)}</td>
              <td>${Number(partner.is_active) ? 'Oui' : 'Non'}</td>
              <td>
                <div class="action-buttons">
                  <button class="btn btn-secondary btn-sm" aria-label="Monter" onclick="movePartner(${index}, -1)"${index === 0 ? ' disabled' : ''}><i class="ti ti-arrow-up"></i></button>
                  <button class="btn btn-secondary btn-sm" aria-label="Descendre" onclick="movePartner(${index}, 1)"${index === data.length - 1 ? ' disabled' : ''}><i class="ti ti-arrow-down"></i></button>
                  <button class="btn btn-primary btn-sm" onclick="openPartnerForm(${Number(partner.id)})">Modifier</button>
                  <button class="btn btn-secondary btn-sm" onclick="deletePartner(${Number(partner.id)})">Supprimer</button>
                </div>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>`;
  } catch (error) {
    container.textContent = error.message || 'Lecture impossible. Recharge la page.';
  }
}

function openPartnerForm(id) {
  const partner = id ? partnersList.find(item => Number(item.id) === id) : null;
  const form = document.getElementById('partner-form');
  form.reset();
  form.dataset.id = id || '';
  form.elements.name.value = partner ? partner.name : '';
  form.elements.logo_url.value = partner && partner.logo_url ? partner.logo_url : '';
  form.elements.is_active.checked = partner ? Boolean(Number(partner.is_active)) : true;
  document.getElementById('partner-logo-state').textContent = partner && partner.logo_url ? 'Un logo est enregistré.' : '';
  document.getElementById('partner-modal-title').textContent = id ? 'Modifier le partenaire' : 'Ajouter un partenaire';
  openModal('partner-modal');
}

async function movePartner(index, step) {
  const ids = partnersList.map(partner => partner.id);
  const [id] = ids.splice(index, 1);
  ids.splice(index + step, 0, id);
  try {
    await api.request('/partners/order', { method: 'PUT', body: JSON.stringify({ ids }) });
    loadPartners();
  } catch (error) {
    alert(error.message || 'Le changement a échoué.');
  }
}

async function deletePartner(id) {
  const partner = partnersList.find(item => Number(item.id) === id);
  if (!confirm(`Supprimer définitivement « ${partner.name} » ?`)) return;
  try {
    await api.request(`/partners/${id}`, { method: 'DELETE' });
    loadPartners();
  } catch (error) {
    alert(error.message || 'La suppression a échoué.');
  }
}

document.getElementById('add-partner-btn').addEventListener('click', () => openPartnerForm(null));

document.getElementById('partner-logo-file').addEventListener('change', async (event) => {
  const file = event.target.files[0];
  if (!file) return;

  const state = document.getElementById('partner-logo-state');
  const save = document.getElementById('partner-save');
  state.textContent = 'Envoi du logo…';
  save.disabled = true;

  try {
    document.getElementById('partner-form').elements.logo_url.value = await uploadAdminImage(file);
    state.textContent = 'Logo envoyé.';
  } catch (error) {
    event.target.value = '';
    state.textContent = error.message;
  } finally {
    save.disabled = false;
  }
});

document.getElementById('partner-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.target;
  const id = form.dataset.id;

  try {
    await api.request(id ? `/partners/${id}` : '/partners', {
      method: id ? 'PUT' : 'POST',
      body: JSON.stringify({
        name: form.elements.name.value,
        logo_url: form.elements.logo_url.value,
        is_active: form.elements.is_active.checked ? 1 : 0
      })
    });
    closeModal('partner-modal');
    loadPartners();
  } catch (error) {
    alert(error.message || "L'enregistrement a échoué.");
  }
});

// ---------- Organisation et fondateurs ----------

let foundersList = [];

async function loadOrganizationForm() {
  const form = document.getElementById('organization-form');
  try {
    const { data } = await api.request('/organization');
    for (const name of ['name', 'email', 'phone', 'address', 'mission', 'description']) {
      form.elements[name].value = data[name] || '';
    }
    foundersList = data.founders || [];
    renderFounders();
  } catch (error) {
    document.getElementById('founders-list').textContent = error.message || 'Lecture impossible. Recharge la page.';
  }
}

function renderFounders() {
  document.getElementById('founders-list').innerHTML = foundersList.length === 0 ? '<p>Aucun fondateur.</p>' : `
    <table>
      <thead><tr><th>Nom</th><th>Fonction</th><th>Ordre</th><th>Actions</th></tr></thead>
      <tbody>
        ${foundersList.map(founder => `
          <tr>
            <td>${escapeHtml(founder.name)}</td>
            <td>${escapeHtml(founder.role)}</td>
            <td>${Number(founder.order_position)}</td>
            <td>
              <div class="action-buttons">
                <button class="btn btn-primary btn-sm" onclick="openFounderForm(${Number(founder.id)})">Modifier</button>
                <button class="btn btn-secondary btn-sm" onclick="deleteFounder(${Number(founder.id)})">Supprimer</button>
              </div>
            </td>
          </tr>
        `).join('')}
      </tbody>
    </table>`;
}

function openFounderForm(id) {
  const founder = id ? foundersList.find(item => Number(item.id) === id) : null;
  const form = document.getElementById('founder-form');
  form.reset();
  form.dataset.id = id || '';
  form.elements.name.value = founder ? founder.name : '';
  form.elements.role.value = founder ? founder.role : '';
  form.elements.order_position.value = founder ? founder.order_position : foundersList.length;
  form.elements.image_url.value = founder && founder.image_url ? founder.image_url : '';
  document.getElementById('founder-image-state').textContent = founder && founder.image_url ? 'Une photo est enregistrée.' : '';
  document.getElementById('founder-modal-title').textContent = id ? 'Modifier le fondateur' : 'Ajouter un fondateur';
  openModal('founder-modal');
}

async function deleteFounder(id) {
  const founder = foundersList.find(item => Number(item.id) === id);
  if (!confirm(`Supprimer « ${founder.name} » ?`)) return;
  try {
    await api.request(`/founders/${id}`, { method: 'DELETE' });
    loadOrganizationForm();
  } catch (error) {
    alert(error.message || 'La suppression a échoué.');
  }
}

document.getElementById('add-founder-btn').addEventListener('click', () => openFounderForm(null));

document.getElementById('founder-image-file').addEventListener('change', async (event) => {
  const file = event.target.files[0];
  if (!file) return;

  const state = document.getElementById('founder-image-state');
  const save = document.getElementById('founder-save');
  state.textContent = 'Envoi de la photo…';
  save.disabled = true;

  try {
    document.getElementById('founder-form').elements.image_url.value = await uploadAdminImage(file);
    state.textContent = 'Photo envoyée.';
  } catch (error) {
    event.target.value = '';
    state.textContent = error.message;
  } finally {
    save.disabled = false;
  }
});

document.getElementById('founder-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.target;
  const id = form.dataset.id;

  try {
    await api.request(id ? `/founders/${id}` : '/founders', {
      method: id ? 'PUT' : 'POST',
      body: JSON.stringify(Object.fromEntries(new FormData(form).entries()))
    });
    closeModal('founder-modal');
    loadOrganizationForm();
  } catch (error) {
    alert(error.message || "L'enregistrement a échoué.");
  }
});

document.getElementById('organization-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  try {
    await api.request('/organization', {
      method: 'PUT',
      body: JSON.stringify(Object.fromEntries(new FormData(event.target).entries()))
    });
    showNotification('Informations enregistrées', 'success');
  } catch (error) {
    alert(error.message || "L'enregistrement a échoué.");
  }
});

// Le nombre de messages non lus se voit dès l'arrivée dans l'administration
fetchMessages().catch((error) => console.error('Messages non chargés :', error));
