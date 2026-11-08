#!/usr/bin/env node
// Mise a jour des dates officielles du concours ATSEM (depot vfrmania/patty).
// Les dates servies par le site proviennent de dates.json : ce script est le
// seul endroit autorise a le modifier (a la main ou via GitHub Actions).
//
// Usage :
//   node update-dates.mjs --check     lit la source et affiche les dates trouvees (aucune ecriture)
//   node update-dates.mjs --auto      lit la source et met a jour dates.json si changement
//   node update-dates.mjs --set ecrit=2027-09-29 [--set oral=2028-01-15 ...]
//                                     mise a jour manuelle de cles precises
import { readFileSync, writeFileSync } from 'node:fs';

const FILE = new URL('./dates.json', import.meta.url);
const MOIS = { 'janvier': 1, 'février': 2, 'mars': 3, 'avril': 4, 'mai': 5, 'juin': 6, 'juillet': 7, 'août': 8, 'septembre': 9, 'octobre': 10, 'novembre': 11, 'décembre': 12 };
const CLES_AUTO = ['inscriptions_debut', 'inscriptions_fin', 'dossier_fin', 'ecrit'];

function load() { return JSON.parse(readFileSync(FILE, 'utf8')); }
function save(d) { writeFileSync(FILE, JSON.stringify(d, null, 2) + '\n'); }
function iso(j, m, a) { return a + '-' + String(m).padStart(2, '0') + '-' + String(j).padStart(2, '0'); }
function aujourdhui() { const t = new Date(); return iso(t.getDate(), t.getMonth() + 1, t.getFullYear()); }

async function lireSource(session) {
  const url = 'https://objectif-petite-enfance.fr/articles/calendrier-concours-atsem-' + session + '.html';
  const res = await fetch(url);
  if (!res.ok) throw new Error('Source inaccessible (HTTP ' + res.status + ') : ' + url);
  const txt = (await res.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
  const d = {};
  let m = txt.match(/court du \S+ (\d{1,2}) (\S+) au \S+ (\d{1,2}) (\S+) (\d{4})/);
  if (!m || !MOIS[m[2]] || !MOIS[m[4]]) throw new Error('Introuvable : ouverture / fin des inscriptions');
  d.inscriptions_debut = iso(+m[1], MOIS[m[2]], +m[5]);
  d.inscriptions_fin = iso(+m[3], MOIS[m[4]], +m[5]);
  m = txt.match(/cl\u00f4ture des inscriptions[^\n]{0,120}?\S+ (\d{1,2}) (\S+) (\d{4})/i);
  if (!m || !MOIS[m[2]]) throw new Error('Introuvable : cloture des inscriptions (depot du dossier)');
  d.dossier_fin = iso(+m[1], MOIS[m[2]], +m[3]);
  m = txt.match(/premi\u00e8res \u00e9preuves[^\n]{0,120}?\S+ (\d{1,2}) (\S+) (\d{4})/i);
  if (!m || !MOIS[m[2]]) throw new Error('Introuvable : premieres epreuves (ecrit)');
  d.ecrit = iso(+m[1], MOIS[m[2]], +m[3]);
  return d;
}

const args = process.argv.slice(2);
const d = load();

if (args.includes('--check') || args.includes('--auto')) {
  const trouvees = await lireSource(d.session);
  console.log('Source lue pour la session ' + d.session + ' => ' + JSON.stringify(trouvees));
  const changes = CLES_AUTO.filter(k => trouvees[k] !== d[k]);
  if (args.includes('--check')) {
    console.log(changes.length ? 'CHANGEMENTS DETECTES : ' + changes.map(k => k + ' : ' + d[k] + ' -> ' + trouvees[k]).join(' ; ') : 'dates.json est a jour avec la source.');
    process.exit(0);
  }
  if (changes.length) CLES_AUTO.forEach(k => { d[k] = trouvees[k]; });
  d.verifie_le = aujourdhui();
  save(d);
  console.log(changes.length ? 'dates.json mis a jour : ' + changes.join(', ') : 'Aucun changement de dates ; verifie_le actualise.');
} else {
  const sets = [];
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--set' && args[i + 1]) sets.push(args[i + 1]);
    else if (args[i].startsWith('--set=')) sets.push(args[i].slice(6));
  }
  if (!sets.length) {
    console.error('Usage : node update-dates.mjs --check | --auto | --set cle=AAAA-MM-JJ');
    process.exit(1);
  }
  for (const s of sets) {
    const eq = s.indexOf('=');
    const k = s.slice(0, eq), v = s.slice(eq + 1);
    if (eq < 1 || !(k in d)) { console.error('Cle inconnue : ' + k + '. Cles valides : ' + Object.keys(d).join(', ')); process.exit(1); }
    d[k] = v;
  }
  d.verifie_le = aujourdhui();
  save(d);
  console.log('dates.json mis a jour manuellement.');
}
