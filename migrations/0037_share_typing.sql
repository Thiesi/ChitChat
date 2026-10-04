-- The typing indicator works both ways: someone who turns it off neither
-- signals their own typing nor sees anyone else's.
ALTER TABLE users ADD COLUMN share_typing BOOLEAN NOT NULL DEFAULT TRUE;
