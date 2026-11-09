CREATE FUNCTION notify_push_dispatch() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.push_dispatched_at IS NULL AND NEW.read_at IS NULL AND NEW.kind <> 'privacy_policy_updated' THEN
        -- Empty payload: notification contents and routing data never enter the shared channel.
        -- PostgreSQL delivers this wake only when the producing transaction commits.
        PERFORM pg_notify('chitchat_push', '');
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER account_notifications_push_wakeup
    AFTER INSERT ON account_notifications FOR EACH ROW EXECUTE FUNCTION notify_push_dispatch();
