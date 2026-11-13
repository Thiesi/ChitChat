// Live additions to a room's unread badge (#268, #279, #286). The room list's unread_count is
// a server snapshot; live room_message events add to it. A message the snapshot already
// counted, or one already read (at or below last_read_message_id), must not be counted again.
//
// Servers with capabilities.realtime_settled_resume send unread_settled_message_id (every
// message at or below it had committed when the counts were taken) and unread_counted_ids
// (the counted ids above it). That counts each message once whatever order messages commit
// in. Older servers send only unread_through_message_id, which skips a message that commits
// after the snapshot with a lower id.
//
// Only the server moves those marks. Live counts don't: two senders' messages can reach
// the stream out of id order (message ids and event ids come from separate sequences),
// and moving the mark to a counted id would then skip the earlier message.

const UNREAD_CAP = 100;

/** Adds a live message to the room's badge unless it is already covered. Returns whether it counted. */
export function countLiveMessage(room, messageId) {
  if (!room || !Number.isInteger(messageId)) return false;
  const settled = Array.isArray(room.unread_counted_ids);
  const marks = settled
    ? [room.last_read_message_id, room.unread_settled_message_id]
    : [room.last_read_message_id, room.unread_through_message_id];
  for (const covered of marks) {
    if (Number.isInteger(covered) && messageId <= covered) return false;
  }
  if (settled) {
    // At the cap the count is "100 or more" until the next fetch or read.
    if ((room.unread_count ?? 0) >= UNREAD_CAP) return false;
    if (room.unread_counted_ids.includes(messageId)) return false;
    // A resent event for the same message never counts twice either. Ids already read can go.
    const read = Number.isInteger(room.last_read_message_id) ? room.last_read_message_id : 0;
    room.unread_counted_ids = [...room.unread_counted_ids.filter((id) => id > read), messageId];
  }
  room.unread_count = Math.min(UNREAD_CAP, (room.unread_count ?? 0) + 1);
  return true;
}
