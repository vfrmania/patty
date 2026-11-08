<?php
// Mise a jour des dates officielles du concours ATSEM - version PHP.
// Ce script est independant des pages du site : il lit la source du calendrier
// national FNCDG, met a jour dates.json, puis affiche un rapport.
// Il necessite un hebergement capable d'executer PHP (OVH, Infomaniak, etc.)
// et que dates.json soit inscriptible (chmod 664 ou 666 selon l'hebergeur).
// Sur GitHub Pages (statique), utiliser update-dates.mjs a la place.

header('Content-Type: text/html; charset=utf-8');

$FILE = __DIR__ . '/dates.json';

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

function erreur($msg) { page('Erreur de mise a jour', '<div class="msg">' . $msg . '</div>', false); exit; }

if (!file_exists($FILE)) erreur('dates.json introuvable sur le serveur.');
$d = json_decode(file_get_contents($FILE), true);
if (!is_array($d) || empty($d['session'])) erreur('dates.json illisible.');

$url = 'https://objectif-petite-enfance.fr/articles/calendrier-concours-atsem-' . rawurlencode($d['session']) . '.html';
$brut = @file_get_contents($url);
if ($brut === false) erreur('La source officielle est inaccessible : ' . htmlspecialchars($url));

$txt = preg_replace('/<[^>]+>/u', ' ', $brut);
$txt = preg_replace('/\s+/u', ' ', $txt);

$MOIS = array('janvier'=>1,'février'=>2,'mars'=>3,'avril'=>4,'mai'=>5,'juin'=>6,'juillet'=>7,'août'=>8,'aout'=>8,'septembre'=>9,'octobre'=>10,'novembre'=>11,'décembre'=>12,'decembre'=>12);
function iso($j, $m, $a) { return sprintf('%d-%02d-%02d', $a, $m, $j); }

if (!preg_match('/court du \S+ (\d{1,2}) ([a-zA-Zéû]+) au \S+ (\d{1,2}) ([a-zA-Zéû]+) (\d{4})/u', $txt, $m)
    || !isset($MOIS[mb_strtolower($m[2])]) || !isset($MOIS[mb_strtolower($m[4])]))
  erreur('Format de la source non reconnu (inscriptions).');
$ins_deb = iso($m[1], $MOIS[mb_strtolower($m[2])], $m[5]);
$ins_fin = iso($m[3], $MOIS[mb_strtolower($m[4])], $m[5]);

if (!preg_match('/clôture des inscriptions[^\n]{0,120}?\S+ (\d{1,2}) ([a-zA-Zéû]+) (\d{4})/ui', $txt, $m)
    || !isset($MOIS[mb_strtolower($m[2])]))
  erreur('Format de la source non reconnu (clôture des inscriptions).');
$dossier = iso($m[1], $MOIS[mb_strtolower($m[2])], $m[3]);

if (!preg_match('/premières épreuves[^\n]{0,120}?\S+ (\d{1,2}) ([a-zA-Zéû]+) (\d{4})/ui', $txt, $m)
    || !isset($MOIS[mb_strtolower($m[2])]))
  erreur('Format de la source non reconnu (premières épreuves).');
$ecrit = iso($m[1], $MOIS[mb_strtolower($m[2])], $m[3]);

$nouvelles = array('inscriptions_debut' => $ins_deb, 'inscriptions_fin' => $ins_fin, 'dossier_fin' => $dossier, 'ecrit' => $ecrit);
$changements = array();
foreach ($nouvelles as $cle => $val) {
  if (!isset($d[$cle]) || $d[$cle] !== $val) { $d[$cle] = $val; $changements[] = $cle . ' : ' . $val; }
}
$d['verifie_le'] = date('Y-m-d');

$json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
$ecrit_ok = file_put_contents($FILE, $json);

$corps = '<div class="msg">Source officielle lue pour la session ' . htmlspecialchars($d['session']) . '.</div>';
$corps .= '<table><tr><th>Étape</th><th>Date lue</th></tr>';
$corps .= '<tr><td>Ouverture des inscriptions</td><td>' . $ins_deb . '</td></tr>';
$corps .= '<tr><td>Fin des inscriptions</td><td>' . $ins_fin . '</td></tr>';
$corps .= '<tr><td>Clôture / dépôt du dossier</td><td>' . $dossier . '</td></tr>';
$corps .= '<tr><td>Premières épreuves (écrit)</td><td>' . $ecrit . '</td></tr></table>';

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
