// ============================================
// KOULÈ KI PALE — questionnaires avant / après
// Une seule page sert les deux passations : ?phase=avant (défaut) ou ?phase=apres
// ============================================

// Les clés doivent rester identiques à celles de api/routes/kkp.php.
// Elles sont recopiées ici plutôt que chargées de l'API pour que le formulaire
// s'affiche instantanément, même sur une connexion capricieuse.
const KKP = {
  questions: [
    "Je sais reconnaître les signes d'un conflit qui monte.",
    "Je sais nommer ce que je ressens dans une situation de conflit.",
    "Je connais des moyens de régler un conflit sans violence.",
    "J'arrive à écouter le point de vue de quelqu'un avec qui je suis en désaccord.",
    "Je me sens capable d'exprimer mon désaccord sans agresser l'autre.",
    "Je connais mes droits et les recours possibles face à un conflit.",
    "Je me sens capable de prendre la parole en public sur ce que je vis."
  ],
  satisfaction: [
    "Dans l'ensemble, je suis satisfait(e) de la formation.",
    "Les activités étaient intéressantes.",
    "La facilitatrice et les intervenants étaient clairs et disponibles.",
    "Je me suis senti(e) en sécurité dans le groupe.",
    "J'ai appris des choses utiles pour moi.",
    "Les interventions sur le droit (CALSDH, ASF Canada) m'ont été utiles.",
    "L'organisation (salle, horaires, matériel) était satisfaisante."
  ],
  gender: [
    ['feminin', 'Féminin'],
    ['masculin', 'Masculin'],
    ['autre', 'Autre']
  ],
  situation: [
    ['etudes', 'En études'],
    ['emploi', 'En emploi'],
    ['recherche', "En recherche d'emploi"],
    ['autre', 'Autre']
  ],
  prior_training: [
    ['1', 'Oui'],
    ['0', 'Non']
  ],
  arts: [
    ['art_drawing', 'Dessin ou peinture'],
    ['art_theatre', 'Théâtre'],
    ['art_music', 'Musique ou chant'],
    ['art_writing', 'Écriture ou slam'],
    ['art_other', 'Autre'],
    ['art_none', 'Aucune']
  ],
  reaction: [
    ['evite', "J'évite la situation ou je laisse passer."],
    ['cede', "Je cède pour préserver la relation."],
    ['impose', "J'impose mon point de vue."],
    ['compromis', "Je cherche un compromis où chacun lâche un peu."],
    ['collabore', "Je cherche avec l'autre une solution qui convient aux deux."]
  ],
  can_apply: [
    ['oui', 'Oui'],
    ['non', 'Non'],
    ['pas_certain', 'Pas certain(e)']
  ],
  testimonial_consent: [
    ['oui_nom', 'Oui, avec mon nom'],
    ['oui_anonyme', 'Oui, sans mon nom'],
    ['non', 'Non']
  ],
  // Avant : oui/non. Après : quatre niveaux. Les deux échelles diffèrent à
  // dessein, elles ne se comparent pas.
  art_can_help: [
    ['oui', 'Oui'],
    ['non', 'Non']
  ],
  art_helped: [
    ['beaucoup', 'Beaucoup'],
    ['un_peu', 'Un peu'],
    ['pas_vraiment', 'Pas vraiment'],
    ['pas_du_tout', 'Pas du tout']
  ],
  uses: [
    ['use_draw', "Je dessine ou je peins ce que je ressens."],
    ['use_write', "J'écris (texte, poème, slam, journal)."],
    ['use_music', "J'écoute de la musique, je chante ou je joue d'un instrument."],
    ['use_dance', "Je danse ou je joue une scène."],
    ['use_photo', "Je fais des photos ou des vidéos."],
    ['use_none', "Je n'utilise pas l'art pour cela."],
    ['use_other', "Autre"]
  ],
  strategies: [
    ['strategy_listen', "Écouter l'autre."],
    ['strategy_art', "Exprimer ses émotions par l'art."],
    ['strategy_dialogue', "Dialoguer calmement."],
    ['strategy_other', "Autre"]
  ]
};
KKP.would_recommend = KKP.can_apply;

const PHASE_TEXT = {
  avant: {
    title: 'Questionnaire avant la formation',
    deadline: "À remplir avant le début des activités, au plus tard à l'accueil du lundi 21 septembre 2026",
    intro: [
      "Ce questionnaire ne te note pas. Il nous aide à mieux connaître le groupe et, comparé au questionnaire de fin de formation, à mesurer ce que ces quatre jours auront changé. Ton nom n'est pas demandé. Il n'y a pas de bonne ou de mauvaise réponse."
    ],
    codeHint: "Ton code personnel : les deux premières lettres de ton prénom suivies de ton jour de naissance (exemple : MA14 pour Maya, née un 14). Le même code figure sur les deux questionnaires, ce qui permet de les rapprocher sans connaître ton nom.",
    done: "Tes réponses sont enregistrées. On se retrouve à la formation."
  },
  apres: {
    title: 'Évaluation de fin de formation',
    deadline: 'À remplir le dernier jour, le jeudi 24 septembre 2026',
    intro: [
      "Réponds honnêtement : il n'y a pas de bonne ou de mauvaise réponse, et tes réponses nous aideront à améliorer le programme. Les parties 1 à 3 reprennent les questions du questionnaire de début de formation, pour mesurer ce qui a changé. Ton nom n'est pas demandé.",
      "Inscris le même code personnel que sur ton premier questionnaire : les deux premières lettres de ton prénom suivies de ton jour de naissance."
    ],
    codeHint: "Inscris exactement le même code que sur ton premier questionnaire (exemple : MA14 pour Maya, née un 14). C'est lui qui permet de rapprocher tes deux réponses sans connaître ton nom.",
    done: "Tes réponses sont enregistrées. Merci pour ces quatre jours."
  }
};

const phase = (new URLSearchParams(window.location.search).get('phase') === 'apres')
  ? 'apres'
  : 'avant';

document.addEventListener('DOMContentLoaded', () => {
  applyPhase();
  renderChoiceGroups();
  renderScale('kkp-questions', 'q', KKP.questions);
  renderScale('kkp-satisfaction', 's', KKP.satisfaction);
  wireConsentField();
  wireOtherFields();
  wireCodeField();
  document.getElementById('kkp-form').addEventListener('submit', onSubmit);
});

// ---------- Mise en place selon la phase ----------

function applyPhase() {
  const text = PHASE_TEXT[phase];

  document.title = text.title + ' — Koulè Ki Pale | DevDynamics';
  document.getElementById('kkp-title').textContent = text.title;
  document.getElementById('kkp-deadline').textContent = text.deadline;
  document.getElementById('kkp-intro').innerHTML =
    text.intro.map(p => `<p>${escapeHtml(p)}</p>`).join('');
  document.getElementById('kkp-code-hint').textContent = text.codeHint;

  // Les blocs marqués d'une autre phase sont retirés du document : laisser
  // des champs cachés dans le formulaire finirait par les faire remonter.
  document.querySelectorAll('[data-phase]').forEach(el => {
    if (el.dataset.phase !== phase) el.remove();
  });

  if (phase === 'apres') {
    // Le questionnaire de fin ne numérote pas le code personnel : la
    // numérotation des parties commence au rapport au conflit.
    document.getElementById('kkp-s1-title').textContent = 'Ton code personnel';
    document.getElementById('kkp-n-code').remove();
    document.getElementById('kkp-n-conflit').textContent = '1';
    document.getElementById('kkp-n-reaction').textContent = '2';
  }
}

// ---------- Rendu des groupes de choix ----------

function renderChoiceGroups() {
  document.querySelectorAll('[data-radio]').forEach(container => {
    const name = container.dataset.radio;
    const options = KKP[name] || [];
    container.innerHTML = options.map(([value, label]) => `
      <label class="kkp-choice">
        <input type="radio" name="${name}" value="${escapeHtml(value)}">
        <span>${escapeHtml(label)}</span>
      </label>
    `).join('');
  });

  document.querySelectorAll('[data-checkbox]').forEach(container => {
    const options = KKP[container.dataset.checkbox] || [];
    container.innerHTML = options.map(([value, label]) => `
      <label class="kkp-choice">
        <input type="checkbox" name="${escapeHtml(value)}" value="1">
        <span>${escapeHtml(label)}</span>
      </label>
    `).join('');
  });

  // Une reponse « aucune » exclut les autres cases du meme groupe
  wireExclusiveNone('[data-checkbox="arts"]', 'art_none');
  wireExclusiveNone('[data-checkbox="uses"]', 'use_none');
}

function renderScale(containerId, prefix, statements) {
  const container = document.getElementById(containerId);
  if (!container) return;

  container.innerHTML = statements.map((statement, index) => {
    const num = index + 1;
    const name = `${prefix}${num}`;
    const scale = [1, 2, 3, 4, 5].map(value => `
      <label>
        <input type="radio" name="${name}" value="${value}"
               aria-label="${escapeHtml(statement)} — note ${value} sur 5">
        <span aria-hidden="true">${value}</span>
      </label>
    `).join('');

    return `
      <fieldset class="kkp-statement" data-field="${name}">
        <legend class="kkp-statement-text" data-num="${num}">${escapeHtml(statement)}</legend>
        <div class="kkp-scale">${scale}</div>
      </fieldset>
    `;
  }).join('');

  // Un énoncé signalé comme manquant redevient neutre dès qu'on y répond
  container.addEventListener('change', event => {
    const statement = event.target.closest('.kkp-statement');
    if (statement) statement.classList.remove('kkp-missing');
  });
}

function wireConsentField() {
  const field = document.getElementById('kkp-name-field');
  if (!field) return;

  document.querySelectorAll('input[name="testimonial_consent"]').forEach(input => {
    input.addEventListener('change', () => {
      const wantsName = document.querySelector('input[name="testimonial_consent"]:checked')?.value === 'oui_nom';
      field.hidden = !wantsName;
      // Sans l'accord de citation, le nom ne doit même pas partir au serveur
      if (!wantsName) document.getElementById('kkp-name').value = '';
    });
  });
}

/**
 * Le champ libre d'un « Autre » n'apparait que si la case est cochee, et se
 * vide sinon : un texte laisse derriere une case decochee partirait au
 * serveur sans que personne ne l'ait voulu.
 */
function wireOtherFields() {
  [
    ['use_other', 'kkp-use-other-field', 'kkp-use-other'],
    ['strategy_other', 'kkp-strategy-other-field', 'kkp-strategy-other']
  ].forEach(([caseName, fieldId, inputId]) => {
    const field = document.getElementById(fieldId);
    const input = document.getElementById(inputId);
    const box = document.querySelector(`input[name="${caseName}"]`);
    if (!field || !input || !box) return;

    const refresh = () => {
      field.hidden = !box.checked;
      if (!box.checked) input.value = '';
    };
    box.addEventListener('change', refresh);
    refresh();
  });
}

/** « Je n'utilise pas l'art pour cela » exclut les autres usages. */
function wireExclusiveNone(groupSelector, noneName) {
  const none = document.querySelector(`input[name="${noneName}"]`);
  if (!none) return;
  const others = Array.from(document.querySelectorAll(`${groupSelector} input`))
    .filter(input => input !== none);
  none.addEventListener('change', () => {
    if (none.checked) others.forEach(o => {
      o.checked = false;
      o.dispatchEvent(new Event('change'));
    });
  });
  others.forEach(o => o.addEventListener('change', () => {
    if (o.checked) none.checked = false;
  }));
}

function wireCodeField() {
  const code = document.getElementById('kkp-code');
  code.addEventListener('input', () => {
    // Le code est stocké en majuscules : on l'aligne dès la saisie
    const cleaned = code.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (cleaned !== code.value) code.value = cleaned;
  });
}

// ---------- Envoi ----------

async function onSubmit(event) {
  event.preventDefault();

  const submit = document.getElementById('kkp-submit');
  const feedback = document.getElementById('kkp-feedback');
  feedback.className = 'kkp-feedback';
  feedback.textContent = '';

  const code = document.getElementById('kkp-code').value.trim();
  if (!/^[A-Z0-9]{3,12}$/.test(code)) {
    return fail('Indique ton code personnel : 3 à 12 lettres ou chiffres, par exemple MA14.',
                document.getElementById('kkp-code'));
  }

  // Les affirmations sont l'instrument de mesure : aucune ne peut rester vide
  const scales = { q: KKP.questions.length };
  if (phase === 'apres') scales.s = KKP.satisfaction.length;

  let firstMissing = null;
  for (const [prefix, count] of Object.entries(scales)) {
    for (let i = 1; i <= count; i++) {
      const name = `${prefix}${i}`;
      if (!document.querySelector(`input[name="${name}"]:checked`)) {
        const statement = document.querySelector(`[data-field="${name}"]`);
        if (statement) statement.classList.add('kkp-missing');
        if (!firstMissing) firstMissing = statement;
      }
    }
  }
  if (firstMissing) {
    return fail('Il reste des affirmations sans réponse. Elles sont encadrées en rouge.', firstMissing);
  }

  const payload = collect(code);

  submit.disabled = true;
  submit.textContent = 'Envoi en cours…';

  try {
    await api.request('/kkp/responses', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    showDone(code);
  } catch (error) {
    submit.disabled = false;
    submit.textContent = 'Envoyer mes réponses';
    fail(error.message || "L'envoi a échoué. Vérifie ta connexion et réessaie.");
  }
}

function collect(code) {
  const payload = { phase, personal_code: code, website: document.getElementById('kkp-website')?.value || '' };

  const radio = name => document.querySelector(`input[name="${name}"]:checked`)?.value ?? null;
  const field = id => document.getElementById(id)?.value.trim() || null;

  for (let i = 1; i <= KKP.questions.length; i++) {
    payload[`q${i}`] = radio(`q${i}`);
  }
  payload.reaction = radio('reaction');

  // Meme question dans les deux questionnaires, donc meme champ : c'est la
  // phase qui distingue les deux reponses d'un meme participant.
  payload.art_conflict_meaning = field(phase === 'avant' ? 'kkp-sens' : 'kkp-sens-apres');

  const checkbox = name => document.querySelector(`input[name="${name}"]`)?.checked ? 1 : 0;

  if (phase === 'avant') {
    payload.age = field('kkp-age');
    payload.gender = radio('gender');
    payload.situation = radio('situation');
    payload.prior_training = radio('prior_training');
    KKP.arts.forEach(([key]) => { payload[key] = checkbox(key); });
    payload.art_can_help = radio('art_can_help');
    KKP.uses.forEach(([key]) => { payload[key] = checkbox(key); });
    payload.use_other_text = field('kkp-use-other');
    payload.expectations = field('kkp-expectations');
    payload.special_needs = field('kkp-needs');
  } else {
    for (let i = 1; i <= KKP.satisfaction.length; i++) {
      payload[`s${i}`] = radio(`s${i}`);
    }
    payload.art_helped = radio('art_helped');
    KKP.strategies.forEach(([key]) => { payload[key] = checkbox(key); });
    payload.strategy_other_text = field('kkp-strategy-other');
    payload.fav_activity = field('kkp-fav');
    payload.will_do_differently = field('kkp-different');
    payload.improvements = field('kkp-improve');
    payload.can_apply = radio('can_apply');
    payload.would_recommend = radio('would_recommend');
    payload.testimonial = field('kkp-testimonial');
    payload.testimonial_consent = radio('testimonial_consent');
    payload.testimonial_name = radio('testimonial_consent') === 'oui_nom' ? field('kkp-name') : null;
  }

  return payload;
}

function showDone(code) {
  document.getElementById('kkp-form').hidden = true;
  const done = document.getElementById('kkp-done');
  document.getElementById('kkp-done-text').textContent = PHASE_TEXT[phase].done;
  document.getElementById('kkp-done-code').textContent = code;
  done.hidden = false;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function fail(message, focusTarget) {
  const feedback = document.getElementById('kkp-feedback');
  feedback.className = 'kkp-feedback alert alert-error';
  feedback.textContent = message;
  if (focusTarget) {
    focusTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (focusTarget.focus) focusTarget.focus({ preventScroll: true });
  }
}

function escapeHtml(value) {
  const div = document.createElement('div');
  div.textContent = String(value ?? '');
  return div.innerHTML;
}
