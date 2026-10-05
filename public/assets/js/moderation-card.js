// The Moderation part of the profile card: only what the viewer may do to
// this person, in the room the card was opened in and everywhere. The
// server worked out the options (users/profile.php) and checks every action.
import { apiPost } from './api.js';
import { formatDateTime } from './datetime.js';
import { chooseGuestBlock, chooseRestriction, confirmAction } from './moderation-tools.js';

/**
 * @param {{ id: number, username: string, moderation: null | { room: null | object, everywhere: null | object, guest?: null | object } }} profile
 * @param {{ refresh: () => void, report: (text: string, afterRefresh: boolean) => void }} hooks
 * @returns {HTMLElement | null}
 */
export function moderationSection(profile, { refresh, report }) {
  const options = profile.moderation;
  if (!options || (!options.room && !options.everywhere && !options.guest)) return null;
  const section = document.createElement('div');
  section.className = 'name-menu-moderation';
  const name = profile.username;
  const run = async (button, work, done) => {
    button.disabled = true;
    try {
      await work();
      report(done, true);
      refresh();
    } catch (error) {
      report(error instanceof Error ? error.message : 'That did not work.', false);
      button.disabled = false;
    }
  };

  const room = options.room;
  if (room) {
    const group = groupWithLabel(`In #${room.name}`);
    if (room.mute) group.append(status(`Muted here ${until(room.mute.expires_at)}${room.mute.reason ? ` · “${room.mute.reason}”` : ''}`));
    if (room.can_set_moderator && room.member_role) {
      const promote = room.member_role !== 'moderator';
      group.append(item(promote ? 'Make room moderator' : 'Remove room moderator', false, (button) => run(
        button,
        () => apiPost('/api/v1/rooms/role.php', { room_id: room.id, target_user_id: profile.id, role: promote ? 'moderator' : 'member' }),
        promote ? `${name} moderates #${room.name} now.` : `${name} no longer moderates #${room.name}.`,
      )));
    }
    if (room.can_mute) {
      group.append(room.mute
        ? item(`Lift mute in #${room.name}`, false, (button) => run(button, () => apiPost('/api/v1/moderation/unmute.php', { mute_id: room.mute.id }), `${name} can post in #${room.name} again.`))
        : item(`Mute in #${room.name}…`, true, async (button) => {
          const choice = await chooseRestriction({
            kind: 'mute',
            title: `Mute ${name} in #${room.name}?`,
            confirmLabel: `Mute ${name}`,
            reasonHint: `optional, shown to ${name} and kept in the audit log`,
            effect: (span) => `${name} can still read #${room.name} but cannot post there ${span}. ${name} is told.`,
            announce: (span) => `${name} was muted in this room ${span}.`,
            formatUntil: (date) => formatDateTime(date),
          });
          if (!choice) return;
          await run(button, () => apiPost('/api/v1/moderation/mute.php', {
            user_id: profile.id, room_id: room.id, expires_at: choice.expiresAt, reason: choice.reason, announce: choice.announce,
          }), `${name} is muted in #${room.name}.`);
        }));
    }
    if (room.can_remove && room.member_role) {
      group.append(item(`Remove from #${room.name}…`, true, async (button) => {
        const choice = await confirmAction({
          title: `Remove ${name} from #${room.name}?`,
          text: `${name} leaves the room at once. In a public room they can join again; in a private one they need a new invitation.`,
          confirmLabel: 'Remove',
          announce: `${name} was removed from this room.`,
        });
        if (!choice) return;
        await run(button, () => apiPost('/api/v1/admin/rooms/remove-member.php', {
          room_id: room.id, target_user_id: profile.id, announce: choice.announce,
        }), `${name} was removed from #${room.name}.`);
      }));
    }
    section.append(document.createElement('hr'), group);
  }

  const everywhere = options.everywhere;
  if (everywhere) {
    const group = groupWithLabel('Everywhere');
    if (everywhere.mute) group.append(status(`Muted everywhere ${until(everywhere.mute.expires_at)}${everywhere.mute.reason ? ` · “${everywhere.mute.reason}”` : ''}`));
    if (everywhere.ban) group.append(status(`Banned ${until(everywhere.ban.expires_at)}${everywhere.ban.reason ? ` · “${everywhere.ban.reason}”` : ''}`));
    if (everywhere.can_kick && !everywhere.ban) {
      group.append(item('Sign out everywhere', true, async (button) => {
        const choice = await confirmAction({
          title: `Sign ${name} out everywhere?`,
          text: `${name} is signed out on every device at once and can sign in again right away.`,
          confirmLabel: 'Sign out',
          announce: null,
        });
        if (choice) await run(button, () => apiPost('/api/v1/admin/kick.php', { target_user_id: profile.id }), `${name} was signed out everywhere.`);
      }));
    }
    if (everywhere.can_mute) {
      group.append(everywhere.mute
        ? item('Lift mute everywhere', false, (button) => run(button, () => apiPost('/api/v1/moderation/unmute.php', { mute_id: everywhere.mute.id }), `${name} can post again.`))
        : item('Mute everywhere…', true, async (button) => {
          const choice = await chooseRestriction({
            kind: 'mute',
            title: `Mute ${name} everywhere?`,
            confirmLabel: `Mute ${name}`,
            reasonHint: `optional, shown to ${name} and kept in the audit log`,
            effect: (span) => `${name} can still sign in and read, but cannot post in any room or send direct messages ${span}. ${name} is told.`,
            announce: null,
            formatUntil: (date) => formatDateTime(date),
          });
          if (!choice) return;
          await run(button, () => apiPost('/api/v1/moderation/mute.php', {
            user_id: profile.id, room_id: null, expires_at: choice.expiresAt, reason: choice.reason, announce: false,
          }), `${name} is muted everywhere.`);
        }));
    }
    if (everywhere.can_ban) {
      group.append(everywhere.ban
        ? item('Lift ban', false, (button) => run(button, () => apiPost('/api/v1/admin/unban.php', { target_user_id: profile.id }), `${name} can sign in again.`))
        : item('Ban…', true, async (button) => {
          const choice = await chooseRestriction({
            kind: 'ban',
            title: `Ban ${name}?`,
            confirmLabel: `Ban ${name}`,
            reasonHint: 'optional, kept with the ban and in the audit log',
            effect: (span) => `${name} is signed out at once and cannot sign in ${span}. You can lift the ban earlier from this card.`,
            announce: room ? (span) => `${name} was banned ${span}.` : null,
            formatUntil: (date) => formatDateTime(date),
          });
          if (!choice) return;
          await run(button, () => apiPost('/api/v1/admin/ban.php', {
            target_user_id: profile.id,
            reason: choice.reason,
            expires_at: choice.expiresAt,
            room_id: room?.id ?? null,
            announce: choice.announce,
          }), `${name} is banned.`);
        }));
    }
    if (everywhere.can_open_administration) {
      const link = document.createElement('a');
      link.className = 'menu-item';
      link.href = `/admin.php?user=${encodeURIComponent(profile.username)}`;
      link.textContent = 'Open in Administration';
      group.append(link);
    }
    section.append(document.createElement('hr'), group);
  }

  // A guest is not signed out or banned: the visit ends, or the connection is blocked for a while.
  const guest = options.guest;
  if (guest) {
    const group = groupWithLabel('Guest session');
    if (guest.can_end) {
      group.append(item('End guest session', true, async (button) => {
        const choice = await confirmAction({
          title: `End ${name}'s visit?`,
          text: `${name} is signed out at once and leaves every room. Their messages stay. They can start a new guest visit unless you also block their connection.`,
          confirmLabel: 'End visit',
          announce: null,
        });
        if (choice) await run(button, () => apiPost('/api/v1/moderation/guest-end.php', { user_id: profile.id }), `${name}'s visit has ended.`);
      }));
    }
    if (guest.can_block_connection) {
      group.append(item('Block guests from this connection…', true, async (button) => {
        const choice = await chooseGuestBlock({ name });
        if (!choice) return;
        await run(button, () => apiPost('/api/v1/moderation/guest-block.php', {
          user_id: profile.id, duration_seconds: choice.seconds, reason: choice.reason,
        }), `Guests from ${name}'s connection are blocked.`);
      }));
    }
    section.append(document.createElement('hr'), group);
  }
  return section;
}

function groupWithLabel(text) {
  const group = document.createElement('div');
  group.className = 'name-menu-group';
  const label = document.createElement('p');
  label.className = 'name-menu-group-label';
  label.textContent = text;
  group.append(label);
  return group;
}

function item(text, danger, handler) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = danger ? 'menu-item danger-item' : 'menu-item';
  button.textContent = text;
  button.addEventListener('click', () => handler(button));
  return button;
}

function status(text) {
  const node = document.createElement('p');
  node.className = 'name-menu-status';
  node.textContent = text;
  return node;
}

function until(expiresAt) {
  return expiresAt ? `until ${formatDateTime(expiresAt)}` : 'until lifted';
}
