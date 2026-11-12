// A person's name changed (#164: `user_renamed`, on a moderator's action and
// again when they choose a new name). Every rendered occurrence of that user
// ID takes the new name: authors, reply quotes, mentions and pings. Callers
// re-render from the updated state; the transcript's signatures notice the
// change. Literal "@OldName" typed inside message bodies is people's content
// and stays as written (docs/api/username-change.md).

/** The `user_renamed` payload's user, or null when it isn't one. */
export function renamedUser(payload) {
  const user = payload?.user;
  return Number.isInteger(user?.id) && typeof user.username === 'string' && user.username !== '' ? user : null;
}

/** Rewrites one person's name in room or direct messages; returns whether anything changed. */
export function renameInMessages(messages, { id, username }) {
  let changed = false;
  const person = (value) => {
    if (value && value.id === id && value.username !== username) {
      value.username = username;
      changed = true;
    }
  };
  for (const message of messages ?? []) {
    if (message.sender_id === id && message.username !== username) {
      message.username = username;
      changed = true;
    }
    person(message.sender);
    person(message.recipient);
    person(message.reply_to?.message?.sender);
    for (const mention of message.mentions ?? []) {
      if (mention.user_id === id && mention.username !== username) {
        mention.username = username;
        changed = true;
      }
    }
  }
  return changed;
}

/** Rewrites one person's name in room pings (sender and target). */
export function renameInPings(pings, { id, username }) {
  let changed = false;
  for (const ping of pings ?? []) {
    for (const value of [ping.sender, ping.target]) {
      if (value && value.id === id && value.username !== username) {
        value.username = username;
        changed = true;
      }
    }
  }
  return changed;
}
