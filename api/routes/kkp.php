<?php
/**
 * Koule Ki Pale Routes — questionnaires avant / apres formation
 *
 * POST   /api/kkp/responses      - Soumettre un questionnaire (public)
 * GET    /api/kkp/stats          - Agregats et comparaison appariee (admin)
 * GET    /api/kkp/responses      - Reponses individuelles (admin)
 * DELETE /api/kkp/responses/:id  - Supprimer une reponse (admin)
 * GET    /api/kkp/export         - Export csv | excel | pdf (admin)
 */

// ---------- Referentiels partages par l'API, les exports et le front ----------

function kkp_questions() {
    return [
        'q1' => "Je sais reconnaître les signes d'un conflit qui monte.",
        'q2' => "Je sais nommer ce que je ressens dans une situation de conflit.",
        'q3' => "Je connais des moyens de régler un conflit sans violence.",
        'q4' => "J'arrive à écouter le point de vue de quelqu'un avec qui je suis en désaccord.",
        'q5' => "Je me sens capable d'exprimer mon désaccord sans agresser l'autre.",
        'q6' => "Je connais mes droits et les recours possibles face à un conflit.",
        'q7' => "Je me sens capable de prendre la parole en public sur ce que je vis.",
    ];
}

/**
 * Partie 3 du questionnaire de fin : l'avis des participants sur la formation.
 */
function kkp_satisfaction() {
    return [
        's1' => "Dans l'ensemble, je suis satisfait(e) de la formation.",
        's2' => "Les activités étaient intéressantes.",
        's3' => "La facilitatrice et les intervenants étaient clairs et disponibles.",
        's4' => "Je me suis senti(e) en sécurité dans le groupe.",
        's5' => "J'ai appris des choses utiles pour moi.",
        's6' => "Les interventions sur le droit (CALSDH, ASF Canada) m'ont été utiles.",
        's7' => "L'organisation (salle, horaires, matériel) était satisfaisante.",
    ];
}

function kkp_choices() {
    return ['oui' => 'Oui', 'non' => 'Non', 'pas_certain' => 'Pas certain(e)'];
}

function kkp_consents() {
    return [
        'oui_nom'      => 'Oui, avec mon nom',
        'oui_anonyme'  => 'Oui, sans mon nom',
        'non'          => 'Non',
    ];
}

function kkp_reactions() {
    return [
        'evite'     => "J'évite la situation ou je laisse passer.",
        'cede'      => "Je cède pour préserver la relation.",
        'impose'    => "J'impose mon point de vue.",
        'compromis' => "Je cherche un compromis où chacun lâche un peu.",
        'collabore' => "Je cherche avec l'autre une solution qui convient aux deux.",
    ];
}

function kkp_genders() {
    return ['feminin' => 'Féminin', 'masculin' => 'Masculin', 'autre' => 'Autre'];
}

function kkp_situations() {
    return [
        'etudes'    => 'En études',
        'emploi'    => 'En emploi',
        'recherche' => "En recherche d'emploi",
        'autre'     => 'Autre',
    ];
}

function kkp_arts() {
    return [
        'art_drawing' => 'Dessin ou peinture',
        'art_theatre' => 'Théâtre',
        'art_music'   => 'Musique ou chant',
        'art_writing' => 'Écriture ou slam',
        'art_other'   => 'Autre',
        'art_none'    => 'Aucune',
    ];
}

function kkp_phases() {
    return ['avant' => 'Avant la formation', 'apres' => 'Après la formation'];
}

/**
 * Tous les libelles, pour que le front et les exports parlent la meme langue.
 */
function kkp_labels() {
    return [
        'questions'    => kkp_questions(),
        'satisfaction' => kkp_satisfaction(),
        'reactions'    => kkp_reactions(),
        'genders'      => kkp_genders(),
        'situations'   => kkp_situations(),
        'arts'         => kkp_arts(),
        'choices'      => kkp_choices(),
        'consents'     => kkp_consents(),
        'phases'       => kkp_phases(),
    ];
}

// ---------- Helpers ----------

/**
 * Normalise une valeur d'enumeration : renvoie null si absente ou hors liste.
 */
function kkp_enum($value, array $allowed) {
    if ($value === null || $value === '') return null;
    $value = strtolower(trim((string) $value));
    return in_array($value, $allowed, true) ? $value : null;
}

/**
 * Normalise une reponse de Likert : entier 1..5, sinon null.
 */
function kkp_likert($value) {
    if ($value === null || $value === '') return null;
    $n = (int) $value;
    return ($n >= 1 && $n <= 5) ? $n : null;
}

function kkp_bool($value) {
    if ($value === null || $value === '') return null;
    if (is_bool($value)) return $value ? 1 : 0;
    $v = strtolower(trim((string) $value));
    if (in_array($v, ['1', 'true', 'oui', 'yes'], true)) return 1;
    if (in_array($v, ['0', 'false', 'non', 'no'], true)) return 0;
    return null;
}

function kkp_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function kkp_ip_hash() {
    return hash('sha256', kkp_client_ip() . '|koule-ki-pale');
}

/**
 * Phase demandee en filtre : 'avant', 'apres', ou null pour les deux.
 */
function kkp_phase_filter() {
    $phase = Router::getQueryParam('phase');
    return kkp_enum($phase, ['avant', 'apres']);
}

function kkp_fetch_rows($db, $phase = null) {
    if ($phase) {
        return $db->fetchAll(
            "SELECT * FROM kkp_responses WHERE phase = ? ORDER BY created_at DESC",
            [$phase]
        );
    }
    return $db->fetchAll("SELECT * FROM kkp_responses ORDER BY created_at DESC");
}

/**
 * Compte les occurrences d'une colonne en respectant l'ordre du referentiel.
 * Les lignes sans valeur sont comptees sous la cle 'nr' (non renseigne).
 */
function kkp_count_by(array $rows, $column, array $referential) {
    $counts = [];
    foreach (array_keys($referential) as $key) {
        $counts[$key] = 0;
    }
    $counts['nr'] = 0;

    foreach ($rows as $row) {
        $value = $row[$column] ?? null;
        if ($value !== null && $value !== '' && array_key_exists($value, $counts)) {
            $counts[$value]++;
        } else {
            $counts['nr']++;
        }
    }
    return $counts;
}

/**
 * Moyenne et distribution 1..5 pour un jeu d'affirmations.
 * Sert aussi bien au rapport au conflit (q1..q7) qu'a l'avis sur la formation (s1..s7).
 */
function kkp_likert_stats(array $rows, array $questions) {
    $stats = [];
    foreach ($questions as $key => $label) {
        $values = [];
        $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $row) {
            $v = kkp_likert($row[$key] ?? null);
            if ($v !== null) {
                $values[] = $v;
                $dist[$v]++;
            }
        }
        $stats[] = [
            'key'   => $key,
            'label' => $label,
            'n'     => count($values),
            'avg'   => $values ? round(array_sum($values) / count($values), 2) : null,
            'dist'  => array_values($dist),
        ];
    }
    return $stats;
}

/**
 * Agregats d'une phase : profil du groupe, moyennes de Likert, styles de reaction.
 */
function kkp_aggregate(array $rows) {
    $questions = kkp_questions();

    // Profil : ages
    $ages = [];
    foreach ($rows as $row) {
        if ($row['age'] !== null && $row['age'] !== '') $ages[] = (int) $row['age'];
    }
    sort($ages);
    $ageStats = [
        'n'   => count($ages),
        'avg' => $ages ? round(array_sum($ages) / count($ages), 1) : null,
        'min' => $ages ? $ages[0] : null,
        'max' => $ages ? $ages[count($ages) - 1] : null,
    ];

    // Profil : tranches d'age
    $buckets = ['moins_18' => 0, '18_24' => 0, '25_30' => 0, 'plus_30' => 0, 'nr' => 0];
    foreach ($rows as $row) {
        $age = $row['age'];
        if ($age === null || $age === '') { $buckets['nr']++; continue; }
        $age = (int) $age;
        if ($age < 18)      $buckets['moins_18']++;
        elseif ($age <= 24) $buckets['18_24']++;
        elseif ($age <= 30) $buckets['25_30']++;
        else                $buckets['plus_30']++;
    }

    // Pratiques artistiques (choix multiple : une ligne peut compter plusieurs fois)
    $arts = [];
    foreach (array_keys(kkp_arts()) as $key) {
        $arts[$key] = 0;
        foreach ($rows as $row) {
            if (!empty($row[$key])) $arts[$key]++;
        }
    }

    // Formation anterieure sur la gestion des conflits
    $prior = ['oui' => 0, 'non' => 0, 'nr' => 0];
    foreach ($rows as $row) {
        $value = $row['prior_training'];
        if ($value === null || $value === '') $prior['nr']++;
        elseif ((int) $value === 1)           $prior['oui']++;
        else                                  $prior['non']++;
    }

    // Echelle de Likert : moyenne et distribution par affirmation
    $likert = kkp_likert_stats($rows, $questions);

    // Score global : moyenne des 7 affirmations, toutes reponses confondues
    $allValues = [];
    foreach ($rows as $row) {
        foreach (array_keys($questions) as $key) {
            $v = kkp_likert($row[$key] ?? null);
            if ($v !== null) $allValues[] = $v;
        }
    }

    // Avis sur la formation : seul le questionnaire de fin le renseigne
    $satisfaction = kkp_likert_stats($rows, kkp_satisfaction());
    $satValues = [];
    foreach ($rows as $row) {
        foreach (array_keys(kkp_satisfaction()) as $key) {
            $v = kkp_likert($row[$key] ?? null);
            if ($v !== null) $satValues[] = $v;
        }
    }

    // Temoignages : on ne retient que ceux dont la diffusion est autorisee,
    // et le nom seulement quand il a ete explicitement accorde.
    $testimonials = [];
    foreach ($rows as $row) {
        $text = trim((string) ($row['testimonial'] ?? ''));
        if ($text === '') continue;
        $consent = $row['testimonial_consent'] ?? null;
        $testimonials[] = [
            'code'    => $row['personal_code'],
            'text'    => $text,
            'consent' => $consent,
            'name'    => ($consent === 'oui_nom') ? ($row['testimonial_name'] ?: null) : null,
        ];
    }

    return [
        'satisfaction' => $satisfaction,
        'satisfaction_global' => $satValues ? round(array_sum($satValues) / count($satValues), 2) : null,
        'can_apply'       => kkp_count_by($rows, 'can_apply', kkp_choices()),
        'would_recommend' => kkp_count_by($rows, 'would_recommend', kkp_choices()),
        'testimonial_consent' => kkp_count_by($rows, 'testimonial_consent', kkp_consents()),
        'testimonials' => $testimonials,
        'total'     => count($rows),
        'age'       => $ageStats,
        'age_buckets' => $buckets,
        'gender'    => kkp_count_by($rows, 'gender', kkp_genders()),
        'situation' => kkp_count_by($rows, 'situation', kkp_situations()),
        'prior_training' => $prior,
        'arts'      => $arts,
        'likert'    => $likert,
        'likert_global' => $allValues ? round(array_sum($allValues) / count($allValues), 2) : null,
        'reaction'  => kkp_count_by($rows, 'reaction', kkp_reactions()),
    ];
}

/**
 * Comparaison avant / apres, restreinte aux codes personnels presents dans
 * les deux passations. C'est la seule mesure honnete du changement : comparer
 * des groupes differents melangerait l'effet de la formation et celui des absents.
 */
function kkp_compare(array $before, array $after) {
    $questions = kkp_questions();

    $beforeByCode = [];
    foreach ($before as $row) { $beforeByCode[$row['personal_code']] = $row; }
    $afterByCode = [];
    foreach ($after as $row) { $afterByCode[$row['personal_code']] = $row; }

    $pairedCodes = array_values(array_intersect(
        array_keys($beforeByCode),
        array_keys($afterByCode)
    ));
    sort($pairedCodes);

    $likert = [];
    foreach ($questions as $key => $label) {
        $pairs = [];
        foreach ($pairedCodes as $code) {
            $b = kkp_likert($beforeByCode[$code][$key] ?? null);
            $a = kkp_likert($afterByCode[$code][$key] ?? null);
            if ($b !== null && $a !== null) $pairs[] = [$b, $a];
        }
        $n = count($pairs);
        $avgBefore = $n ? array_sum(array_column($pairs, 0)) / $n : null;
        $avgAfter  = $n ? array_sum(array_column($pairs, 1)) / $n : null;

        // Nombre de participants dont le score progresse, stagne ou recule
        $up = $flat = $down = 0;
        foreach ($pairs as [$b, $a]) {
            if ($a > $b) $up++;
            elseif ($a < $b) $down++;
            else $flat++;
        }

        $likert[] = [
            'key'        => $key,
            'label'      => $label,
            'n'          => $n,
            'avg_before' => $avgBefore === null ? null : round($avgBefore, 2),
            'avg_after'  => $avgAfter === null ? null : round($avgAfter, 2),
            'delta'      => ($avgBefore === null || $avgAfter === null)
                            ? null : round($avgAfter - $avgBefore, 2),
            'progress'   => ['up' => $up, 'flat' => $flat, 'down' => $down],
        ];
    }

    // Ce que la demarche veut dire, avant puis apres. C'est la seule mesure
    // qualitative du changement : deux textes du meme participant, a lire
    // l'un a cote de l'autre.
    $meanings = [];
    foreach ($pairedCodes as $code) {
        $avant = trim((string) ($beforeByCode[$code]['art_conflict_meaning'] ?? ''));
        $apres = trim((string) ($afterByCode[$code]['art_conflict_meaning'] ?? ''));
        if ($avant !== '' || $apres !== '') {
            $meanings[] = ['code' => $code, 'before' => $avant ?: null, 'after' => $apres ?: null];
        }
    }

    // Deplacement des styles de reaction au conflit
    $reactionShift = [];
    foreach ($pairedCodes as $code) {
        $b = $beforeByCode[$code]['reaction'] ?? null;
        $a = $afterByCode[$code]['reaction'] ?? null;
        if ($b && $a && $b !== $a) {
            $reactionShift[] = ['code' => $code, 'from' => $b, 'to' => $a];
        }
    }

    // Codes orphelins : utiles pour relancer ou nettoyer la saisie
    $onlyBefore = array_values(array_diff(array_keys($beforeByCode), $pairedCodes));
    $onlyAfter  = array_values(array_diff(array_keys($afterByCode), $pairedCodes));
    sort($onlyBefore);
    sort($onlyAfter);

    return [
        'paired'         => count($pairedCodes),
        'paired_codes'   => $pairedCodes,
        'only_before'    => $onlyBefore,
        'only_after'     => $onlyAfter,
        'likert'         => $likert,
        'reaction_shift' => $reactionShift,
        'meanings'       => $meanings,
    ];
}

// ---------- Routes ----------

$router = Router::getInstance();
$db = Database::getInstance();

/**
 * Soumission publique du questionnaire.
 */
$router->post('\/kkp/responses', function($params) use ($db) {
    $body = Router::getBody();

    // Piege a robots : un champ invisible que seul un script remplit
    if (!empty($body['website'])) {
        Response::success(null, 'Reponse enregistree', 201);
    }

    $phase = kkp_enum($body['phase'] ?? 'avant', ['avant', 'apres']);
    if ($phase === null) {
        Response::error('Phase invalide', 400);
    }

    $code = strtoupper(trim((string) ($body['personal_code'] ?? '')));
    if ($code === '') {
        Response::error('Le code personnel est obligatoire', 400);
    }
    if (!preg_match('/^[A-Z0-9]{3,12}$/', $code)) {
        Response::error(
            'Le code personnel doit contenir de 3 a 12 lettres ou chiffres, sans espace ni accent (exemple : MA14)',
            400
        );
    }

    // Les 7 affirmations sont l'instrument de mesure : sans elles la ligne
    // n'apporte rien a la comparaison avant / apres.
    $likert = [];
    $missing = [];
    foreach (array_keys(kkp_questions()) as $key) {
        $value = kkp_likert($body[$key] ?? null);
        if ($value === null) $missing[] = $key;
        $likert[$key] = $value;
    }
    if ($missing) {
        Response::error(
            'Merci de repondre a toutes les affirmations (manquantes : ' . implode(', ', $missing) . ')',
            400
        );
    }

    $age = null;
    if (isset($body['age']) && $body['age'] !== '') {
        $age = (int) $body['age'];
        if ($age < 5 || $age > 120) {
            Response::error('Age invalide', 400);
        }
    }

    $arts = [];
    foreach (array_keys(kkp_arts()) as $key) {
        $arts[$key] = !empty($body[$key]) ? 1 : 0;
    }

    // Avis sur la formation : facultatif, et sans objet avant la formation
    $satisfaction = [];
    foreach (array_keys(kkp_satisfaction()) as $key) {
        $satisfaction[$key] = ($phase === 'apres') ? kkp_likert($body[$key] ?? null) : null;
    }

    $consent = kkp_enum($body['testimonial_consent'] ?? null, array_keys(kkp_consents()));
    // Le nom n'est conserve que si le participant a accepte d'etre cite.
    // Sans cet accord, le garder ne servirait qu'a le trahir.
    $testimonialName = ($consent === 'oui_nom' && !empty($body['testimonial_name']))
        ? mb_substr(trim((string) $body['testimonial_name']), 0, 120)
        : null;

    $text = function($key) use ($body) {
        return (isset($body[$key]) && trim((string) $body[$key]) !== '')
            ? trim((string) $body[$key])
            : null;
    };

    $ipHash = kkp_ip_hash();

    try {
        // Garde-fou anti-flood : 15 soumissions par IP et par quart d'heure
        $recent = $db->fetchOne(
            "SELECT COUNT(*) AS n FROM kkp_responses
             WHERE ip_hash = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)",
            [$ipHash]
        );
        if ($recent && (int) $recent['n'] >= 15) {
            Response::error('Trop de soumissions depuis cet appareil. Reessaie dans quelques minutes.', 429);
        }

        $db->query(
            "INSERT INTO kkp_responses
                (phase, personal_code, age, gender, situation, prior_training,
                 art_drawing, art_theatre, art_music, art_writing, art_other, art_none,
                 q1, q2, q3, q4, q5, q6, q7,
                 reaction, art_conflict_meaning, expectations, special_needs,
                 s1, s2, s3, s4, s5, s6, s7,
                 fav_activity, will_do_differently, improvements,
                 can_apply, would_recommend,
                 testimonial, testimonial_consent, testimonial_name,
                 ip_hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $phase,
                $code,
                $age,
                kkp_enum($body['gender'] ?? null, array_keys(kkp_genders())),
                kkp_enum($body['situation'] ?? null, array_keys(kkp_situations())),
                kkp_bool($body['prior_training'] ?? null),
                $arts['art_drawing'], $arts['art_theatre'], $arts['art_music'],
                $arts['art_writing'], $arts['art_other'], $arts['art_none'],
                $likert['q1'], $likert['q2'], $likert['q3'], $likert['q4'],
                $likert['q5'], $likert['q6'], $likert['q7'],
                kkp_enum($body['reaction'] ?? null, array_keys(kkp_reactions())),
                $text('art_conflict_meaning'),
                $text('expectations'),
                $text('special_needs'),
                $satisfaction['s1'], $satisfaction['s2'], $satisfaction['s3'], $satisfaction['s4'],
                $satisfaction['s5'], $satisfaction['s6'], $satisfaction['s7'],
                $text('fav_activity'),
                $text('will_do_differently'),
                $text('improvements'),
                kkp_enum($body['can_apply'] ?? null, array_keys(kkp_choices())),
                kkp_enum($body['would_recommend'] ?? null, array_keys(kkp_choices())),
                $text('testimonial'),
                $consent,
                $testimonialName,
                $ipHash,
            ]
        );

        Response::success(['id' => $db->lastInsertId()], 'Reponse enregistree', 201);

    } catch (Exception $e) {
        // Code deja utilise pour cette phase : collision de code ou double envoi
        if (strpos($e->getMessage(), 'uq_kkp_phase_code') !== false
            || strpos($e->getMessage(), 'Duplicate entry') !== false) {
            Response::error(
                "Ce code personnel a deja ete utilise pour ce questionnaire. "
                . "Si ce n'est pas toi, ajoute la premiere lettre de ton nom de famille a la fin "
                . "(exemple : MA14B) et reessaie.",
                409
            );
        }
        Response::error('Enregistrement impossible : ' . $e->getMessage(), 500);
    }
});

/**
 * Statistiques : agregats par phase et comparaison appariee.
 */
$router->get('\/kkp/stats', function($params) use ($db) {
    try {
        $before = kkp_fetch_rows($db, 'avant');
        $after  = kkp_fetch_rows($db, 'apres');

        Response::success([
            'labels' => kkp_labels(),
            'phases' => [
                'avant' => kkp_aggregate($before),
                'apres' => kkp_aggregate($after),
            ],
            'comparison' => kkp_compare($before, $after),
            'generated_at' => date('c'),
        ]);
    } catch (Exception $e) {
        Response::error('Statistiques indisponibles : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

/**
 * Reponses individuelles.
 */
$router->get('\/kkp/responses', function($params) use ($db) {
    try {
        $rows = kkp_fetch_rows($db, kkp_phase_filter());

        // L'empreinte d'IP ne sort jamais de l'API
        foreach ($rows as &$row) { unset($row['ip_hash']); }
        unset($row);

        Response::success([
            'responses' => $rows,
            'labels' => kkp_labels(),
        ]);
    } catch (Exception $e) {
        Response::error('Lecture impossible : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

/**
 * Suppression d'une reponse (saisie de test, doublon).
 */
$router->delete('\/kkp/responses/:id', function($params) use ($db) {
    try {
        $row = $db->fetchOne("SELECT id FROM kkp_responses WHERE id = ? LIMIT 1", [$params['id']]);
        if (!$row) {
            Response::notFound('Reponse introuvable');
        }
        $db->query("DELETE FROM kkp_responses WHERE id = ?", [$params['id']]);
        Response::success(null, 'Reponse supprimee');
    } catch (Exception $e) {
        Response::error('Suppression impossible : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

/**
 * Export : csv (donnees brutes), excel (donnees + synthese), pdf (rapport).
 */
$router->get('\/kkp/export', function($params) use ($db) {
    $format = strtolower((string) Router::getQueryParam('format', 'csv'));
    if (!in_array($format, ['csv', 'excel', 'pdf'], true)) {
        Response::error('Format invalide (csv, excel ou pdf)', 400);
    }

    try {
        $phase = kkp_phase_filter();
        $rows = kkp_fetch_rows($db, $phase);
        $stamp = date('Y-m-d');
        $suffix = $phase ? '-' . $phase : '';

        if ($format === 'csv') {
            kkp_export_csv($rows, "koule-ki-pale{$suffix}-{$stamp}.csv");
        } elseif ($format === 'excel') {
            $before = $phase === 'apres' ? [] : kkp_fetch_rows($db, 'avant');
            $after  = $phase === 'avant' ? [] : kkp_fetch_rows($db, 'apres');
            kkp_export_excel($rows, $before, $after, "koule-ki-pale{$suffix}-{$stamp}.xls");
        } else {
            $before = kkp_fetch_rows($db, 'avant');
            $after  = kkp_fetch_rows($db, 'apres');
            kkp_export_pdf($before, $after, $phase, "koule-ki-pale{$suffix}-{$stamp}.pdf");
        }
    } catch (Exception $e) {
        Response::error('Export impossible : ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// ---------- Exports ----------

/**
 * Colonnes communes au CSV et a l'Excel : [en-tete, extracteur].
 */
function kkp_export_columns() {
    $phases = kkp_phases();
    $genders = kkp_genders();
    $situations = kkp_situations();
    $reactions = kkp_reactions();
    $arts = kkp_arts();

    $columns = [
        ['Phase', function($r) use ($phases) { return $phases[$r['phase']] ?? $r['phase']; }],
        ['Code personnel', function($r) { return $r['personal_code']; }],
        ['Age', function($r) { return $r['age']; }],
        ['Sexe', function($r) use ($genders) { return $r['gender'] ? ($genders[$r['gender']] ?? $r['gender']) : ''; }],
        ['Situation', function($r) use ($situations) { return $r['situation'] ? ($situations[$r['situation']] ?? $r['situation']) : ''; }],
        ['Formation anterieure', function($r) {
            if ($r['prior_training'] === null || $r['prior_training'] === '') return '';
            return ((int) $r['prior_training'] === 1) ? 'Oui' : 'Non';
        }],
    ];

    foreach ($arts as $key => $label) {
        $columns[] = [$label, function($r) use ($key) { return !empty($r[$key]) ? 'Oui' : 'Non'; }];
    }

    $i = 0;
    foreach (kkp_questions() as $key => $label) {
        $i++;
        $columns[] = ["Q{$i}. {$label}", function($r) use ($key) { return $r[$key]; }];
    }

    $columns[] = ['Reaction au conflit', function($r) use ($reactions) {
        return $r['reaction'] ? ($reactions[$r['reaction']] ?? $r['reaction']) : '';
    }];
    $columns[] = ['Sens de « gerer un conflit a travers l\'art »', function($r) {
        return $r['art_conflict_meaning'] ?? '';
    }];
    $columns[] = ['Attentes', function($r) { return $r['expectations']; }];
    $columns[] = ['Besoins particuliers', function($r) { return $r['special_needs']; }];

    // Colonnes propres au questionnaire de fin : vides pour les lignes 'avant'
    $i = 0;
    foreach (kkp_satisfaction() as $key => $label) {
        $i++;
        $columns[] = ["S{$i}. {$label}", function($r) use ($key) { return $r[$key] ?? ''; }];
    }

    $columns[] = ['Activite preferee', function($r) { return $r['fav_activity'] ?? ''; }];
    $columns[] = ['Fera differemment', function($r) { return $r['will_do_differently'] ?? ''; }];
    $columns[] = ['A ameliorer', function($r) { return $r['improvements'] ?? ''; }];

    $choices = kkp_choices();
    $columns[] = ['Utilisable au quotidien', function($r) use ($choices) {
        return !empty($r['can_apply']) ? ($choices[$r['can_apply']] ?? $r['can_apply']) : '';
    }];
    $columns[] = ['Recommanderait la formation', function($r) use ($choices) {
        return !empty($r['would_recommend']) ? ($choices[$r['would_recommend']] ?? $r['would_recommend']) : '';
    }];

    $consents = kkp_consents();
    $columns[] = ['Temoignage', function($r) { return $r['testimonial'] ?? ''; }];
    $columns[] = ['Autorisation de diffusion', function($r) use ($consents) {
        return !empty($r['testimonial_consent']) ? ($consents[$r['testimonial_consent']] ?? '') : '';
    }];
    $columns[] = ['Nom (si cite)', function($r) {
        // Le nom n'est deja stocke que sous consentement ; cette garde evite
        // qu'un import ancien ou une correction manuelle le fasse ressortir.
        return ($r['testimonial_consent'] ?? null) === 'oui_nom' ? ($r['testimonial_name'] ?? '') : '';
    }];

    $columns[] = ['Date de soumission', function($r) { return $r['created_at']; }];

    return $columns;
}

function kkp_export_csv(array $rows, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $columns = kkp_export_columns();
    $out = fopen('php://output', 'w');

    // BOM UTF-8 : sans lui Excel casse les accents
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_column($columns, 0));

    foreach ($rows as $row) {
        $line = [];
        foreach ($columns as [$header, $extract]) {
            $line[] = $extract($row);
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

function kkp_xml_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Une cellule SpreadsheetML. $type vaut 'String' ou 'Number'.
 */
function kkp_xls_cell($value, $type = 'String', $style = null) {
    if ($value === null || $value === '') {
        return $style ? "<Cell ss:StyleID=\"{$style}\"/>" : '<Cell/>';
    }
    $attr = $style ? " ss:StyleID=\"{$style}\"" : '';
    if ($type === 'Number') {
        return "<Cell{$attr}><Data ss:Type=\"Number\">" . (0 + $value) . "</Data></Cell>";
    }
    return "<Cell{$attr}><Data ss:Type=\"String\">" . kkp_xml_escape($value) . "</Data></Cell>";
}

function kkp_xls_row(array $cells) {
    return '<Row>' . implode('', $cells) . "</Row>\n";
}

/**
 * Classeur Excel au format SpreadsheetML 2003 : pas de dependance,
 * pas besoin de l'extension ZipArchive, et les accents passent en UTF-8.
 * Feuille 1 = reponses brutes, feuille 2 = synthese.
 */
function kkp_export_excel(array $rows, array $before, array $after, $filename) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $columns = kkp_export_columns();

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
       . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";

    echo '<Styles>'
       . '<Style ss:ID="sHeader"><Font ss:Bold="1" ss:Color="#FFFFFF"/>'
       . '<Interior ss:Color="#008080" ss:Pattern="Solid"/>'
       . '<Alignment ss:Vertical="Center" ss:WrapText="1"/></Style>'
       . '<Style ss:ID="sTitle"><Font ss:Bold="1" ss:Size="14"/></Style>'
       . '<Style ss:ID="sBold"><Font ss:Bold="1"/></Style>'
       . '</Styles>' . "\n";

    // --- Feuille 1 : reponses ---
    echo '<Worksheet ss:Name="Reponses"><Table>' . "\n";
    $header = [];
    foreach ($columns as [$label, $extract]) {
        $header[] = kkp_xls_cell($label, 'String', 'sHeader');
    }
    echo kkp_xls_row($header);

    $numeric = ['Age'];
    foreach ($rows as $row) {
        $cells = [];
        foreach ($columns as [$label, $extract]) {
            $value = $extract($row);
            $isNumber = in_array($label, $numeric, true) || preg_match('/^[QS]\d\./', $label);
            $cells[] = kkp_xls_cell($value, ($isNumber && $value !== null && $value !== '') ? 'Number' : 'String');
        }
        echo kkp_xls_row($cells);
    }
    echo '</Table></Worksheet>' . "\n";

    // --- Feuille 2 : synthese ---
    echo '<Worksheet ss:Name="Synthese"><Table>' . "\n";
    echo kkp_xls_row([kkp_xls_cell('Koule Ki Pale - synthese', 'String', 'sTitle')]);
    echo kkp_xls_row([kkp_xls_cell('Genere le ' . date('d/m/Y H:i'))]);
    echo kkp_xls_row([]);

    $aggBefore = kkp_aggregate($before);
    $aggAfter  = kkp_aggregate($after);
    $comparison = kkp_compare($before, $after);

    echo kkp_xls_row([
        kkp_xls_cell('Participation', 'String', 'sBold'),
        kkp_xls_cell('Avant', 'String', 'sBold'),
        kkp_xls_cell('Apres', 'String', 'sBold'),
    ]);
    echo kkp_xls_row([
        kkp_xls_cell('Questionnaires recus'),
        kkp_xls_cell($aggBefore['total'], 'Number'),
        kkp_xls_cell($aggAfter['total'], 'Number'),
    ]);
    echo kkp_xls_row([
        kkp_xls_cell('Participants apparies (les deux questionnaires)'),
        kkp_xls_cell($comparison['paired'], 'Number'),
    ]);
    echo kkp_xls_row([]);

    echo kkp_xls_row([
        kkp_xls_cell('Affirmation', 'String', 'sHeader'),
        kkp_xls_cell('Moyenne avant', 'String', 'sHeader'),
        kkp_xls_cell('Moyenne apres', 'String', 'sHeader'),
        kkp_xls_cell('Ecart', 'String', 'sHeader'),
        kkp_xls_cell('Apparies', 'String', 'sHeader'),
    ]);
    foreach ($comparison['likert'] as $item) {
        echo kkp_xls_row([
            kkp_xls_cell($item['label']),
            kkp_xls_cell($item['avg_before'], 'Number'),
            kkp_xls_cell($item['avg_after'], 'Number'),
            kkp_xls_cell($item['delta'], 'Number'),
            kkp_xls_cell($item['n'], 'Number'),
        ]);
    }
    echo kkp_xls_row([]);

    echo kkp_xls_row([
        kkp_xls_cell('Reaction au conflit', 'String', 'sHeader'),
        kkp_xls_cell('Avant', 'String', 'sHeader'),
        kkp_xls_cell('Apres', 'String', 'sHeader'),
    ]);
    foreach (kkp_reactions() as $key => $label) {
        echo kkp_xls_row([
            kkp_xls_cell($label),
            kkp_xls_cell($aggBefore['reaction'][$key] ?? 0, 'Number'),
            kkp_xls_cell($aggAfter['reaction'][$key] ?? 0, 'Number'),
        ]);
    }

    // Avis sur la formation : recueilli uniquement a la fin
    if ($aggAfter['total'] > 0) {
        echo kkp_xls_row([]);
        echo kkp_xls_row([
            kkp_xls_cell('Avis sur la formation', 'String', 'sHeader'),
            kkp_xls_cell('Moyenne sur 5', 'String', 'sHeader'),
            kkp_xls_cell('Reponses', 'String', 'sHeader'),
        ]);
        foreach ($aggAfter['satisfaction'] as $item) {
            echo kkp_xls_row([
                kkp_xls_cell($item['label']),
                kkp_xls_cell($item['avg'], 'Number'),
                kkp_xls_cell($item['n'], 'Number'),
            ]);
        }

        echo kkp_xls_row([]);
        echo kkp_xls_row([
            kkp_xls_cell('', 'String', 'sHeader'),
            kkp_xls_cell('Oui', 'String', 'sHeader'),
            kkp_xls_cell('Non', 'String', 'sHeader'),
            kkp_xls_cell('Pas certain(e)', 'String', 'sHeader'),
        ]);
        foreach ([
            'Pense pouvoir utiliser au quotidien' => $aggAfter['can_apply'],
            'Recommanderait la formation'         => $aggAfter['would_recommend'],
        ] as $label => $counts) {
            echo kkp_xls_row([
                kkp_xls_cell($label),
                kkp_xls_cell($counts['oui'] ?? 0, 'Number'),
                kkp_xls_cell($counts['non'] ?? 0, 'Number'),
                kkp_xls_cell($counts['pas_certain'] ?? 0, 'Number'),
            ]);
        }
    }

    echo '</Table></Worksheet>' . "\n";
    echo '</Workbook>';
    exit;
}

/**
 * Petite barre horizontale en caracteres, pour lire une distribution d'un coup d'oeil.
 */
/**
 * Reserve de la place avant un bloc indivisible : sans ce garde-fou,
 * un libelle peut rester en bas d'une page et son chiffre passer a la suivante.
 */
function kkp_pdf_keep($pdf, $points = 48) {
    if ($pdf->cursorY - $points < $pdf->marginBottom) {
        $pdf->addPage();
    }
}

function kkp_pdf_bar($value, $max, $width = 24) {
    if (!$max || $value === null) return '';
    $filled = (int) round(($value / $max) * $width);
    return str_repeat('|', max(0, $filled));
}

function kkp_export_pdf(array $before, array $after, $phaseFilter, $filename) {
    // Chargé ici seulement : l'écrivain PDF ne sert qu'à cet export
    require_once __DIR__ . '/../utils/SimplePDF.php';

    $pdf = new SimplePDF();
    $pdf->setFont(10);

    $pdf->setFont(16, true);
    $pdf->text('Koulè Ki Pale : Jèn yo pran pawòl');
    $pdf->setFont(11);
    $pdf->text('REVIV, Appel 5 — synthèse des questionnaires');
    $pdf->setFont(9);
    $pdf->text('Généré le ' . date('d/m/Y à H:i') . ' — DevDynamics');
    $pdf->moveDown(6);
    $pdf->hr();
    $pdf->moveDown(10);

    $aggBefore = kkp_aggregate($before);
    $aggAfter  = kkp_aggregate($after);
    $comparison = kkp_compare($before, $after);

    $pdf->setFont(10);
    $pdf->heading('Participation', 13);
    $pdf->moveDown(4);
    $pdf->labelValue('Questionnaires avant la formation', $aggBefore['total'], 250);
    $pdf->labelValue('Questionnaires après la formation', $aggAfter['total'], 250);
    $pdf->labelValue('Participants appariés', $comparison['paired'], 250);
    $pdf->moveDown(10);

    // Profil du groupe, sur la phase demandée (avant par défaut)
    $profile = ($phaseFilter === 'apres') ? $aggAfter : $aggBefore;
    $profileLabel = ($phaseFilter === 'apres') ? 'après la formation' : 'avant la formation';

    $pdf->heading("Profil du groupe ({$profileLabel})", 13);
    $pdf->moveDown(4);
    if ($profile['age']['avg'] !== null) {
        $pdf->labelValue(
            'Âge',
            "moyenne {$profile['age']['avg']} ans (de {$profile['age']['min']} à {$profile['age']['max']} ans)",
            250
        );
    }
    foreach (kkp_genders() as $key => $label) {
        $pdf->labelValue("Sexe — {$label}", $profile['gender'][$key] ?? 0, 250);
    }
    foreach (kkp_situations() as $key => $label) {
        $pdf->labelValue("Situation — {$label}", $profile['situation'][$key] ?? 0, 250);
    }
    $pdf->labelValue(
        'Déjà formé à la gestion des conflits',
        "oui : {$profile['prior_training']['oui']} — non : {$profile['prior_training']['non']}",
        250
    );
    foreach (kkp_arts() as $key => $label) {
        $pdf->labelValue("Pratique artistique — {$label}", $profile['arts'][$key] ?? 0, 250);
    }
    $pdf->moveDown(10);

    $pdf->heading('Rapport au conflit — moyennes sur 5', 13);
    $pdf->moveDown(4);
    $pdf->setFont(9);
    foreach ($profile['likert'] as $item) {
        kkp_pdf_keep($pdf);
        $pdf->paragraph($item['label']);
        $avg = $item['avg'] === null ? '—' : number_format($item['avg'], 2, ',', ' ');
        $pdf->text('    ' . kkp_pdf_bar($item['avg'], 5) . "   {$avg}  (n = {$item['n']})");
        $pdf->moveDown(2);
    }
    $pdf->moveDown(8);

    $pdf->setFont(10);
    $pdf->heading('Façon de réagir au conflit', 13);
    $pdf->moveDown(4);
    $pdf->setFont(9);
    $maxReaction = max(1, max(array_values($profile['reaction'])));
    foreach (kkp_reactions() as $key => $label) {
        $count = $profile['reaction'][$key] ?? 0;
        kkp_pdf_keep($pdf);
        $pdf->paragraph($label);
        $pdf->text('    ' . kkp_pdf_bar($count, $maxReaction) . "   {$count}");
        $pdf->moveDown(2);
    }

    // La comparaison n'a de sens qu'avec des participants appariés
    if ($comparison['paired'] > 0) {
        $pdf->addPage();
        $pdf->setFont(10);
        $pdf->heading('Évolution avant / après', 13);
        $pdf->moveDown(4);
        $pdf->setFont(9);
        $pdf->paragraph(
            "Comparaison restreinte aux {$comparison['paired']} participants ayant rempli "
            . "les deux questionnaires, rapprochés par leur code personnel."
        );
        $pdf->moveDown(8);

        foreach ($comparison['likert'] as $item) {
            kkp_pdf_keep($pdf, 70);
            $pdf->paragraph($item['label']);
            $b = $item['avg_before'] === null ? '—' : number_format($item['avg_before'], 2, ',', ' ');
            $a = $item['avg_after'] === null ? '—' : number_format($item['avg_after'], 2, ',', ' ');
            $d = $item['delta'] === null
                ? '—'
                : (($item['delta'] > 0 ? '+' : '') . number_format($item['delta'], 2, ',', ' '));
            $pdf->text("    avant {$b}   après {$a}   écart {$d}   (n = {$item['n']})");
            $pdf->text(
                "    en progrès : {$item['progress']['up']}   stable : {$item['progress']['flat']}"
                . "   en recul : {$item['progress']['down']}"
            );
            $pdf->moveDown(4);
        }
    }

    // Le sens donne a la demarche, avant puis apres : les chiffres disent si
    // le groupe progresse, ces textes disent en quoi.
    if (!empty($comparison['meanings'])) {
        $pdf->addPage();
        $pdf->setFont(10);
        $pdf->heading('« Gérer un conflit à travers l\'art » — avant et après', 13);
        $pdf->moveDown(4);
        $pdf->setFont(9);
        $pdf->paragraph(
            'Ce que ' . count($comparison['meanings']) . ' participant(s) apparié(s) mettent '
            . 'derrière la démarche, au premier jour puis au dernier.'
        );
        $pdf->moveDown(8);

        foreach ($comparison['meanings'] as $m) {
            kkp_pdf_keep($pdf, 90);
            $pdf->setFont(9, true);
            $pdf->text('Code ' . $m['code']);
            $pdf->setFont(9);
            $pdf->text('    Avant :');
            $pdf->paragraph('      ' . ($m['before'] ?: '—'));
            $pdf->text('    Après :');
            $pdf->paragraph('      ' . ($m['after'] ?: '—'));
            $pdf->moveDown(8);
        }
    }

    // Avis sur la formation et temoignages : questionnaire de fin uniquement
    if ($aggAfter['total'] > 0) {
        $pdf->addPage();
        $pdf->setFont(10);
        $pdf->heading('Avis sur la formation', 13);
        $pdf->moveDown(4);
        $pdf->setFont(9);
        foreach ($aggAfter['satisfaction'] as $item) {
            kkp_pdf_keep($pdf);
            $pdf->paragraph($item['label']);
            $avg = $item['avg'] === null ? '—' : number_format($item['avg'], 2, ',', ' ');
            $pdf->text('    ' . kkp_pdf_bar($item['avg'], 5) . "   {$avg}  (n = {$item['n']})");
            $pdf->moveDown(2);
        }
        $pdf->moveDown(8);

        $pdf->setFont(10);
        $pdf->heading('Après la formation', 13);
        $pdf->moveDown(4);
        $pdf->setFont(9);
        foreach ([
            'Pense pouvoir utiliser au quotidien' => $aggAfter['can_apply'],
            'Recommanderait la formation'         => $aggAfter['would_recommend'],
        ] as $label => $counts) {
            $pdf->labelValue(
                $label,
                "oui : " . ($counts['oui'] ?? 0)
                . " — non : " . ($counts['non'] ?? 0)
                . " — pas certain(e) : " . ($counts['pas_certain'] ?? 0),
                230
            );
        }

        // Seuls les témoignages dont la diffusion a été autorisée figurent
        // dans ce rapport, qui est destiné à sortir de l'équipe.
        $quotable = array_values(array_filter($aggAfter['testimonials'], function($t) {
            return in_array($t['consent'], ['oui_nom', 'oui_anonyme'], true);
        }));
        if ($quotable) {
            $pdf->moveDown(10);
            $pdf->setFont(10);
            $pdf->heading('Témoignages (diffusion autorisée)', 13);
            $pdf->moveDown(4);
            $pdf->setFont(9);
            foreach ($quotable as $t) {
                kkp_pdf_keep($pdf, 70);
                $pdf->paragraph('« ' . $t['text'] . ' »');
                $pdf->text('    — ' . ($t['name'] ?: 'participant anonyme'));
                $pdf->moveDown(6);
            }
        }
    }

    $pdf->moveDown(10);
    $pdf->setFont(8);
    $pdf->hr();
    $pdf->moveDown(6);
    $pdf->paragraph('REVIV est un projet mis en œuvre par la FOKAL, avec le financement de l\'Union européenne.');
    $pdf->paragraph('DevDynamics | contact@dev-dynamics.org | +509 47 41 8737');

    $content = $pdf->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}
