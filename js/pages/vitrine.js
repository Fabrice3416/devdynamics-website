// ============================================
// VITRINE — partenaires, témoignages, fondateurs
// ============================================

function partnerTile(partner, base) {
  return `
    <div class="logo-tile fade-in">
      ${partner.logo_url
        ? `<img src="${base}${escapeHtml(partner.logo_url)}" alt="${escapeHtml(partner.name)}" loading="lazy">`
        : escapeHtml(partner.name)}
    </div>`;
}

async function loadPartnersGrid() {
  const container = document.getElementById('partners-grid');
  try {
    const { data } = await api.request('/partners');
    container.innerHTML = data.length === 0
      ? '<p class="state-message">Aucun partenaire à afficher pour le moment.</p>'
      : data.map(partner => partnerTile(partner, '../')).join('');
  } catch (error) {
    container.innerHTML = '<p class="state-message">Les partenaires ne peuvent pas être affichés pour le moment. Réessayez dans un instant.</p>';
  }
}

// L'accueil garde ces sections cachées tant qu'elles n'ont rien à montrer
async function loadHomeShowcase() {
  try {
    const { data } = await api.request('/partners');
    if (data.length > 0) {
      document.getElementById('home-partners').innerHTML = data.map(partner => partnerTile(partner, '')).join('');
      document.getElementById('home-partners-section').hidden = false;
    }
  } catch (error) {}

  try {
    const { data } = await api.request('/project-testimonials/featured');
    if (data.length > 0) {
      document.getElementById('home-testimonials').innerHTML = data.map(item => testimonialCard(item,
        ` <br><a href="pages/realisation.html?p=${encodeURIComponent(item.project_slug)}">${escapeHtml(item.project_name)}</a>`
      )).join('');
      document.getElementById('home-testimonials-section').hidden = false;
    }
  } catch (error) {}
}

// ---------- Dépôt d'un témoignage ----------

function testimonialMessage(text) {
  document.getElementById('testimonial-root').innerHTML = `<div class="card fade-in"><p>${escapeHtml(text)}</p></div>`;
}

async function loadTestimonialForm() {
  const root = document.getElementById('testimonial-root');
  const slug = new URLSearchParams(window.location.search).get('p');

  if (!slug) {
    testimonialMessage('Ce lien ne correspond à aucun projet.');
    return;
  }

  let project;
  try {
    ({ data: project } = await api.request(`/project-testimonials/collect/${encodeURIComponent(slug)}`));
  } catch (error) {
    testimonialMessage(error.status === 404 ? 'Ce lien ne correspond à aucun projet.' : 'Cette page ne peut pas être affichée pour le moment. Réessayez dans un instant.');
    return;
  }

  document.getElementById('testimonial-lead').textContent = project.project_name;
  if (!project.open) {
    testimonialMessage('La collecte de témoignages est fermée pour ce projet.');
    return;
  }

  root.innerHTML = `
    <form class="form fade-in" id="testimonial-form" novalidate>
      <div class="hp" aria-hidden="true">
        <label for="testimonial-website">Ne pas remplir</label>
        <input type="text" id="testimonial-website" name="website" tabindex="-1" autocomplete="off">
      </div>
      <div class="field">
        <label for="testimonial-name">Nom</label>
        <input type="text" id="testimonial-name" name="name" maxlength="120" autocomplete="name" required>
      </div>
      <div class="field">
        <label for="testimonial-role">Fonction <span class="optional">(facultatif)</span></label>
        <input type="text" id="testimonial-role" name="role_title" maxlength="160">
      </div>
      <div class="field">
        <label for="testimonial-content">Votre témoignage</label>
        <textarea id="testimonial-content" name="content" maxlength="3000" required></textarea>
      </div>
      <div class="field">
        <label for="testimonial-video">Lien d'une vidéo YouTube <span class="optional">(facultatif)</span></label>
        <input type="url" id="testimonial-video" name="video_url" inputmode="url" placeholder="https://">
      </div>
      <div class="field">
        <label class="consent"><input type="checkbox" name="consent" required> J'accepte que ce témoignage, mon nom et ma fonction soient publiés sur le site de DevDynamics.</label>
      </div>
      <p class="form-status" id="testimonial-status" role="alert" hidden></p>
      <div class="actions"><button type="submit" class="btn btn-primary">Envoyer</button></div>
      <p class="form-note">Votre témoignage sera publié après validation. Voir la <a href="confidentialite.html">page Confidentialité</a>.</p>
    </form>`;

  const form = document.getElementById('testimonial-form');
  const status = document.getElementById('testimonial-status');
  const submit = form.querySelector('button[type="submit"]');
  const fail = (message) => {
    status.textContent = message;
    status.className = 'form-status is-error';
    status.hidden = false;
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    status.hidden = true;

    const data = Object.fromEntries(new FormData(form).entries());
    data.consent = form.elements.consent.checked ? 1 : 0;

    if (!data.name.trim()) return fail('Indiquez votre nom.');
    if (!data.content.trim()) return fail('Écrivez votre témoignage.');
    if (!data.consent) return fail('Votre accord pour la publication est nécessaire.');

    submit.disabled = true;
    submit.textContent = 'Envoi en cours…';

    try {
      await api.request(`/project-testimonials/collect/${encodeURIComponent(slug)}`, {
        method: 'POST',
        body: JSON.stringify(data)
      });
      document.getElementById('testimonial-title').textContent = 'Merci !';
      testimonialMessage('Votre témoignage a bien été reçu. Il sera publié après validation.');
      window.scrollTo(0, 0);
    } catch (error) {
      fail(error.message || "L'envoi a échoué. Vérifiez votre connexion et réessayez.");
      submit.disabled = false;
      submit.textContent = 'Envoyer';
    }
  });
}

// ---------- Qui sommes-nous ----------

window.addEventListener('organization-loaded', ({ detail: organization }) => {
  const history = document.getElementById('history');
  if (history && organization.description) {
    history.innerHTML = `<h2>Notre histoire</h2>${organization.description.split(/\n{2,}/).filter(Boolean).map(text => `<p>${escapeHtml(text)}</p>`).join('')}`;
    history.classList.add('is-visible');
  }

  const founders = document.getElementById('founders');
  if (founders && organization.founders && organization.founders.length > 0) {
    founders.innerHTML = organization.founders.map(founder => `
      <article class="card person fade-in">
        ${founder.image_url
          ? `<img class="person-photo" src="../${escapeHtml(founder.image_url)}" alt="">`
          : '<span class="icon-badge"><i class="ti ti-user" aria-hidden="true"></i></span>'}
        <h3>${escapeHtml(founder.name)}</h3>
        <p>${escapeHtml(founder.role)}</p>
      </article>`).join('');
  }
});

if (document.getElementById('partners-grid')) loadPartnersGrid();
if (document.getElementById('home-partners')) loadHomeShowcase();
if (document.getElementById('testimonial-root')) loadTestimonialForm();
