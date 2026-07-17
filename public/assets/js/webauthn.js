export function webAuthnSupported() {
  return window.isSecureContext && 'PublicKeyCredential' in window && navigator.credentials;
}

export async function createPasskey(options) {
  requireSupport();
  const credential = await navigator.credentials.create({
    publicKey: normalizeCreationOptions(options),
  });
  if (!(credential instanceof PublicKeyCredential)) {
    throw new Error('The authenticator did not return a public-key credential.');
  }
  return serializeCredential(credential);
}

export async function getPasskey(options) {
  requireSupport();
  const credential = await navigator.credentials.get({
    publicKey: normalizeRequestOptions(options),
  });
  if (!(credential instanceof PublicKeyCredential)) {
    throw new Error('The authenticator did not return a public-key credential.');
  }
  return serializeCredential(credential);
}

function requireSupport() {
  if (!webAuthnSupported()) {
    throw new Error('Passkeys require a secure context and a browser with WebAuthn support.');
  }
}

function normalizeCreationOptions(options) {
  return {
    ...options,
    challenge: decodeBase64Url(options.challenge),
    user: {
      ...options.user,
      id: decodeBase64Url(options.user.id),
    },
    excludeCredentials: Array.isArray(options.excludeCredentials)
      ? options.excludeCredentials.map(normalizeDescriptor)
      : [],
  };
}

function normalizeRequestOptions(options) {
  return {
    ...options,
    challenge: decodeBase64Url(options.challenge),
    allowCredentials: Array.isArray(options.allowCredentials)
      ? options.allowCredentials.map(normalizeDescriptor)
      : [],
  };
}

function normalizeDescriptor(descriptor) {
  return {
    ...descriptor,
    id: decodeBase64Url(descriptor.id),
  };
}

function serializeCredential(credential) {
  const response = credential.response;
  const serialized = {
    id: credential.id,
    rawId: encodeBase64Url(credential.rawId),
    type: credential.type,
    authenticatorAttachment: credential.authenticatorAttachment ?? null,
    clientExtensionResults: credential.getClientExtensionResults(),
    response: {
      clientDataJSON: encodeBase64Url(response.clientDataJSON),
    },
  };

  if (response instanceof AuthenticatorAttestationResponse) {
    serialized.response.attestationObject = encodeBase64Url(response.attestationObject);
    serialized.response.transports = typeof response.getTransports === 'function'
      ? response.getTransports()
      : [];
  } else if (response instanceof AuthenticatorAssertionResponse) {
    serialized.response.authenticatorData = encodeBase64Url(response.authenticatorData);
    serialized.response.signature = encodeBase64Url(response.signature);
    serialized.response.userHandle = response.userHandle === null
      ? null
      : encodeBase64Url(response.userHandle);
  } else {
    throw new Error('The authenticator response type is unsupported.');
  }

  return serialized;
}

function decodeBase64Url(value) {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
  const padded = base64 + '='.repeat((4 - base64.length % 4) % 4);
  const binary = atob(padded);
  const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index);
  }
  return bytes.buffer;
}

function encodeBase64Url(value) {
  const bytes = new Uint8Array(value);
  let binary = '';
  for (let offset = 0; offset < bytes.length; offset += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/u, '');
}
