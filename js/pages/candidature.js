// ============================================
// CANDIDATURE — Académie des Cartographes Populaires
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  initNavigation();
  initDateConstraints();
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

// Restrict the date-of-birth picker to applicants aged 18-25 at the deadline.
function initDateConstraints() {
  const dateInput = document.getElementById('date_naissance');
  if (!dateInput) return;
  const today = new Date();
  const maxDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());
  const minDate = new Date(today.getFullYear() - 25, today.getMonth(), today.getDate());
  dateInput.max = maxDate.toISOString().split('T')[0];
  dateInput.min = minDate.toISOString().split('T')[0];
}

// "Avec contraintes" reveals the contraintes textarea and makes it required.
function initConditionalFields() {
  const radios = document.querySelectorAll('input[name="disponibilite"]');
  const group = document.getElementById('contraintes-group');
  const textarea = document.getElementById('contraintes');

  radios.forEach(radio => {
    radio.addEventListener('change', () => {
      const showContraintes = radio.checked && radio.value === 'Avec contraintes';
      group.style.display = showContraintes ? 'block' : 'none';
      textarea.required = showContraintes;
      if (!showContraintes) textarea.value = '';
    });
  });
}

function initFormSubmit() {
  const form = document.getElementById('candidature-form');
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

  // Custom client-side check: native required + extra rules
  const data = collectFormData(form);
  const errors = validateClient(data);
  if (Object.keys(errors).length > 0) {
    showFieldErrors(form, errors);
    showFeedback(feedback, 'Veuillez corriger les champs en surbrillance avant de soumettre.', 'error');
    scrollToFirstError(form);
    return;
  }

  // Submit
  submitBtn.disabled = true;
  const originalLabel = submitBtn.textContent;
  submitBtn.innerHTML = '<span class="spinner"></span> Envoi en cours...';

  try {
    const response = await api.submitCandidature(data);
    if (response && response.success && response.data && response.data.candidature_id) {
      showSuccess(response.data);
    } else {
      throw new Error(response?.message || 'Réponse inattendue du serveur');
    }
  } catch (err) {
    console.error('Erreur soumission:', err);

    // Backend may return validation errors in err.message; fall back to generic
    const apiErrors = parseApiErrors(err);
    if (apiErrors) {
      showFieldErrors(form, apiErrors);
      showFeedback(feedback, 'Le serveur a rejeté certains champs. Voir les indications ci-dessous.', 'error');
      scrollToFirstError(form);
    } else {
      showFeedback(feedback, 'Erreur lors de l\'envoi : ' + (err.message || 'serveur indisponible') + '. Réessayez ou contactez-nous.', 'error');
    }
    submitBtn.disabled = false;
    submitBtn.textContent = originalLabel;
  }
}

function collectFormData(form) {
  const fd = new FormData(form);
  const data = {};
  for (const [key, value] of fd.entries()) {
    data[key] = typeof value === 'string' ? value.trim() : value;
  }
  // Coerce checkboxes (FormData only contains them when checked)
  data.confirm_id = form.querySelector('#confirm_id').checked;
  data.confirm_eligibility = form.querySelector('#confirm_eligibility').checked;
  return data;
}

function validateClient(data) {
  const errors = {};
  const required = [
    'prenom', 'nom', 'date_naissance', 'sexe', 'lieu_naissance', 'piece_identite',
    'adresse', 'commune', 'departement', 'telephone', 'email',
    'niveau_etudes', 'situation', 'motivation', 'usage_envisage', 'disponibilite'
  ];
  required.forEach(f => {
    if (!data[f] || String(data[f]).trim() === '') errors[f] = 'Champ obligatoire';
  });

  if (data.email && !isEmail(data.email)) errors.email = 'Adresse email invalide';

  if (data.date_naissance) {
    const dob = new Date(data.date_naissance);
    if (isNaN(dob.getTime())) {
      errors.date_naissance = 'Date invalide';
    } else {
      const age = computeAge(dob);
      if (age < 18 || age > 25) errors.date_naissance = 'Vous devez avoir entre 18 et 25 ans';
    }
  }

  if (data.motivation && data.motivation.length < 30) errors.motivation = 'Minimum 30 caractères';
  if (data.usage_envisage && data.usage_envisage.length < 30) errors.usage_envisage = 'Minimum 30 caractères';

  if (data.disponibilite === 'Avec contraintes' && (!data.contraintes || data.contraintes.trim() === '')) {
    errors.contraintes = 'Précisez vos contraintes';
  }

  if (!data.confirm_id) errors.confirm_id = 'Vous devez accepter cet engagement';
  if (!data.confirm_eligibility) errors.confirm_eligibility = 'Vous devez certifier votre éligibilité';

  return errors;
}

function computeAge(dob) {
  const now = new Date();
  let age = now.getFullYear() - dob.getFullYear();
  const m = now.getMonth() - dob.getMonth();
  if (m < 0 || (m === 0 && now.getDate() < dob.getDate())) age--;
  return age;
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

function parseApiErrors(err) {
  // Backend returns 422 with data: {field: message, ...} on validation
  // The api.js client throws a plain Error and discards the data field, so we
  // can't reliably parse it. Return null to fall back to generic message.
  return null;
}

function showSuccess(payload) {
  document.getElementById('candidature-form').style.display = 'none';
  const success = document.getElementById('success-message');
  document.getElementById('candidature-id-display').textContent = payload.candidature_id;
  success.style.display = 'block';
  success.scrollIntoView({ behavior: 'smooth', block: 'start' });

  // If email could not be delivered, surface a soft warning so the candidate
  // knows to follow up by email manually.
  if (payload.email_sent === false) {
    const warn = document.createElement('p');
    warn.style.color = 'var(--color-warning)';
    warn.style.marginTop = 'var(--spacing-md)';
    warn.innerHTML = 'Note : l\'envoi automatique par email a échoué. Votre candidature est bien enregistrée — l\'équipe sera notifiée manuellement.';
    success.insertBefore(warn, success.querySelector('.btn'));
  }
}
