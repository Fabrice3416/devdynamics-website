// ============================================
// ADMIN — Feuille de présence
// ============================================

async function loadPresence() {
  const container = document.getElementById('presence-content');
  container.textContent = 'Chargement…';

  try {
    const response = await api.request('/attendance');
    const rows = response.data;
    const genders = { F: 'Féminin', M: 'Masculin' };
    const link = `${window.location.origin}/pages/presence.html`;

    container.innerHTML = `
      <p style="padding:var(--spacing-md);">
        <strong>${rows.length}</strong> présent${rows.length > 1 ? 's' : ''}.
        Lien à partager : <code>${escapeHtml(link)}</code>
      </p>
      <table>
        <thead>
          <tr>
            <th>N°</th>
            <th>Nom</th>
            <th>Prénom</th>
            <th>Identifiant</th>
            <th>Sexe</th>
            <th>Âge</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map((row, index) => `
            <tr>
              <td>${index + 1}</td>
              <td>${escapeHtml(row.last_name)}</td>
              <td>${escapeHtml(row.first_name)}</td>
              <td>${escapeHtml(row.identifier)}</td>
              <td>${genders[row.gender] || escapeHtml(row.gender)}</td>
              <td>${escapeHtml(row.age)}</td>
              <td>
                <button class="btn btn-secondary btn-sm" onclick="deletePresence(${Number(row.id)})">Supprimer</button>
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

async function deletePresence(id) {
  if (!confirm('Supprimer définitivement cette ligne de la feuille de présence ?')) return;

  try {
    await api.request(`/attendance/${id}`, { method: 'DELETE' });
    loadPresence();
  } catch (error) {
    alert(error.message || 'La suppression a échoué.');
  }
}

/**
 * Le téléchargement passe par fetch : il doit porter le jeton admin,
 * qu'un simple lien href n'enverrait pas.
 */
document.getElementById('presence-download').addEventListener('click', async (event) => {
  const button = event.currentTarget;
  const original = button.innerHTML;
  button.disabled = true;
  button.textContent = 'Préparation…';

  try {
    const response = await fetch(`${API_BASE_URL}/attendance/export`, {
      headers: { 'Authorization': `Bearer ${api.token}` }
    });

    if (!response.ok) {
      let message = `Téléchargement impossible (${response.status})`;
      try { message = (await response.json()).message || message; } catch (e) {}
      throw new Error(message);
    }

    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url;
    link.download = 'feuille-de-presence-2026-11-02.pdf';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (error) {
    alert(error.message || 'Le téléchargement a échoué.');
  } finally {
    button.disabled = false;
    button.innerHTML = original;
  }
});
