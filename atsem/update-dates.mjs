#!/usr/bin/env node
// Mise a jour des dates officielles du concours ATSEM (depot vfrmania/patty).
// Les dates servies par le site proviennent de dates.json : ce script est le
// seul endroit autorise a le modifier (a la main ou via le PHP).
//
// Sources, en cascade (perennite) :
//   1. FNCDG (officiel) : https://www.concours-territorial.fr/calendrier.aspx
//      -> inscriptions + premieres epreuves. Ne fournit PAS la cloture du dossier.
//   2. Objectif Petite Enfance (secondaire) : reprise du calendrier FNCDG
//      -> fournit en plus la date limite de depot du dossier.
//   La source effectivement utilisee est enregistree dans dates.json
//   (cle "source_utilisee") pour transparence.
//
// Usage :
//   node update-dates.mjs --check     lit les sources et affiche les dates trouvees (aucune ecriture)
//   node update-dates.mjs --auto      lit les sources et met a jour dates.json si changement
//   node update-dates.mjs --set ecrit=2027-09-29 [--set oral=2028-01-15 ...]
//                                     mise a jour manuelle de cles precises
import { readFileSync, writeFileSync } from 'node:fs';

const FILE = new URL('./dates.json', import.meta.url);
const MOIS = { 'janvier': 1, 'février': 2, 'mars': 3, 'avril': 4, 'mai': 5, 'juin': 6, 'juillet': 7, 'août': 8, 'aout': 8, 'septembre': 9, 'octobre': 10, 'novembre': 11, 'décembre': 12, 'decembre': 12 };
const CLES = ['inscriptions_debut', 'inscriptions_fin', 'ecrit', 'dossier_fin'];

function load() { return JSON.parse(readFileSync(FILE, 'utf8')); }
function save(d) { writeFileSync(FILE, JSON.stringify(d, null, 2) + '\n'); }
function iso(j, m, a) { return a + '-' + String(m).padStart(2, '0') + '-' + String(j).padStart(2, '0'); }
function aujourdhui() { const t = new Date(); return iso(t.getDate(), t.getMonth() + 1, t.getFullYear()); }

async function telecharger(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error('HTTP ' + res.status);
  return (await res.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
}

// ---- Source 1 : FNCDG (officiel) ----
async function lireFncdg(session) {
  const txt = await telecharger('https://www.concours-territorial.fr/calendrier.aspx?type=concours&annee=' + session);
  // Premiere entree "ATSEM principal de 2e classe" = metropole (les entrees
  // suivantes, ex. "2027-972" Martinique, ne doivent pas etre prises).
  const m = txt.match(/ATSEM principal de 2[^\n]{0,40}classe[\s\S]{0,600}?Inscriptions du (\d{2})\/(\d{2})\/(\d{4}) au (\d{2})\/(\d{2})\/(\d{4})/);
  if (!m) throw new Error('bloc ATSEM introuvable');
  const d = {
    inscriptions_debut: iso(+m[1], +m[2], +m[3]),
    inscriptions_fin: iso(+m[4], +m[5], +m[6])
  };
  const suite = txt.slice(m.index);
  const e = suite.match(/premi\S*res \S*preuves[:\s]*(\d{2})\/(\d{2})\/(\d{4})/i);
  if (!e) throw new Error('premieres epreuves introuvables');
  d.ecrit = iso(+e[1], +e[2], +e[3]);
  d.source = 'FNCDG (concours-territorial.fr)';
  return d;
}

// ---- Source 2 : Objectif Petite Enfance (secondaire) ----
async function lireOpe(session) {
  const txt = await telecharger('https://objectif-petite-enfance.fr/articles/calendrier-concours-atsem-' + session + '.html');
  const d = {};
  let m = txt.match(/court du \S+ (\d{1,2}) (\S+) au \S+ (\d{1,2}) (\S+) (\d{4})/);
  if (!m || !MOIS[m[2]] || !MOIS[m[4]]) throw new Error('inscriptions introuvables');
  d.inscriptions_debut = iso(+m[1], MOIS[m[2]], +m[5]);
  d.inscriptions_fin = iso(+m[3], MOIS[m[4]], +m[5]);
  m = txt.match(/cl\u00f4ture des inscriptions[^\n]{0,120}?\S+ (\d{1,2}) (\S+) (\d{4})/i);
  if (m && MOIS[m[2]]) d.dossier_fin = iso(+m[1], MOIS[m[2]], +m[3]);
  m = txt.match(/premi\u00e8res \u00e9preuves[^\n]{0,120}?\S+ (\d{1,2}) (\S+) (\d{4})/i);
  if (m && MOIS[m[2]]) d.ecrit = iso(+m[1], MOIS[m[2]], +m[3]);
  d.source = 'Objectif Petite Enfance (secondaire)';
  return d;
}

async function lireSources(session) {
  const notes = [];
  let principal = null, secondaire = null;
  try { principal = await lireFncdg(session); }
  catch (e) { notes.push('FNCDG indisponible (' + (e && e.message ? e.message : e) + ')'); }
  try { secondaire = await lireOpe(session); }
  catch (e) { notes.push('Source secondaire indisponible (' + (e && e.message ? e.message : e) + ')'); }
  if (!principal && !secondaire) throw new Error('Aucune source disponible : ' + notes.join(' | '));
  const trouve = principal ? { ...principal } : { ...secondaire };
  if (principal) {
    if (secondaire && secondaire.dossier_fin) {
      trouve.dossier_fin = secondaire.dossier_fin;
    } else {
      notes.push('Cloture du dossier non rev\u00e9rifi\u00e9e : valeur pr\u00e9c\u00e9dente conserv\u00e9e.');
      if (!trouve.dossier_fin) delete trouve.dossier_fin;
    }
  } else {
    notes.push('ATTENTION : donn\u00e9es issues de la source SECONDAIRE uniquement.');
  }
  return { trouve, notes };
}

const args = process.argv.slice(2);
const d = load();

if (args.includes('--check') || args.includes('--auto')) {
  const { trouve, notes } = await lireSources(d.session);
  console.log('Session ' + d.session + ' | source : ' + trouve.source);
  notes.forEach(n => console.log('NOTE : ' + n));
  console.log('Dates lues : ' + JSON.stringify(CLES.reduce((o, k) => { if (trouve[k]) o[k] = trouve[k]; return o; }, {})));
  const changes = CLES.filter(k => trouve[k] && trouve[k] !== d[k]);
  if (args.includes('--check')) {
    console.log(changes.length ? 'CHANGEMENTS DETECTES : ' + changes.map(k => k + ' : ' + d[k] + ' -> ' + trouve[k]).join(' ; ') : 'dates.json est a jour avec la source.');
    process.exit(0);
  }
  changes.forEach(k => { d[k] = trouve[k]; });
  d.source_utilisee = trouve.source;
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
