// Pavillons du code international des signaux : un par terme de recherche.
// Chaque pavillon a un motif (lisible même en niveaux de gris) et une couleur-clé
// qui souligne les correspondances du terme dans l'aperçu.

const Y = '#f2c230';
const R = '#c8302b';
const B = '#1d5fa8';
const K = '#14202e';
const W = '#ffffff';

const rect = (x, y, w, h, fill) => `<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${fill}"/>`;

const FLAGS = [
  { letter: 'A', key: B, draw: () => rect(0, 0, 15, 20, W) + `<path d="M15 0h15l-6 10 6 10H15z" fill="${B}"/>` },
  { letter: 'B', key: R, draw: () => `<path d="M0 0h30l-6 10 6 10H0z" fill="${R}"/>` },
  { letter: 'I', key: Y, draw: () => rect(0, 0, 30, 20, Y) + `<circle cx="15" cy="10" r="5" fill="${K}"/>` },
  { letter: 'L', key: K, draw: () => rect(0, 0, 15, 10, Y) + rect(15, 0, 15, 10, K) + rect(0, 10, 15, 10, K) + rect(15, 10, 15, 10, Y) },
  { letter: 'C', key: B, draw: () => rect(0, 0, 30, 4, B) + rect(0, 4, 30, 4, W) + rect(0, 8, 30, 4, R) + rect(0, 12, 30, 4, W) + rect(0, 16, 30, 4, B) },
  { letter: 'D', key: Y, draw: () => rect(0, 0, 30, 5, Y) + rect(0, 5, 30, 10, B) + rect(0, 15, 30, 5, Y) },
  { letter: 'E', key: R, draw: () => rect(0, 0, 30, 10, B) + rect(0, 10, 30, 10, R) },
  { letter: 'F', key: R, draw: () => rect(0, 0, 30, 20, W) + `<path d="M15 1 28 10 15 19 2 10z" fill="${R}"/>` },
  { letter: 'G', key: Y, draw: () => [0, 1, 2, 3, 4, 5].map((i) => rect(i * 5, 0, 5, 20, i % 2 ? B : Y)).join('') },
  { letter: 'H', key: R, draw: () => rect(0, 0, 15, 20, W) + rect(15, 0, 15, 20, R) },
  { letter: 'J', key: B, draw: () => rect(0, 0, 30, 6.66, B) + rect(0, 6.66, 30, 6.67, W) + rect(0, 13.33, 30, 6.67, B) },
  { letter: 'K', key: Y, draw: () => rect(0, 0, 15, 20, Y) + rect(15, 0, 15, 20, B) },
];

export function flagFor(index) {
  return FLAGS[index % FLAGS.length];
}

/** Élément SVG du pavillon d'un terme. */
export function flagSvg(index, { off = false, title = '' } = {}) {
  const flag = flagFor(index);
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 30 20');
  svg.setAttribute('preserveAspectRatio', 'none');
  svg.setAttribute('class', 'flag' + (off ? ' flag-off' : ''));
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', title ? `Pavillon ${flag.letter} : ${title}` : `Pavillon ${flag.letter}`);
  svg.innerHTML = flag.draw();
  return svg;
}

export function termColor(index) {
  return flagFor(index).key;
}
