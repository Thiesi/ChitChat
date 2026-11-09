import fs from 'node:fs';
import { expect, test } from '@playwright/test';

const cases = JSON.parse(fs.readFileSync(new URL('../fixtures/mention-punctuation.json', import.meta.url), 'utf8'));

test('resolved mention punctuation renders consistently without highlighting emails or consuming suffixes', async ({ page }) => {
  await page.route('**/mention-punctuation-fixture', route => route.fulfill({
    contentType: 'text/html', body: '<!doctype html><html lang="en"><head><title>Mention fixture</title></head><body><main id="fixture"></main></body></html>',
  }));
  await page.goto('/mention-punctuation-fixture');
  for (const nameButtons of [false, true]) {
    for (const sample of cases) {
      const actual = await page.evaluate(async ({ sample, nameButtons }) => {
        const { renderMessageBody } = await import('/assets/js/message-content.js');
        const mentions = sample.known.map((username, index) => ({ username, user_id: index + 1 }));
        if (sample.broadcast) mentions.push({ username: 'Other participant', user_id: 999, broadcast: true });
        const container = document.getElementById('fixture');
        renderMessageBody(container, sample.body, mentions, { nameButtons });
        return {
          text: container.textContent,
          spans: [...container.querySelectorAll('.mention')].map(node => node.textContent),
          broadcasts: [...container.querySelectorAll('.mention-broadcast')].map(node => node.textContent),
          buttons: [...container.querySelectorAll('button.mention')].map(node => node.textContent),
        };
      }, { sample, nameButtons });
      expect(actual.text, sample.body).toBe(sample.body);
      expect(actual.spans, sample.body).toEqual(sample.spans);
      const broadcastSpans = sample.spans.filter(spelling => sample.broadcast && ['@room', '@here'].includes(spelling.toLowerCase()));
      expect(actual.broadcasts, sample.body).toEqual(broadcastSpans);
      expect(actual.buttons, sample.body).toEqual(nameButtons ? sample.spans.filter(spelling => !broadcastSpans.includes(spelling)) : []);
    }
  }
});
