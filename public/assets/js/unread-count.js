// Live additions to a room's unread badge (#268, #279). The room list's unread_count is a
// server snapshot; live room_message events add to it. A message the snapshot already
// covers (at or below unread_through_message_id), or one already read (at or below
// last_read_message_id), must not be counted again.
//
// Only the server moves those marks. Live counts don't: two senders' messages can reach
// the stream out of id order (message ids and event ids come from separate sequences),
// and moving the mark to a counted id would then skip the earlier message.

const UNREAD_CAP = 100;

/** Adds a live message to the room's badge unless it is already covered. Returns whether it counted. */
export function countLiveMessage(room, messageId) {
  if (!room || !Number.isInteger(messageId)) return false;
  for (const covered of [room.last_read_message_id, room.unread_through_message_id]) {
    if (Number.isInteger(covered) && messageId <= covered) return false;
  }
  room.unread_count = Math.min(UNREAD_CAP, (room.unread_count ?? 0) + 1);
  return true;
}
