import { createHash, generateKeyPairSync, randomBytes, sign } from 'node:crypto';
import { createServer } from 'node:http';

/*
 * A tiny OpenID Connect provider for the browser tests, standing in for
 * Google. CI points ChitChat at it with OIDC_GOOGLE_ENDPOINT_OVERRIDE, which
 * the server only accepts in development and test environments. It approves
 * every sign-in at once as the subject set with useSubject(), and signs real
 * RS256 ID tokens so the server's verification runs unchanged.
 */
export const MOCK_OIDC_PORT = 9177;
const issuer = `http://127.0.0.1:${MOCK_OIDC_PORT}`;
const clientId = 'chitchat-e2e';
const clientSecret = 'e2e-secret';

export async function startMockOidc() {
  const { privateKey, publicKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
  const jwk = { ...publicKey.export({ format: 'jwk' }), kid: 'mock-1', use: 'sig', alg: 'RS256' };
  const codes = new Map();
  let subject = 'mock-subject';

  const b64 = (value) => Buffer.from(value).toString('base64url');
  const idToken = (claims) => {
    const header = b64(JSON.stringify({ alg: 'RS256', typ: 'JWT', kid: 'mock-1' }));
    const payload = b64(JSON.stringify(claims));
    const signature = sign('sha256', Buffer.from(`${header}.${payload}`), privateKey).toString('base64url');
    return `${header}.${payload}.${signature}`;
  };

  const server = createServer((request, response) => {
    const url = new URL(request.url, issuer);
    if (url.pathname === '/keys') {
      response.writeHead(200, { 'Content-Type': 'application/json' });
      response.end(JSON.stringify({ keys: [jwk] }));
      return;
    }
    if (url.pathname === '/authorize') {
      const query = url.searchParams;
      if (query.get('client_id') !== clientId || query.get('scope') !== 'openid' || query.get('code_challenge_method') !== 'S256') {
        response.writeHead(400).end('bad authorization request');
        return;
      }
      const code = randomBytes(16).toString('hex');
      codes.set(code, {
        nonce: query.get('nonce'),
        challenge: query.get('code_challenge'),
        redirectUri: query.get('redirect_uri'),
        subject,
      });
      const back = new URL(query.get('redirect_uri'));
      back.searchParams.set('code', code);
      back.searchParams.set('state', query.get('state'));
      response.writeHead(302, { Location: back.toString() }).end();
      return;
    }
    if (url.pathname === '/token' && request.method === 'POST') {
      let body = '';
      request.on('data', (chunk) => { body += chunk; });
      request.on('end', () => {
        const form = new URLSearchParams(body);
        const grant = codes.get(form.get('code'));
        codes.delete(form.get('code'));
        const challenge = createHash('sha256').update(form.get('code_verifier') ?? '').digest('base64url');
        if (!grant || grant.challenge !== challenge || form.get('client_secret') !== clientSecret
          || form.get('client_id') !== clientId || form.get('redirect_uri') !== grant.redirectUri) {
          response.writeHead(400, { 'Content-Type': 'application/json' }).end('{"error":"invalid_grant"}');
          return;
        }
        const now = Math.floor(Date.now() / 1000);
        response.writeHead(200, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({
          access_token: 'unused',
          token_type: 'Bearer',
          id_token: idToken({ iss: issuer, aud: clientId, sub: grant.subject, nonce: grant.nonce, iat: now, exp: now + 300 }),
        }));
      });
      return;
    }
    response.writeHead(404).end();
  });

  await new Promise((resolve) => server.listen(MOCK_OIDC_PORT, '127.0.0.1', resolve));
  return {
    useSubject(value) { subject = value; },
    close: () => new Promise((resolve) => server.close(resolve)),
  };
}
