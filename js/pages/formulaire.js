// ============================================
// FORMULAIRE PUBLIC
// ?f=adresse pour répondre, ?apercu=id pour l'aperçu de l'administrateur
// ============================================

const formRoot = document.getElementById('form-root');
const formParams = new URLSearchParams(window.location.search);
const formSlug = formParams.get('f');
const formPreviewId = formParams.get('apercu');

function formMessage(title, text) {
  document.getElementById('form-title').textContent = title;
  document.getElementById('form-intro').textContent = '';
  formRoot.innerHTML = `<div class="card fade-in"><p>${escapeHtml(text)}</p></div>`;
}

/**
 * Identifiant aléatoire conservé sur l'appareil. S'il ne peut pas être conservé
 * (navigation privée stricte), il vaut pour la page en cours seulement.
 */
function deviceToken() {
  const key = 'dd_device';
  let token = null;
  try { token = localStorage.getItem(key); } catch (error) {}
  if (!token || !/^[A-Za-z0-9-]{16,64}$/.test(token)) {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    token = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    try { localStorage.setItem(key, token); } catch (error) {}
  }
  return token;
}

function fieldLabel(field) {
  return `${escapeHtml(field.label)}${field.required ? ' <span class="required-mark" aria-hidden="true">*</span>' : ''}`;
}

function choiceList(field, kind, choices, row) {
  return `
    <fieldset>
      <legend>${fieldLabel(field)}</legend>
      ${field.help_text ? `<p class="help">${escapeHtml(field.help_text)}</p>` : ''}
      <div class="choices${row ? ' is-row' : ''}">
        ${choices.map(([value, label]) => `
          <label><input type="${kind}" name="field-${field.id}" value="${escapeHtml(value)}"${field.required && kind === 'radio' ? ' required' : ''}> ${escapeHtml(label)}</label>
        `).join('')}
      </div>
    </fieldset>`;
}

function renderField(field) {
  const id = `field-${field.id}`;
  const required = field.required ? ' required' : '';
  const input = (html) => `
    <label for="${id}">${fieldLabel(field)}</label>
    ${field.help_text ? `<p class="help">${escapeHtml(field.help_text)}</p>` : ''}
    ${html}`;
  const options = field.options || [];

  switch (field.type) {
    case 'textarea':
      return input(`<textarea id="${id}" name="${id}" maxlength="10000"${required}></textarea>`);
    case 'number':
      return input(`<input type="number" step="any" inputmode="decimal" id="${id}" name="${id}"${required}>`);
    case 'date':
      return input(`<input type="date" id="${id}" name="${id}"${required}>`);
    case 'select':
      return input(`<select id="${id}" name="${id}"${required}><option value="">Choisir…</option>${options.map(option => `<option value="${escapeHtml(option)}">${escapeHtml(option)}</option>`).join('')}</select>`);
    case 'single':
      return choiceList(field, 'radio', options.map(option => [option, option]), false);
    case 'multiple':
      return choiceList(field, 'checkbox', options.map(option => [option, option]), false);
    case 'scale':
      return choiceList(field, 'radio', ['1', '2', '3', '4', '5'].map(value => [value, value]), true);
    case 'yesno':
      return choiceList(field, 'radio', [['oui', 'Oui'], ['non', 'Non']], true);
    default:
      return input(`<input type="text" id="${id}" name="${id}" maxlength="500"${required}>`);
  }
}

function collectAnswers(form, fields) {
  const data = new FormData(form);
  const answers = {};
  for (const field of fields) {
    const name = `field-${field.id}`;
    answers[field.id] = field.type === 'multiple' ? data.getAll(name) : (data.get(name) || '').trim();
  }
  return answers;
}

function missingField(fields, answers) {
  return fields.find((field) => {
    const value = answers[field.id];
    return field.required && (Array.isArray(value) ? value.length === 0 : value === '');
  });
}

function renderForm(form, preview) {
  document.title = `${form.title} | DevDynamics`;
  document.getElementById('form-title').textContent = form.title;
  document.getElementById('form-intro').textContent = form.intro || '';

  formRoot.innerHTML = `
    ${preview ? '<p class="preview-banner"><i class="ti ti-eye" aria-hidden="true"></i> Aperçu : les réponses ne sont pas enregistrées.</p>' : ''}
    <form class="form fade-in" id="public-form" novalidate>
      <div class="hp" aria-hidden="true">
        <label for="form-website">Ne pas remplir</label>
        <input type="text" id="form-website" name="website" tabindex="-1" autocomplete="off">
      </div>
      ${form.fields.map(field => `<div class="field">${renderField(field)}</div>`).join('')}
      <p class="form-status" id="form-status" role="alert" hidden></p>
      <div class="actions"><button type="submit" class="btn btn-primary">Envoyer</button></div>
      <p class="form-note">Les réponses servent au suivi du projet. Voir la <a href="confidentialite.html">page Confidentialité</a>.</p>
    </form>`;

  const element = document.getElementById('public-form');
  const status = document.getElementById('form-status');
  const submit = element.querySelector('button[type="submit"]');

  const fail = (message) => {
    status.textContent = message;
    status.className = 'form-status is-error';
    status.hidden = false;
  };

  element.addEventListener('submit', async (event) => {
    event.preventDefault();
    status.hidden = true;

    const answers = collectAnswers(element, form.fields);
    const missing = missingField(form.fields, answers);
    if (missing) {
      fail(`Répondez à la question : ${missing.label}`);
      return;
    }
    if (preview) {
      fail('Aperçu : rien n\'a été envoyé.');
      return;
    }

    submit.disabled = true;
    submit.textContent = 'Envoi en cours…';

    try {
      const response = await api.request(`/forms/public/${encodeURIComponent(formSlug)}/responses`, {
        method: 'POST',
        body: JSON.stringify({
          answers,
          website: element.elements.website.value,
          device_token: form.one_per_device ? deviceToken() : undefined
        })
      });
      const thanks = response.data && response.data.thanks_message;
      formMessage('Merci !', thanks || 'Votre réponse a bien été enregistrée.');
      window.scrollTo(0, 0);
    } catch (error) {
      fail(error.message || "L'envoi a échoué. Vérifiez votre connexion et réessayez.");
      submit.disabled = false;
      submit.textContent = 'Envoyer';
    }
  });
}

async function loadPublicForm() {
  if (formPreviewId) {
    try {
      const { data } = await api.request(`/forms/${encodeURIComponent(formPreviewId)}`);
      renderForm({ ...data, fields: data.fields.filter(field => !Number(field.hidden)).map(field => ({ ...field, required: Number(field.required) })) }, true);
    } catch (error) {
      formMessage('Aperçu indisponible', 'Connectez-vous à l\'administration pour voir cet aperçu.');
    }
    return;
  }

  if (!formSlug) {
    formMessage('Formulaire introuvable', "Ce lien ne correspond à aucun formulaire.");
    return;
  }

  try {
    const { data } = await api.request(`/forms/public/${encodeURIComponent(formSlug)}`);
    if (data.state === 'open') {
      renderForm(data, false);
    } else if (data.state === 'suspended') {
      formMessage(data.title, "Ce formulaire est suspendu pour le moment. Il n'accepte pas de réponses.");
    } else {
      formMessage(data.title, "Ce formulaire est fermé. Il n'accepte plus de réponses.");
    }
  } catch (error) {
    if (error.status === 404) {
      formMessage('Formulaire introuvable', "Ce lien ne correspond à aucun formulaire.");
    } else {
      formMessage('Formulaire indisponible', 'Il ne peut pas être affiché pour le moment. Réessayez dans un instant.');
    }
  }
}

loadPublicForm();
