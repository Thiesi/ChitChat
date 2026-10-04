/** The name this installation presents, as rendered into the page by the server. */
export function applicationName() {
  return document.querySelector('meta[name="application-name"]')?.content || document.title;
}
