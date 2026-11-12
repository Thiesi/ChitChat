// Live additions to a room's unread badge (#268, #279). The room list's unread_count is a
// server snapshot; live room_message events add to it. A message the snapshot already
// covers (at or below unread_through_message_id), or one already read (at or below
// last_read_message_id), must not be counted again. The watermark moves with every
// counted message, so a repeated event can't count twice either.

const UNREAD_CAP = 100;

/** Adds a live message to the room's badge unless it is already covered. Returns whether it counted. */
export function countLiveMessage(room, messageId) {
  if (!room || !Number.isInteger(messageId)) return false;
  for (const covered of [room.last_read_message_id, room.unread_through_message_id]) {
    if (Number.isInteger(covered) && messageId <= covered) return false;
  }
  room.unread_count = Math.min(UNREAD_CAP, (room.unread_count ?? 0) + 1);
  room.unread_through_message_id = messageId;
  return true;
}
