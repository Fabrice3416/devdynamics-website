// ============================================
// ADMIN — Koulè Ki Pale : statistiques des questionnaires
// Les graphiques sont des barres HTML/CSS : rien à télécharger,
// et le rendu reste identique en clair comme en sombre.
// ============================================

let kkpStats = null;
let kkpResponses = null;
let kkpPhase = 'avant';

async function loadKkp() {
  const container = document.getElementById('kkp-content');
  container.innerHTML = '<div class="kkp-empty">Chargement des statistiques…</div>';

  try {
    const [stats, responses] = await Promise.all([
      api.request('/kkp/stats'),
      api.request('/kkp/responses')
    ]);
    kkpStats = stats.data;
    kkpResponses = responses.data;
    renderKkp();
  } catch (error) {
    container.innerHTML = `
      <div class="kkp-empty">
        <p><strong>Statistiques indisponibles.</strong></p>
        <p>${kkpEsc(error.message || 'Erreur inconnue')}</p>
        <p>Si la table n'existe pas encore, exécute <code>api/sql/kkp_responses.sql</code> sur la base.</p>
      </div>`;
  }
}

function kkpSetPhase(phase) {
  kkpPhase = phase;
  renderKkp();
}

// ---------- Rendu ----------

function renderKkp() {
  const stats = kkpStats;
  const agg = stats.phases[kkpPhase];
  const cmp = stats.comparison;
  const labels = stats.labels;

  const totalAll = stats.phases.avant.total + stats.phases.apres.total;

  document.getElementById('kkp-content').innerHTML = `
    ${kkpToolbar()}
    ${totalAll === 0 ? kkpNoData() : `
      ${kkpTiles(stats, cmp)}
      ${cmp.paired > 0 ? kkpComparison(cmp) : ''}
      ${kkpConflictBlock(agg)}
      ${kkpReactionBlock(stats)}
      ${kkpProfileBlock(stats.phases.avant, labels)}
      ${kkpArtBlock(stats)}
      ${kkpMeaningsBlock(cmp)}
      ${stats.phases.apres.total > 0 ? kkpFeedbackBlock(stats.phases.apres) : ''}
      ${stats.phases.apres.total > 0 ? kkpTestimonialsBlock(stats.phases.apres, labels) : ''}
      ${kkpOrphanBlock(cmp)}
      ${kkpTable(labels)}
    `}
  `;

  wireKkpToolbar();
}

function kkpToolbar() {
  const tab = (value, label) => `
    <button type="button" class="${kkpPhase === value ? 'active' : ''}" data-kkp-phase="${value}">
      ${label}
    </button>`;

  return `
    <div class="kkp-toolbar">
      <div class="kkp-phase-switch" role="group" aria-label="Phase affichée">
        ${tab('avant', 'Avant la formation')}
        ${tab('apres', 'Après la formation')}
      </div>
      <div class="kkp-exports">
        <button type="button" class="btn btn-outline btn-sm" data-kkp-export="csv">
          <i class="ti ti-file-type-csv"></i> CSV
        </button>
        <button type="button" class="btn btn-outline btn-sm" data-kkp-export="excel">
          <i class="ti ti-file-spreadsheet"></i> Excel
        </button>
        <button type="button" class="btn btn-outline btn-sm" data-kkp-export="pdf">
          <i class="ti ti-file-text"></i> Rapport PDF
        </button>
      </div>
    </div>`;
}

function kkpNoData() {
  return `
    <div class="kkp-empty">
      <p><strong>Aucune réponse pour le moment.</strong></p>
      <p>
        Partage le lien du questionnaire :<br>
        <code>/pages/kkp-questionnaire.html</code> (avant) &nbsp;·&nbsp;
        <code>/pages/kkp-questionnaire.html?phase=apres</code> (fin de formation)
      </p>
    </div>`;
}

function kkpTiles(stats, cmp) {
  const tile = (label, value, note) => `
    <div class="kkp-tile">
      <div class="kkp-tile-label">${label}</div>
      <div class="kkp-tile-value">${value}</div>
      ${note ? `<div class="kkp-tile-note">${note}</div>` : ''}
    </div>`;

  const avantAvg = stats.phases.avant.likert_global;
  const apresAvg = stats.phases.apres.likert_global;
  const satAvg = stats.phases.apres.satisfaction_global;

  return `
    <div class="kkp-tiles">
      ${tile('Questionnaires avant', stats.phases.avant.total)}
      ${tile('Questionnaires après', stats.phases.apres.total)}
      ${tile('Participants appariés', cmp.paired, 'ont rempli les deux')}
      ${tile(
        'Rapport au conflit',
        avantAvg === null ? '—' : kkpNum(avantAvg) + (apresAvg === null ? '' : ' → ' + kkpNum(apresAvg)),
        'moyenne sur 5'
      )}
      ${stats.phases.apres.total > 0
        ? tile('Satisfaction', satAvg === null ? '—' : kkpNum(satAvg), 'moyenne sur 5')
        : ''}
    </div>`;
}

/**
 * Évolution avant / après : deux séries, donc légende obligatoire,
 * plus l'écart en clair à droite de chaque paire.
 */
function kkpComparison(cmp) {
  const rows = cmp.likert.map(item => {
    const before = item.avg_before;
    const after = item.avg_after;
    const delta = item.delta;
    const deltaClass = delta === null ? 'flat' : (delta > 0 ? 'up' : (delta < 0 ? 'down' : 'flat'));
    const deltaText = delta === null ? '—' : (delta > 0 ? '+' : '') + kkpNum(delta);

    return `
      <div>
        <div class="kkp-row-label">
          ${kkpEsc(item.label)}
          <span class="kkp-n">— ${item.progress.up} en progrès · ${item.progress.flat} stable · ${item.progress.down} en recul</span>
        </div>
        ${kkpBarLine(before, 5, 'avant', kkpNum(before), '<span class="kkp-delta"></span>')}
        ${kkpBarLine(after, 5, 'apres', kkpNum(after), `<span class="kkp-delta kkp-delta-${deltaClass}">${deltaText}</span>`)}
      </div>`;
  }).join('');

  return `
    <div class="kkp-block">
      <h3>Évolution avant / après</h3>
      <p class="kkp-block-note">
        Moyennes sur 5, restreintes aux ${cmp.paired} participant(s) ayant rempli les deux questionnaires.
        Comparer des groupes différents mélangerait l'effet de la formation et celui des absents.
      </p>
      <div class="kkp-legend">
        <span><i class="kkp-swatch kkp-swatch-avant"></i> Avant la formation</span>
        <span><i class="kkp-swatch kkp-swatch-apres"></i> Après la formation</span>
      </div>
      <div class="kkp-rows">${rows}</div>
    </div>`;
}

function kkpConflictBlock(agg) {
  if (agg.total === 0) {
    return `
      <div class="kkp-block">
        <h3>Rapport au conflit</h3>
        <p class="kkp-block-note">Aucune réponse pour cette phase.</p>
      </div>`;
  }

  const series = kkpPhase === 'avant' ? 'avant' : 'apres';
  const rows = agg.likert.map(item => `
    <div>
      <div class="kkp-row-label">
        ${kkpEsc(item.label)} <span class="kkp-n">(n = ${item.n})</span>
      </div>
      ${kkpBarLine(item.avg, 5, series, kkpNum(item.avg))}
    </div>`).join('');

  return `
    <div class="kkp-block">
      <h3>Rapport au conflit — ${kkpPhase === 'avant' ? 'avant' : 'après'} la formation</h3>
      <p class="kkp-block-note">Moyenne de chaque affirmation, sur une échelle de 1 à 5.</p>
      <div class="kkp-rows">${rows}</div>
    </div>`;
}

function kkpReactionBlock(stats) {
  const reactions = stats.labels.reactions;
  const before = stats.phases.avant.reaction;
  const after = stats.phases.apres.reaction;
  const hasAfter = stats.phases.apres.total > 0;

  const maxCount = Math.max(
    1,
    ...Object.keys(reactions).map(k => Math.max(before[k] || 0, after[k] || 0))
  );

  const rows = Object.entries(reactions).map(([key, label]) => `
    <div>
      <div class="kkp-row-label">${kkpEsc(label)}</div>
      ${kkpBarLine(before[key] || 0, maxCount, 'avant', before[key] || 0)}
      ${hasAfter ? kkpBarLine(after[key] || 0, maxCount, 'apres', after[key] || 0) : ''}
    </div>`).join('');

  return `
    <div class="kkp-block">
      <h3>Façon de réagir au conflit</h3>
      <p class="kkp-block-note">Nombre de participants ayant choisi chaque réponse.</p>
      ${hasAfter ? `
        <div class="kkp-legend">
          <span><i class="kkp-swatch kkp-swatch-avant"></i> Avant la formation</span>
          <span><i class="kkp-swatch kkp-swatch-apres"></i> Après la formation</span>
        </div>` : ''}
      <div class="kkp-rows">${rows}</div>
    </div>`;
}

/**
 * Le profil n'est recueilli qu'au premier questionnaire : il décrit le groupe
 * une fois pour toutes, quelle que soit la phase affichée.
 */
function kkpProfileBlock(agg, labels) {
  if (agg.total === 0) return '';

  const buckets = {
    moins_18: 'Moins de 18 ans',
    '18_24': '18 à 24 ans',
    '25_30': '25 à 30 ans',
    plus_30: 'Plus de 30 ans'
  };

  const age = agg.age.avg === null
    ? ''
    : `<p class="kkp-block-note">Âge moyen : <strong>${agg.age.avg} ans</strong>
         (de ${agg.age.min} à ${agg.age.max} ans, sur ${agg.age.n} réponse(s)).</p>`;

  return `
    <div class="kkp-block">
      <h3>Profil du groupe</h3>
      <p class="kkp-block-note">
        Recueilli au questionnaire de début de formation, sur ${agg.total} réponse(s).
      </p>
      ${age}
      <div class="kkp-grid-2">
        ${kkpCountChart('Sexe', labels.genders, agg.gender)}
        ${kkpCountChart('Situation', labels.situations, agg.situation)}
        ${kkpCountChart("Tranche d'âge", buckets, agg.age_buckets)}
        ${kkpCountChart('Pratique artistique', labels.arts, agg.arts, true)}
        ${kkpCountChart('Déjà formé à la gestion des conflits', { oui: 'Oui', non: 'Non' }, agg.prior_training)}
      </div>
    </div>`;
}

function kkpFeedbackBlock(agg) {
  const rows = agg.satisfaction.map(item => `
    <div>
      <div class="kkp-row-label">
        ${kkpEsc(item.label)} <span class="kkp-n">(n = ${item.n})</span>
      </div>
      ${kkpBarLine(item.avg, 5, 'apres', kkpNum(item.avg))}
    </div>`).join('');

  return `
    <div class="kkp-block">
      <h3>Avis sur la formation</h3>
      <p class="kkp-block-note">Moyenne sur 5, recueillie au dernier jour.</p>
      <div class="kkp-rows">${rows}</div>
      <div class="kkp-grid-2" style="margin-top:var(--spacing-xl);">
        ${kkpCountChart('Pense pouvoir utiliser au quotidien',
          { oui: 'Oui', non: 'Non', pas_certain: 'Pas certain(e)' }, agg.can_apply)}
        ${kkpCountChart('Recommanderait la formation',
          { oui: 'Oui', non: 'Non', pas_certain: 'Pas certain(e)' }, agg.would_recommend)}
      </div>
    </div>`;
}

function kkpTestimonialsBlock(agg, labels) {
  if (!agg.testimonials.length) return '';

  const quotes = agg.testimonials.map(t => {
    const usable = t.consent === 'oui_nom' || t.consent === 'oui_anonyme';
    const consentLabel = labels.consents[t.consent] || 'Autorisation non renseignée';
    return `
      <div class="kkp-quote ${usable ? '' : 'kkp-quote-private'}">
        <p>« ${kkpEsc(t.text)} »</p>
        <div class="kkp-quote-meta">
          <span class="kkp-code-chip">${kkpEsc(t.code)}</span>
          <span>${t.name ? kkpEsc(t.name) : 'anonyme'}</span>
          <span>${usable ? '✓' : '⚠'} Diffusion : ${kkpEsc(consentLabel)}</span>
        </div>
      </div>`;
  }).join('');

  const usableCount = agg.testimonials.filter(
    t => t.consent === 'oui_nom' || t.consent === 'oui_anonyme'
  ).length;

  return `
    <div class="kkp-block">
      <h3>Témoignages</h3>
      <p class="kkp-block-note">
        ${usableCount} témoignage(s) sur ${agg.testimonials.length} peuvent être repris dans les documents
        du projet et transmis à la FOKAL. Ceux marqués d'un ⚠ ne doivent pas sortir de l'équipe :
        seuls les témoignages autorisés figurent dans le rapport PDF.
      </p>
      ${quotes}
    </div>`;
}

/**
 * L'art et le conflit : ce que le projet cherche a deplacer. Les deux
 * echelles ne se comparent pas — oui/non avant, quatre niveaux apres —
 * elles sont donc presentees separement et jamais mises sur le meme axe.
 */
function kkpArtBlock(stats) {
  const labels = stats.labels;
  const avant = stats.phases.avant;
  const apres = stats.phases.apres;
  if (avant.total === 0 && apres.total === 0) return '';

  const autres = (liste, titre) => (!liste || !liste.length) ? '' : `
    <div style="margin-top:var(--spacing-md);">
      <div class="kkp-row-label"><strong>${titre}</strong></div>
      ${liste.map(t => `<div class="kkp-quote"><p>${kkpEsc(t)}</p></div>`).join('')}
    </div>`;

  return `
    <div class="kkp-block">
      <h3>L'art et le conflit</h3>
      <p class="kkp-block-note">
        Les deux questionnaires posent cette question sur des échelles différentes
        — oui/non au début, quatre niveaux à la fin. Elles se lisent séparément :
        les mettre sur un même axe donnerait une progression qui n'existe pas.
      </p>
      <div class="kkp-grid-2">
        ${avant.total > 0 ? kkpCountChart(
          "Avant — l'art peut aider à gérer les émotions",
          labels.art_can_help, avant.art_can_help) : ''}
        ${apres.total > 0 ? kkpCountChart(
          "Après — l'art m'a aidé",
          labels.art_helped, apres.art_helped) : ''}
        ${avant.total > 0 ? kkpCountChart(
          "Avant — comment le participant utilise déjà l'art",
          labels.uses, avant.uses, true) : ''}
        ${apres.total > 0 ? kkpCountChart(
          'Après — stratégies envisagées',
          labels.strategies, apres.strategies, true) : ''}
      </div>
      ${autres(avant.other_texts?.uses, "Autres usages précisés (avant)")}
      ${autres(apres.other_texts?.strategies, 'Autres stratégies précisées (après)')}
    </div>`;
}

/**
 * Le sens donne a la demarche, avant puis apres, pour un meme participant.
 * C'est la seule lecture qualitative du changement : deux textes cote a cote.
 */
function kkpMeaningsBlock(cmp) {
  if (!cmp.meanings || !cmp.meanings.length) return '';

  const lignes = cmp.meanings.map(m => `
    <div class="kkp-meaning">
      <div class="kkp-meaning-code"><span class="kkp-code-chip">${kkpEsc(m.code)}</span></div>
      <div class="kkp-meaning-pair">
        <div>
          <div class="kkp-meaning-when"><i class="kkp-swatch kkp-swatch-avant"></i> Avant</div>
          <p>${m.before ? kkpEsc(m.before) : '<em>sans réponse</em>'}</p>
        </div>
        <div>
          <div class="kkp-meaning-when"><i class="kkp-swatch kkp-swatch-apres"></i> Après</div>
          <p>${m.after ? kkpEsc(m.after) : '<em>sans réponse</em>'}</p>
        </div>
      </div>
    </div>`).join('');

  return `
    <div class="kkp-block">
      <h3>« Gérer un conflit à travers l'art » — avant et après</h3>
      <p class="kkp-block-note">
        Ce que ${cmp.meanings.length} participant(s) apparié(s) mettent derrière la démarche,
        au premier jour puis au dernier. Les chiffres disent si le groupe progresse ;
        ces textes disent en quoi.
      </p>
      ${lignes}
    </div>`;
}

function kkpOrphanBlock(cmp) {
  if (!cmp.only_before.length && !cmp.only_after.length) return '';

  const list = (title, codes, note) => codes.length === 0 ? '' : `
    <div>
      <div class="kkp-row-label"><strong>${title}</strong> — ${note}</div>
      <div class="kkp-codes">${codes.map(c => `<span class="kkp-code-chip">${kkpEsc(c)}</span>`).join('')}</div>
    </div>`;

  return `
    <div class="kkp-block">
      <h3>Codes sans jumeau</h3>
      <p class="kkp-block-note">
        Ces codes n'apparaissent que dans une seule passation : ils sont exclus de la comparaison.
        Une faute de frappe sur le code en est souvent la cause.
      </p>
      <div class="kkp-rows">
        ${list('Avant seulement', cmp.only_before, "pas de questionnaire de fin")}
        ${list('Après seulement', cmp.only_after, "pas de questionnaire de début")}
      </div>
    </div>`;
}

function kkpTable(labels) {
  const rows = kkpResponses.responses.filter(r => r.phase === kkpPhase);

  if (!rows.length) {
    return `
      <div class="kkp-block">
        <h3>Réponses individuelles</h3>
        <p class="kkp-block-note">Aucune réponse pour cette phase.</p>
      </div>`;
  }

  const qKeys = Object.keys(labels.questions);

  const body = rows.map(r => {
    const scores = qKeys.map(k => r[k] ?? '—').join(' · ');
    const free = kkpPhase === 'avant'
      ? [r.art_conflict_meaning, r.expectations, r.special_needs]
      : [r.art_conflict_meaning, r.fav_activity, r.will_do_differently, r.improvements, r.testimonial];
    const freeText = free.filter(Boolean).join(' — ');

    return `
      <tr>
        <td><span class="kkp-code-chip">${kkpEsc(r.personal_code)}</span></td>
        <td>${r.age || '—'}</td>
        <td>${r.gender ? kkpEsc(labels.genders[r.gender] || r.gender) : '—'}</td>
        <td style="font-variant-numeric:tabular-nums;white-space:nowrap;">${scores}</td>
        <td>${r.reaction ? kkpEsc(labels.reactions[r.reaction] || r.reaction) : '—'}</td>
        <td class="kkp-longtext">${freeText ? kkpEsc(freeText) : '—'}</td>
        <td>${kkpEsc((r.created_at || '').slice(0, 16))}</td>
        <td>
          <button type="button" class="btn btn-ghost btn-sm" data-kkp-delete="${r.id}" title="Supprimer">
            <i class="ti ti-trash"></i>
          </button>
        </td>
      </tr>`;
  }).join('');

  return `
    <div class="kkp-block">
      <h3>Réponses individuelles — ${kkpPhase === 'avant' ? 'avant' : 'après'} la formation</h3>
      <p class="kkp-block-note">
        ${rows.length} réponse(s). Les sept chiffres sont les affirmations 1 à 7, dans l'ordre.
      </p>
      <div class="table-container">
        <table>
          <thead>
            <tr>
              <th>Code</th><th>Âge</th><th>Sexe</th><th>Affirmations 1→7</th>
              <th>Réaction</th><th>Texte libre</th><th>Reçu le</th><th></th>
            </tr>
          </thead>
          <tbody>${body}</tbody>
        </table>
      </div>
    </div>`;
}

// ---------- Briques de graphique ----------

/**
 * Une barre horizontale : piste, remplissage proportionnel, valeur en encre de texte.
 */
function kkpBarLine(value, max, series, valueText, extra = '') {
  const pct = (value === null || value === undefined || !max) ? 0 : Math.max(0, Math.min(100, (value / max) * 100));
  const minWidth = pct > 0 ? '2px' : '0';
  return `
    <div class="kkp-bar-line">
      <div class="kkp-track">
        <div class="kkp-fill kkp-fill-${series}" style="width:${pct.toFixed(1)}%;min-width:${minWidth}"></div>
      </div>
      <span class="kkp-value">${valueText === null || valueText === undefined ? '—' : valueText}</span>
      ${extra}
    </div>`;
}

/**
 * Répartition d'un effectif sur un référentiel : barres + effectif + part.
 * Une seule série, donc pas de légende — le titre nomme la mesure.
 */
function kkpCountChart(title, referential, counts, multiple = false) {
  const entries = Object.entries(referential);
  const values = entries.map(([key]) => counts[key] || 0);
  const max = Math.max(1, ...values);
  const total = values.reduce((sum, v) => sum + v, 0);

  const rows = entries.map(([key, label], i) => {
    const value = values[i];
    // Sur un choix multiple, un pourcentage du total n'aurait pas de sens
    const share = (!multiple && total > 0) ? ` <span class="kkp-n">${Math.round((value / total) * 100)} %</span>` : '';
    return `
      <div>
        <div class="kkp-row-label">${kkpEsc(label)}${share}</div>
        ${kkpBarLine(value, max, 'neutral', value)}
      </div>`;
  }).join('');

  const nr = counts.nr || 0;

  return `
    <div>
      <div class="kkp-row-label"><strong>${kkpEsc(title)}</strong></div>
      <div class="kkp-rows" style="margin-top:var(--spacing-sm);">${rows}</div>
      ${nr > 0 ? `<p class="kkp-tile-note">${nr} sans réponse</p>` : ''}
    </div>`;
}

// ---------- Interactions ----------

function wireKkpToolbar() {
  document.querySelectorAll('[data-kkp-phase]').forEach(button => {
    button.addEventListener('click', () => kkpSetPhase(button.dataset.kkpPhase));
  });

  document.querySelectorAll('[data-kkp-export]').forEach(button => {
    button.addEventListener('click', () => kkpExport(button.dataset.kkpExport, button));
  });

  document.querySelectorAll('[data-kkp-delete]').forEach(button => {
    button.addEventListener('click', () => kkpDelete(button.dataset.kkpDelete));
  });
}

/**
 * L'export passe par fetch : le téléchargement doit porter le jeton admin,
 * qu'un simple lien href n'enverrait pas.
 */
async function kkpExport(format, button) {
  const original = button.innerHTML;
  button.disabled = true;
  button.textContent = 'Préparation…';

  // Le rapport PDF couvre toujours les deux phases : il porte la comparaison
  const phaseParam = format === 'pdf' ? '' : `&phase=${kkpPhase}`;

  try {
    const response = await fetch(`${API_BASE_URL}/kkp/export?format=${format}${phaseParam}`, {
      headers: { 'Authorization': `Bearer ${api.token}` }
    });

    if (!response.ok) {
      let message = `Export impossible (${response.status})`;
      try { message = (await response.json()).message || message; } catch (e) {}
      throw new Error(message);
    }

    const blob = await response.blob();
    const name = kkpFilename(response, format);

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
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

function kkpFilename(response, format) {
  const header = response.headers.get('Content-Disposition') || '';
  const match = header.match(/filename="([^"]+)"/);
  if (match) return match[1];

  const ext = format === 'excel' ? 'xls' : format;
  return `koule-ki-pale-${new Date().toISOString().slice(0, 10)}.${ext}`;
}

async function kkpDelete(id) {
  if (!confirm('Supprimer définitivement cette réponse ? Cette action est irréversible.')) return;

  try {
    await api.request(`/kkp/responses/${id}`, { method: 'DELETE' });
    await loadKkp();
  } catch (error) {
    alert(error.message || 'Suppression impossible.');
  }
}

// ---------- Utilitaires ----------

function kkpNum(value) {
  if (value === null || value === undefined) return '—';
  return Number(value).toFixed(2).replace('.', ',');
}

function kkpEsc(value) {
  const div = document.createElement('div');
  div.textContent = String(value ?? '');
  return div.innerHTML;
}
