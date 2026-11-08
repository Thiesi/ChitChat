// The one place that knows how the server describes person reports and
// person cases (#159). Everything else works with the shapes returned here,
// so a renamed field or endpoint changes only this file.
import { apiPost } from './api.js';

const REPORT_USER_ENDPOINT = '/api/v1/reports/user.php';

export const ALREADY_REPORTED = 'already_reported';
export const REPORT_NOT_ALLOWED = 'report_not_allowed';

/** Categories only a person can be reported for, shown first in the dialog. */
export const PERSON_CATEGORIES = [
  ['offensive_username', 'Offensive username'],
  ['offensive_profile_picture', 'Offensive profile picture'],
];

/** Labels for every category, for the moderation queue. */
export const CATEGORY_LABELS = {
  offensive_username: 'Offensive username',
  offensive_profile_picture: 'Offensive profile picture',
  spam: 'Spam',
  harassment: 'Harassment',
  hate: 'Hate speech',
  threats: 'Threats or violence',
  sexual_content: 'Sexual content',
  privacy: 'Privacy violation',
  impersonation: 'Impersonation',
  other: 'Other',
};

export const PROFILE_PICTURE_REMOVED = 'profile_picture_removed';

/** Whether the profile card may offer "Report {name}". Advisory: the server decides on submit. */
export function canReport(profile) {
  return profile?.can_report === true;
}

export function reportedByMe(profile) {
  return profile?.reported_by_me === true;
}

/** Sends a person report. `roomId` is the room the card was opened in, if any. */
export function reportPerson({ userId, category, details, roomId }) {
  return apiPost(REPORT_USER_ENDPOINT, {
    user_id: userId,
    category,
    details: details || null,
    context: Number.isInteger(roomId) ? { room_id: roomId } : null,
  });
}

// ---- Moderation queue ----

/** The query value that limits the queue to one kind, or null for all. */
export function subjectKindFilter(kind) {
  return kind === 'user' || kind === 'message' ? kind : null;
}

export const SUBJECT_KIND_PARAMETER = 'subject_kind';

export function isPersonCase(item) {
  return item?.subject_kind === 'user';
}

/**
 * The room a person case was reported from, as the case remembers it, or
 * null for global scope. A purged room keeps its name with available: false.
 */
export function caseRoom(item) {
  const room = item?.room;
  if (!room) return null;
  return {
    id: Number.isInteger(room.id) ? room.id : null,
    name: typeof room.name === 'string' && room.name !== '' ? room.name : null,
    available: room.available !== false,
  };
}

/** "# The living room", or "# Old room · removed" for a purged room. */
export function caseRoomLabel(room) {
  const name = `# ${room.name ?? 'Unnamed room'}`;
  return room.available ? name : `${name} · removed`;
}

/**
 * What a person case shows as evidence: the name and picture as they were at
 * the first report, and whether either has changed since.
 */
export function personEvidence(item) {
  const snapshot = item?.snapshot ?? {};
  const thenName = typeof snapshot.username === 'string' ? snapshot.username : item?.subject?.username ?? '';
  const nowName = item?.subject?.username ?? thenName;
  return {
    usernameThen: thenName,
    usernameNow: nowName,
    renamed: nowName !== thenName,
    avatarUrl: typeof snapshot.avatar_url === 'string' && snapshot.avatar_url !== '' ? snapshot.avatar_url : null,
    avatarChanged: item?.avatar_changed_since_report === true,
  };
}
