// ============================================
// FICHE D'ÉTUDE — Enseignant(e) ACP
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  initNavigation();
  initConditionalFields();
  initFormSubmit();
});

function initNavigation() {
  const hamburger = document.querySelector('.hamburger');
  const navMenu = document.querySelector('.nav-menu');
  if (hamburger && navMenu) {
    hamburger.addEventListener('click', () => navMenu.classList.toggle('active'));
  }
}

// Toggle conditional precision fields based on radio answers.
function initConditionalFields() {
  bindToggle('eleves_touches', 'Oui', 'touches-precisions-group', 'eleves_touches_lequel', false);
  bindToggle('zones_a_eviter', 'Oui', 'zones-precisions-group', 'zones_precisions', true);
  bindToggle('besoins_particuliers', 'Oui', 'besoins-precisions-group', 'besoins_precisions', true);
}

function bindToggle(radioName, triggerValue, groupId, fieldId, requiredWhenShown) {
  const radios = document.querySelectorAll(`input[name="${radioName}"]`);
  const group = document.getElementById(groupId);
  const field = document.getElementById(fieldId);
  if (!radios.length || !group || !field) return;

  radios.forEach(radio => {
    radio.addEventListener('change', () => {
      const show = radio.checked && radio.value === triggerValue;
      group.style.display = show ? 'block' : 'none';
      if (requiredWhenShown) field.required = show;
      if (!show) field.value = '';
    });
  });
}

function initFormSubmit() {
  const form = document.getElementById('fiche-etude-form');
  if (!form) return;
  form.addEventListener('submit', handleSubmit);
}

async function handleSubmit(e) {
  e.preventDefault();
  const form = e.currentTarget;
  const submitBtn = document.getElementById('submit-btn');
  const feedback = document.getElementById('form-feedback');

  clearFieldErrors(form);
  feedback.style.display = 'none';

  const data = collectFormData(form);
  const errors = validateClient(data);
  if (Object.keys(errors).length > 0) {
    showFieldErrors(form, errors);
    showFeedback(feedback, 'Veuillez corriger les champs en surbrillance avant de soumettre.', 'error');
    scrollToFirstError(form);
    return;
  }

  submitBtn.disabled = true;
  const originalLabel = submitBtn.textContent;
  submitBtn.innerHTML = '<span class="spinner"></span> Envoi en cours...';

  try {
    const response = await api.submitFicheEtude(data);
    if (response && response.success && response.data && response.data.fiche_id) {
      showSuccess(response.data);
    } else {
      throw new Error(response?.message || 'Réponse inattendue du serveur');
    }
  } catch (err) {
    console.error('Erreur soumission:', err);
    showFeedback(feedback, 'Erreur lors de l\'envoi : ' + (err.message || 'serveur indisponible') + '. Réessayez ou contactez-nous.', 'error');
    submitBtn.disabled = false;
    submitBtn.textContent = originalLabel;
  }
}

function collectFormData(form) {
  const fd = new FormData(form);
  const data = {};
  for (const [key, value] of fd.entries()) {
    if (key === 'risques[]') continue;
    data[key] = typeof value === 'string' ? value.trim() : value;
  }
  data.risques = fd.getAll('risques[]');
  data.auth_animation = form.querySelector('#auth_animation').checked;
  data.auth_photos = form.querySelector('#auth_photos').checked;
  data.auth_conservation = form.querySelector('#auth_conservation').checked;
  return data;
}

function validateClient(data) {
  const errors = {};
  const required = [
    'etablissement_nom', 'etablissement_adresse',
    'enseignant_prenom', 'enseignant_nom', 'telephone', 'email',
    'niveau_classe', 'nombre_eleves', 'age_moyen', 'creneau',
    'eleves_touches', 'zones_a_eviter',
    'niveau_lecture', 'capacite_attention', 'aisance_oral',
    'notions_geo', 'plan_simple', 'besoins_particuliers', 'langue_instruction',
    'date_souhaitee_1', 'videoprojecteur', 'imprimante',
    'langue_animation', 'disponible_encadrer', 'conserver_feuilles',
    'motivation', 'attentes', 'collaboration_future'
  ];
  required.forEach(f => {
    if (!data[f] || String(data[f]).trim() === '') errors[f] = 'Champ obligatoire';
  });

  if (data.email && !isEmail(data.email)) errors.email = 'Adresse email invalide';

  if (data.motivation && data.motivation.length < 20) errors.motivation = 'Minimum 20 caractères';
  if (data.attentes && data.attentes.length < 20) errors.attentes = 'Minimum 20 caractères';

  if (!Array.isArray(data.risques) || data.risques.length === 0) {
    if (!data.risque_autre || data.risque_autre.trim() === '') {
      errors['risques[]'] = 'Cochez au moins un risque (ou précisez « Autre »)';
    }
  }

  if (data.zones_a_eviter === 'Oui' && (!data.zones_precisions || data.zones_precisions.trim() === '')) {
    errors.zones_precisions = 'Précisez les zones à éviter';
  }

  if (data.besoins_particuliers === 'Oui' && (!data.besoins_precisions || data.besoins_precisions.trim() === '')) {
    errors.besoins_precisions = 'Précisez les besoins particuliers';
  }

  if (!data.auth_animation) errors.auth_animation = 'Vous devez autoriser l\'animation de la séance';

  return errors;
}

function isEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function clearFieldErrors(form) {
  form.querySelectorAll('.field-error').forEach(el => el.remove());
  form.querySelectorAll('.error').forEach(el => el.classList.remove('error'));
}

function showFieldErrors(form, errors) {
  Object.entries(errors).forEach(([field, message]) => {
    const inputs = form.querySelectorAll('[name="' + field + '"]');
    if (inputs.length === 0) return;
    inputs.forEach(input => input.classList.add('error'));
    const lastInput = inputs[inputs.length - 1];
    const container = lastInput.closest('.form-group') || lastInput.parentElement;
    if (container && !container.querySelector('.field-error')) {
      const div = document.createElement('div');
      div.className = 'field-error';
      div.textContent = message;
      container.appendChild(div);
    }
  });
}

function scrollToFirstError(form) {
  const first = form.querySelector('.error');
  if (first) {
    first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (typeof first.focus === 'function') first.focus({ preventScroll: true });
  }
}

function showFeedback(el, message, type) {
  el.textContent = message;
  el.className = 'form-feedback ' + type;
  el.style.display = 'block';
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function showSuccess(payload) {
  document.getElementById('fiche-etude-form').style.display = 'none';
  const success = document.getElementById('success-message');
  document.getElementById('fiche-id-display').textContent = payload.fiche_id;
  success.style.display = 'block';
  success.scrollIntoView({ behavior: 'smooth', block: 'start' });

  if (payload.email_sent === false) {
    const warn = document.createElement('p');
    warn.style.color = 'var(--color-warning)';
    warn.style.marginTop = 'var(--spacing-md)';
    warn.innerHTML = 'Note : l\'envoi automatique par email a échoué. Votre fiche est bien enregistrée — l\'équipe sera notifiée manuellement.';
    success.insertBefore(warn, success.querySelector('.btn'));
  }
}
