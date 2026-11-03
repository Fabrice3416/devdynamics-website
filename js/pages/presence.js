// ============================================
// FEUILLE DE PRÉSENCE — restitution du 2 novembre 2026
// ============================================

const presenceForm = document.getElementById('presence-form');
const presenceDone = document.getElementById('presence-done');
const presenceError = document.getElementById('presence-error');
const presenceSubmit = document.getElementById('presence-submit');

function presenceFail(message) {
  presenceError.textContent = message;
  presenceError.hidden = false;
}

function presenceMissingField(data) {
  if (!data.last_name) return 'Indique ton nom.';
  if (!data.first_name) return 'Indique ton prénom.';
  if (!data.identifier) return 'Indique ton identifiant (NIF ou NINU).';
  if (!data.gender) return 'Indique ton sexe.';
  if (!data.age) return 'Indique ton âge.';
  return null;
}

presenceForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  presenceError.hidden = true;

  const data = Object.fromEntries(new FormData(presenceForm).entries());
  for (const key of ['last_name', 'first_name', 'identifier']) {
    data[key] = (data[key] || '').trim();
  }

  const missing = presenceMissingField(data);
  if (missing) {
    presenceFail(missing);
    return;
  }

  presenceSubmit.disabled = true;
  presenceSubmit.textContent = 'Envoi en cours…';

  try {
    await api.request('/attendance', {
      method: 'POST',
      body: JSON.stringify(data)
    });
    document.getElementById('presence-done-name').textContent = data.first_name;
    presenceForm.hidden = true;
    presenceDone.hidden = false;
    window.scrollTo(0, 0);
  } catch (error) {
    presenceFail(error.message || "L'envoi a échoué. Vérifie ta connexion et réessaie.");
  } finally {
    presenceSubmit.disabled = false;
    presenceSubmit.textContent = 'Confirmer ma présence';
  }
});
