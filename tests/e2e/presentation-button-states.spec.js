// Every button variant keeps readable labels and a visible focus ring in
// every colour scheme and theme, at rest, under the pointer, while pressed
// and with keyboard focus (#235). Contrast is computed from the styles the
// browser actually applies, compositing translucent fills over what lies
// beneath, so hover tints and color-mix() fills are measured as people see
// them. States come from real pointer and keyboard input, so the check runs
// the same way in Chromium and Firefox.
import { readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { test, expect } from '@playwright/test';

const CSS_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../public/assets/css');
const FIRST = ['tokens.css', 'app.css', 'components.css'];
const STYLESHEETS = [...FIRST, ...readdirSync(CSS_DIR).filter((name) => name.endsWith('.css') && !FIRST.includes(name)).sort()];

const SCHEMES = ['lantern', 'lounge', 'dusk', 'ember', 'rose', 'midnight', 'contrast'];
const THEMES = ['dark', 'light'];

// Each variant as the element and in the context its rules expect (links
// and file labels are not <button>s, so they never pick up the browser's
// default button fill); "on" variants are the selected or pressed looks.
const VARIANTS = [
  ['primary-button'], ['secondary-button'], ['join-button'], ['danger-button'],
  ['text-button'], ['link-button', 'provider-sign-up-note'], ['icon-button'], ['secondary-button admin-link-button', '', '', 'a'],
  ['room-button'], ['room-button active'], ['dm-button', '', '', 'a'], ['dm-button unread', '', '', 'a'],
  ['conversation-button'], ['conversation-button active'], ['inspection-user-button'], ['dm-user-button'],
  ['moderation-case-button'], ['moderation-case-button active'],
  ['header-button'], ['header-button', '', 'aria-pressed="true"'], ['user-button'],
  ['close-button', 'chat-shell'], ['emoji-button'], ['attachment-button', '', '', 'label'], ['secondary-button file-button', '', '', 'label'],
  ['reaction-button'], ['reaction-button reaction-button-active'], ['reaction-add-button'],
  ['message-reply-button'], ['message-react-button'], ['message-more-button'], ['message-mutation-button'],
];

function page_html() {
  const links = STYLESHEETS.map((name) => `<link rel="stylesheet" href="/assets/css/${name}">`).join('\n');
  const groups = ['surface', 'ground'].map((ground) => `<section class="probe-${ground}">${VARIANTS.map(([cls, parent = '', attrs = '', tag = 'button'], index) => {
    const kind = tag === 'a' ? 'href="#probe"' : tag === 'label' ? 'tabindex="0"' : 'type="button"';
    const button = `<${tag} ${kind} class="${cls}" data-probe="${ground}-${index}" ${attrs}>Label</${tag}>`;
    return parent ? `<div class="${parent}">${button}</div>` : button;
  }).join('\n')}</section>`).join('\n');
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Button states</title>${links}
<style>
*, *::before, *::after { transition: none !important; animation: none !important; }
section { display: flex; flex-wrap: wrap; gap: 1rem; padding: 1rem; }
section > *, section > * > * { position: static !important; }
.probe-surface { background: var(--surface); }
.probe-ground { background: var(--ground); }
.chat-shell { display: block; height: auto; min-height: 0; }
</style></head><body><button type="button" id="probe-start">Start</button>${groups}</body></html>`;
}

// Measures one button in its current state, in the page.
function measure(selector) {
  const parse = (value) => {
    if (!value || value === 'transparent') return [0, 0, 0, 0];
    let match = value.match(/^rgba?\(([^)]+)\)$/);
    if (match) {
      const parts = match[1].split(/[\s,/]+/).filter(Boolean).map(Number);
      return [parts[0], parts[1], parts[2], parts[3] ?? 1];
    }
    match = value.match(/^color\(srgb ([^)]+)\)$/);
    if (match) {
      const parts = match[1].split(/[\s/]+/).filter(Boolean).map(Number);
      return [parts[0] * 255, parts[1] * 255, parts[2] * 255, parts[3] ?? 1];
    }
    throw new Error(`Unparsed colour ${value}`);
  };
  const over = (top, bottom) => {
    const alpha = top[3];
    return [0, 1, 2].map((i) => top[i] * alpha + bottom[i] * (1 - alpha)).concat(1);
  };
  const behind = (node) => {
    const chain = [];
    for (let current = node; current; current = current.parentElement) chain.unshift(current);
    let colour = [255, 255, 255, 1];
    for (const element of chain) colour = over(parse(getComputedStyle(element).backgroundColor), colour);
    return colour;
  };
  const luminance = ([r, g, b]) => {
    const channel = (value) => {
      const c = value / 255;
      return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
  };
  const ratio = (a, b) => {
    const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (high + 0.05) / (low + 0.05);
  };

  const button = document.querySelector(selector);
  const style = getComputedStyle(button);
  if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) < 0.5) return null;
  const parentGround = behind(button.parentElement);
  const fill = over(parse(style.backgroundColor), parentGround);
  const opacity = Number(style.opacity);
  const blend = (colour) => [0, 1, 2].map((i) => colour[i] * opacity + parentGround[i] * (1 - opacity)).concat(1);
  const label = blend(over(parse(style.color), fill));
  const result = { text: ratio(label, blend(fill)), focusVisible: button.matches(':focus-visible') };
  if (result.focusVisible) {
    const width = parseFloat(style.outlineWidth);
    result.outline = style.outlineStyle !== 'none' && width > 0
      ? ratio(over(parse(style.outlineColor), parentGround), parentGround)
      : 0;
  }
  return result;
}

for (const browserScheme of SCHEMES) {
  test(`buttons in ${browserScheme} keep readable labels and focus rings in every state`, async ({ page }) => {
    await page.route('**/__button-states.html', (route) => route.fulfill({ contentType: 'text/html', body: page_html() }));
    await page.goto('/__button-states.html');
    const failures = [];
    let measured = 0;
    for (const theme of THEMES) {
      await page.evaluate(([scheme, mode]) => {
        document.documentElement.dataset.scheme = scheme;
        document.documentElement.dataset.theme = mode;
      }, [browserScheme, theme]);
      for (const ground of ['surface', 'ground']) {
        for (const [index, [cls, , attrs = '']] of VARIANTS.entries()) {
          const selector = `[data-probe="${ground}-${index}"]`;
          const name = `${theme} ${ground} .${cls.replace(/ /g, '.')}${attrs ? `[${attrs}]` : ''}`;
          const button = page.locator(selector);
          await button.scrollIntoViewIfNeeded();
          const box = await button.boundingBox();
          const check = async (state) => {
            const result = await page.evaluate(measure, selector);
            if (!result) return;
            measured += 1;
            if (result.text < 4.5) failures.push(`${name} ${state}: label ${result.text.toFixed(2)}:1`);
            if (state === 'focus' && !result.focusVisible) failures.push(`${name} focus: not :focus-visible`);
            if (state === 'focus' && result.outline < 3) failures.push(`${name} focus: ring ${result.outline.toFixed(2)}:1`);
          };

          await page.mouse.move(0, 0);
          await page.evaluate(() => document.activeElement?.blur());
          await check('rest');
          await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
          await check('hover');
          await page.mouse.down();
          await check('pressed');
          await page.mouse.up();
          await page.mouse.move(0, 0);
          await page.evaluate(() => document.activeElement?.blur());
          // Real keyboard focus (Firefox shows no ring for a scripted focus after a click).
          await button.focus();
          await page.keyboard.press('Shift+Tab');
          await page.keyboard.press('Tab');
          await check('focus');
        }
      }
    }
    expect(measured).toBeGreaterThan(VARIANTS.length * 4);
    expect(failures, failures.join('\n')).toEqual([]);
  });
}
