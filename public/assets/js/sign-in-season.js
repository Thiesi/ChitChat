// Seasonal decoration on the sign-in screen (S3, #169; the #227 sheet, the
// switch is #242's admin setting `sign_in_decoration`). Static drawings set
// around the lockup and behind it: never on the mark, never animated, never
// on the favicon, the installation icon or a signed-in page. Decorative only
// (the layer is aria-hidden). Colours are classes in auth.css (tokens).
//
// Items sit in the outer columns of the intro (x ≤ 12% or ≥ 88%), and the
// lockup is narrower than 76% there (auth.css), so nothing overlaps the name.

const SVG = 'http://www.w3.org/2000/svg';
const BASE = 1.3; // the sheet's drawings, at sign-in size

// [x%, y%, scale] (winter, spring) or [x%, y%, degrees, colour] (celebration).
const LAYOUTS = {
  winter: [[6, 18, 1], [92, 15, 0.8], [9, 82, 0.7], [90, 76, 1.1], [96, 46, 0.55], [4, 50, 0.55], [89, 94, 0.6]],
  spring: [[7, 20, 1], [91, 18, 0.86], [8, 78, 0.72], [90, 77, 1.16], [96, 48, 0.58]],
  celebration: [
    [5, 16, 18, 0], [11, 7, -24, 1], [89, 10, 30, 2], [96, 28, -12, 3], [4, 70, 40, 4],
    [10, 90, -30, 0], [90, 86, 16, 1], [97, 66, -40, 2], [93, 3, 10, 3], [7, 44, 24, 4],
  ],
};

export const SEASONS = Object.keys(LAYOUTS);

/** Draws `kind` into the sign-in page's decoration layer; anything else clears it. */
export function renderSeason(kind, layer = document.querySelector('[data-season]')) {
  if (!layer) return;
  layer.replaceChildren();
  const layout = Object.hasOwn(LAYOUTS, kind) ? LAYOUTS[kind] : null;
  layer.hidden = layout === null;
  if (!layout) {
    delete layer.dataset.seasonKind;
    return;
  }
  layer.dataset.seasonKind = kind;
  const svg = node('svg', { width: '100%', height: '100%', focusable: 'false' });
  for (const item of layout) {
    // A zero-size viewport at the spot; the drawing is centred on it.
    const spot = node('svg', { x: `${item[0]}%`, y: `${item[1]}%`, width: '1', height: '1', overflow: 'visible' });
    spot.append(draw(kind, item));
    svg.append(spot);
  }
  layer.append(svg);
}

function draw(kind, item) {
  if (kind === 'winter') return flake(BASE * item[2]);
  if (kind === 'spring') return blossom(BASE * item[2]);
  return slip(item[2], item[3]);
}

// Six arms: three strokes through the centre.
function flake(scale) {
  const group = node('g', { class: 'season-flake' });
  const arm = 9 * scale;
  for (const degrees of [0, 60, 120]) {
    const radians = (degrees * Math.PI) / 180;
    const dx = (arm * Math.cos(radians)).toFixed(2);
    const dy = (arm * Math.sin(radians)).toFixed(2);
    group.append(node('path', { d: `M${-dx} ${-dy}L${dx} ${dy}` }));
  }
  return group;
}

// Five petals round a honey centre.
function blossom(scale) {
  const group = node('g', {});
  for (let petal = 0; petal < 5; petal += 1) {
    const radians = ((petal * 72 - 90) * Math.PI) / 180;
    group.append(node('circle', {
      class: 'season-petal',
      cx: (7 * scale * Math.cos(radians)).toFixed(2),
      cy: (7 * scale * Math.sin(radians)).toFixed(2),
      r: (4.3 * scale).toFixed(2),
    }));
  }
  group.append(node('circle', { class: 'season-centre', cx: '0', cy: '0', r: (3.1 * scale).toFixed(2) }));
  return group;
}

// A confetti slip in one of the scheme's colours.
function slip(degrees, colour) {
  const w = 9 * BASE;
  const h = 4 * BASE;
  return node('rect', {
    class: `season-slip-${colour}`,
    x: (-w / 2).toFixed(2), y: (-h / 2).toFixed(2), width: w.toFixed(2), height: h.toFixed(2), rx: '1.3',
    transform: `rotate(${degrees})`,
  });
}

function node(name, attributes) {
  const element = document.createElementNS(SVG, name);
  for (const [key, value] of Object.entries(attributes)) element.setAttribute(key, value);
  return element;
}
