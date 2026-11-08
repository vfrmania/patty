<?php
// Mise a jour des dates officielles du concours ATSEM - version PHP.
// Cascade de sources (perennite) :
//   1. FNCDG (officiel) : https://www.concours-territorial.fr/calendrier.aspx
//      -> inscriptions + premieres epreuves. Ne fournit PAS la cloture du dossier.
//   2. Objectif Petite Enfance (secondaire) : fournit en plus la cloture du dossier.
// La source effectivement utilisee est enregistree dans dates.json (source_utilisee).
// Necessite un hebergement PHP (OVH, Infomaniak...) et dates.json inscriptible
// (chmod 664 ou 666). Sur un hebergement 100% statique, utiliser update-dates.mjs.

header('Content-Type: text/html; charset=utf-8');

$FILE = __DIR__ . '/atsem/dates.json';

function page($titre, $corps, $ok) {
  $couleur = $ok ? '#059669' : '#dc2626';
  echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
  echo '<title>' . $titre . '</title><style>';
  echo "body{font-family:'Segoe UI',system-ui,sans-serif;background:#f1f5f9;color:#1e293b;display:flex;justify-content:center;padding:40px 16px;line-height:1.6}";
  echo '.card{background:#fff;border-radius:18px;box-shadow:0 1px 4px rgba(0,0,0,.07);padding:28px;max-width:560px;width:100%}';
  echo 'h1{font-size:1.2rem;color:#1e3a8a;margin-bottom:12px}';
  echo '.msg{border-left:4px solid ' . $couleur . ';background:#f8fafc;border-radius:8px;padding:12px 16px;margin:10px 0;font-size:.92rem}';
  echo 'a{color:#2563eb}table{width:100%;border-collapse:collapse;font-size:.9rem;margin:12px 0}';
  echo 'td,th{padding:8px 10px;border-bottom:1px solid #e2e8f0;text-align:left}';
  echo '.retour{display:inline-block;margin-top:16px;padding:10px 18px;background:#2563eb;color:#fff;border-radius:12px;text-decoration:none;font-weight:600}';
  echo '</style></head><body><div class="card"><h1>&#128197; ' . $titre . '</h1>' . $corps;
  echo '<a class="retour" href="../atsem/index.html">&larr; Retour au site</a></div></body></html>';
}

function erreur($msg) { page('Erreur de mise à jour', '<div class="msg">' . $msg . '</div>', false); exit; }

function telecharger($url) {
  $brut = @file_get_contents($url);
  if ($brut === false || $brut === '') throw new Exception('source inaccessible');
  return trim(preg_replace('/\s+/', ' ', strip_tags($brut)));
}

if (!file_exists($FILE)) erreur('dates.json introuvable sur le serveur.');
$d = json_decode(file_get_contents($FILE), true);
if (!is_array($d) || empty($d['session'])) erreur('dates.json illisible.');

function iso($j, $m, $a) { return sprintf('%04d-%02d-%02d', $a, $m, $j); }

// ---- Source 1 : FNCDG (officiel) ----
$principal = null; $notes = array();
try {
  $txt = telecharger('https://www.concours-territorial.fr/calendrier.aspx?type=concours&annee=' . rawurlencode($d['session']));
  if (!preg_match('/ATSEM principal de 2[^\n]{0,40}classe[\s\S]{0,600}?Inscriptions du (\d{2})\/(\d{2})\/(\d{4}) au (\d{2})\/(\d{2})\/(\d{4})/', $txt, $m, PREG_OFFSET_CAPTURE))
    throw new Exception('bloc ATSEM introuvable');
  $principal = array(
    'inscriptions_debut' => iso($m[1][0], $m[2][0], $m[3][0]),
    'inscriptions_fin' => iso($m[4][0], $m[5][0], $m[6][0])
  );
  if (!preg_match('/premi\S*res \S*preuves[^0-9]{0,40}(\d{2})\/(\d{2})\/(\d{4})/i', substr($txt, $m[0][1]), $e))
    throw new Exception('premieres epreuves introuvables');
  $principal['ecrit'] = iso($e[1], $e[2], $e[3]);
} catch (Exception $ex) {
  $notes[] = 'FNCDG indisponible (' . $ex->getMessage() . ')';
}

// ---- Source 2 : Objectif Petite Enfance (secondaire) ----
$secondaire = null;
try {
  $txt = telecharger('https://objectif-petite-enfance.fr/articles/calendrier-concours-atsem-' . rawurlencode($d['session']) . '.html');
  $MOIS = array('janvier'=>1,'février'=>2,'mars'=>3,'avril'=>4,'mai'=>5,'juin'=>6,'juillet'=>7,'août'=>8,'aout'=>8,'septembre'=>9,'octobre'=>10,'novembre'=>11,'décembre'=>12,'decembre'=>12);
  if (!preg_match('/court du \S+ (\d{1,2}) ([a-zA-Zéû]+) au \S+ (\d{1,2}) ([a-zA-Zéû]+) (\d{4})/ui', $txt, $m)
      || !isset($MOIS[mb_strtolower($m[2])]) || !isset($MOIS[mb_strtolower($m[4])]))
    throw new Exception('format non reconnu (inscriptions)');
  $secondaire = array(
    'inscriptions_debut' => iso($m[1], $MOIS[mb_strtolower($m[2])], $m[5]),
    'inscriptions_fin' => iso($m[3], $MOIS[mb_strtolower($m[4])], $m[5])
  );
  if (preg_match('/clôture des inscriptions[^\n]{0,120}?\S+ (\d{1,2}) ([a-zA-Zéû]+) (\d{4})/ui', $txt, $m)
      && isset($MOIS[mb_strtolower($m[2])]))
    $secondaire['dossier_fin'] = iso($m[1], $MOIS[mb_strtolower($m[2])], $m[3]);
  if (preg_match('/premières épreuves[^\n]{0,120}?\S+ (\d{1,2}) ([a-zA-Zéû]+) (\d{4})/ui', $txt, $m)
      && isset($MOIS[mb_strtolower($m[2])]))
    $secondaire['ecrit'] = iso($m[1], $MOIS[mb_strtolower($m[2])], $m[3]);
} catch (Exception $ex) {
  $notes[] = 'Source secondaire indisponible (' . $ex->getMessage() . ')';
}

if (!$principal && !$secondaire) erreur('Aucune source accessible : ' . htmlspecialchars(implode(' | ', $notes)) . '. Réessayez plus tard.');

// ---- Assemblage ----
$trouve = $principal ? $principal : $secondaire;
$source_nom = $principal ? 'FNCDG (concours-territorial.fr)' : 'Objectif Petite Enfance (seule source disponible)';
if ($principal) {
  if ($secondaire && isset($secondaire['dossier_fin'])) {
    $trouve['dossier_fin'] = $secondaire['dossier_fin'];
  } else {
    $notes[] = 'Clôture du dossier non revérifiée : valeur précédente conservée.';
  }
} else {
  $notes[] = 'ATTENTION : données issues de la source SECONDAIRE uniquement.';
}

$nouvelles = array();
foreach (array('inscriptions_debut', 'inscriptions_fin', 'ecrit') as $cle) if (isset($trouve[$cle])) $nouvelles[$cle] = $trouve[$cle];
if (isset($trouve['dossier_fin'])) $nouvelles['dossier_fin'] = $trouve['dossier_fin'];

$changements = array();
foreach ($nouvelles as $cle => $val) {
  if (!isset($d[$cle]) || $d[$cle] !== $val) { $d[$cle] = $val; $changements[] = $cle . ' : ' . $val; }
}
$d['source_utilisee'] = $source_nom;
$d['verifie_le'] = date('Y-m-d');

$json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
$ecrit_ok = file_put_contents($FILE, $json);

$corps = '<div class="msg">Source lue pour la session ' . htmlspecialchars($d['session']) . ' : ' . htmlspecialchars($source_nom) . '.</div>';
foreach ($notes as $n) $corps .= '<div class="msg" style="font-size:.85rem">' . htmlspecialchars($n) . '</div>';
$corps .= '<table><tr><th>Étape</th><th>Date lue</th></tr>';
$corps .= '<tr><td>Ouverture des inscriptions</td><td>' . $trouve['inscriptions_debut'] . '</td></tr>';
$corps .= '<tr><td>Fin des inscriptions</td><td>' . $trouve['inscriptions_fin'] . '</td></tr>';
$corps .= '<tr><td>Clôture / dépôt du dossier</td><td>' . (isset($trouve['dossier_fin']) ? $trouve['dossier_fin'] : 'non revérifiée') . '</td></tr>';
$corps .= '<tr><td>Premières épreuves (écrit)</td><td>' . (isset($trouve['ecrit']) ? $trouve['ecrit'] : 'non trouvée') . '</td></tr></table>';

if ($ecrit_ok === false) {
  $corps .= '<div class="msg">⚠️ Lecture réussie mais <b>écriture de dates.json impossible</b> : vérifiez les permissions du fichier (chmod 664 ou 666) sur votre hébergement.</div>';
  page('Vérification des dates', $corps, false); exit;
}

if (empty($changements)) {
  $corps .= '<div class="msg">✅ Aucun changement : les dates affichées par le site sont à jour (dernière vérification actualisée à aujourd\'hui).</div>';
} else {
  $corps .= '<div class="msg">🔄 Dates mises à jour : ' . htmlspecialchars(implode(' ; ', $changements)) . '. Le site affiche désormais ces dates.</div>';
}
page('Vérification des dates', $corps, true);
