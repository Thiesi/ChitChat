<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Account\PersonalDataExportService;
use ChitChat\Account\PrivacyNotificationService;
use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageMutationService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomMessageMutationService;
use ChitChat\Room\RoomService;

final class ReplyAndMentionTest extends DatabaseTestCase
{
    public function testRoomMentionResolvesOnlyAuthorizedNonSelfAccountsAndNotifiesThem(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $outsider = $auth->register('Outsider', 'yet another secure password', '127.0.0.3');

        $rooms = new RoomService($this->pdo);
        $private = $rooms->create($admin, 'private', 'Private', '', 'private', 0, 0, '127.0.0.1');
        $rooms->invite($admin, $private->id, $member->id, '127.0.0.1');
        $rooms->join($member, $private->id, '127.0.0.2');

        $messages = new MessageService($this->pdo);
        $sent = $messages->send(
            $admin,
            $private->id,
            'Hey @Member and @Outsider and @Admin, see this @unknownuser',
        );

        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM room_message_mentions')->fetchColumn(),
        );
        $row = $this->pdo->query(
            'SELECT mentioned_user_id, broadcast FROM room_message_mentions',
        )->fetch();
        self::assertSame($member->id, (int) $row['mentioned_user_id']);
        self::assertFalse((bool) $row['broadcast']);

        self::assertSame(
            [['user_id' => $member->id, 'username' => 'Member', 'broadcast' => false]],
            $sent['mentions'],
        );
        self::assertSame(
            $sent['mentions'],
            (new MessageService($this->pdo))->storedMessage($sent['id'])['mentions'],
        );

        $notification = $this->pdo->prepare(
            "SELECT context_json FROM account_notifications WHERE user_id = :id AND kind = 'mentioned'",
        );
        $notification->execute(['id' => $member->id]);
        $context = $notification->fetch();
        self::assertIsArray($context);
        $decoded = json_decode((string) $context['context_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('room', $decoded['message_kind']);
        self::assertSame($sent['id'], $decoded['message_id']);
        self::assertFalse($decoded['broadcast']);

        $outsiderCount = $this->pdo->prepare(
            'SELECT COUNT(*) FROM account_notifications WHERE user_id = :id',
        );
        $outsiderCount->execute(['id' => $outsider->id]);
        self::assertSame(0, (int) $outsiderCount->fetchColumn());
    }

    public function testRegularMemberCanMentionTheRoomCreatorInAPublicRoom(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');

        $messages = new MessageService($this->pdo);
        $sent = $messages->send($member, $room->id, '@Admin thanks for the context');

        self::assertSame(
            [['user_id' => $admin->id, 'username' => 'Admin', 'broadcast' => false]],
            $sent['mentions'],
        );
    }

    public function testRoomAndDirectMessageMutationMetadataIncludeMentions(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');

        $roomMessage = (new MessageService($this->pdo))->send($member, $room->id, '@Admin room mutation metadata check');
        $roomMetadata = (new RoomMessageMutationService($this->pdo))->metadata($member, $room->id, [$roomMessage['id']]);
        self::assertSame(
            [['user_id' => $admin->id, 'username' => 'Admin', 'broadcast' => false]],
            $roomMetadata[0]['mentions'],
        );

        $directMessage = (new DirectMessageService($this->pdo))->send($member, $admin->id, '@Admin direct mutation metadata check');
        $directMetadata = (new DirectMessageMutationService($this->pdo))->metadata($member, [$directMessage['id']]);
        self::assertSame(
            [['user_id' => $admin->id, 'username' => 'Admin']],
            $directMetadata[0]['mentions'],
        );
    }

    public function testRoomBroadcastMentionNotifiesCurrentMembersExcludingSender(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $memberOne = $auth->register('MemberOne', 'another secure password', '127.0.0.2');
        $memberTwo = $auth->register('MemberTwo', 'yet another secure password', '127.0.0.3');
        $former = $auth->register('Former', 'still another secure password', '127.0.0.4');

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'team', 'Team', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($memberOne, $room->id, '127.0.0.2');
        $rooms->join($memberTwo, $room->id, '127.0.0.3');
        $rooms->join($former, $room->id, '127.0.0.4');
        $rooms->leave($former, $room->id, '127.0.0.4');

        $messages = new MessageService($this->pdo);
        $messages->send($admin, $room->id, 'Heads up @room, deployment incoming');

        $mentioned = $this->pdo->query(
            'SELECT mentioned_user_id, broadcast FROM room_message_mentions ORDER BY mentioned_user_id',
        )->fetchAll();
        $mentionedIds = array_map(static fn (array $row): int => (int) $row['mentioned_user_id'], $mentioned);
        sort($mentionedIds);

        self::assertSame([$memberOne->id, $memberTwo->id], $mentionedIds);
        foreach ($mentioned as $row) {
            self::assertTrue((bool) $row['broadcast']);
        }
    }

    public function testDirectMessageMentionOnlyResolvesTheRecipient(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $sender = $auth->register('Sender', 'a very secure password', '127.0.0.1');
        $recipient = $auth->register('Recipient', 'another secure password', '127.0.0.2');
        $outsider = $auth->register('Outsider', 'yet another secure password', '127.0.0.3');

        $direct = new DirectMessageService($this->pdo);
        $sent = $direct->send($sender, $recipient->id, 'Hi @Recipient, and hi @Outsider and @room too');

        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM direct_message_mentions')->fetchColumn(),
        );
        self::assertSame(
            [['user_id' => $recipient->id, 'username' => 'Recipient']],
            $sent['mentions'],
        );
        $mentionedUserId = $this->pdo->query(
            'SELECT mentioned_user_id FROM direct_message_mentions',
        )->fetchColumn();
        self::assertSame($recipient->id, (int) $mentionedUserId);
    }

    public function testRoomReplyPreviewReflectsSoftDeletionAndUnavailability(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $other = $rooms->create($admin, 'other', 'Other', '', 'public', 0, 0, '127.0.0.1');

        $messages = new MessageService($this->pdo);
        $original = $messages->send($admin, $room->id, 'Original message');
        $reply = $messages->send($admin, $room->id, 'A reply', $original['id']);

        self::assertNotNull($reply['reply_to']);
        self::assertSame('room', $reply['reply_to']['kind']);
        self::assertSame($original['id'], $reply['reply_to']['message_id']);
        self::assertTrue($reply['reply_to']['available']);
        self::assertSame('Original message', $reply['reply_to']['message']['body']);

        try {
            $messages->send($admin, $other->id, 'Cross-room reply', $original['id']);
            self::fail('Expected a reply targeting another room to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame('invalid_reply_target', $exception->errorCode);
        }

        $messages->delete($admin, $original['id'], '127.0.0.1');
        $afterSoftDelete = $messages->storedMessage($reply['id']);
        self::assertTrue($afterSoftDelete['reply_to']['available']);
        self::assertTrue($afterSoftDelete['reply_to']['message']['deleted']);
        self::assertNull($afterSoftDelete['reply_to']['message']['body']);

        $this->pdo->exec('DELETE FROM room_messages WHERE id = ' . $original['id']);
        $afterHardDelete = $messages->storedMessage($reply['id']);
        self::assertFalse($afterHardDelete['reply_to']['available']);
        self::assertNull($afterHardDelete['reply_to']['message']);
    }

    public function testDirectMessageReplyMustStayWithinTheSameConversation(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');
        $carol = $auth->register('Carol', 'yet another secure password', '127.0.0.3');

        $direct = new DirectMessageService($this->pdo);
        $original = $direct->send($alice, $bob->id, 'Original direct message');
        $reply = $direct->send($bob, $alice->id, 'Replying', $original['id']);

        self::assertNotNull($reply['reply_to']);
        self::assertSame('direct', $reply['reply_to']['kind']);
        self::assertTrue($reply['reply_to']['available']);

        try {
            $direct->send($alice, $carol->id, 'Wrong conversation', $original['id']);
            self::fail('Expected a reply targeting another conversation to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame('invalid_reply_target', $exception->errorCode);
        }
    }

    public function testPersonalDataExportIncludesSentAndReceivedMentionsWithoutOthersBodies(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');

        $messages = new MessageService($this->pdo);
        $messages->send($admin, $room->id, 'Hello @Member');

        $export = (new PersonalDataExportService($this->pdo, $this->config))->export($member, '127.0.0.4');

        self::assertCount(0, $export['mentions']['sent']);
        self::assertCount(1, $export['mentions']['received']);
        $received = $export['mentions']['received'][0];
        self::assertSame('room', $received['message_kind']);
        self::assertArrayNotHasKey('body', $received);

        $adminExport = (new PersonalDataExportService($this->pdo, $this->config))->export($admin, '127.0.0.1');
        self::assertCount(1, $adminExport['mentions']['sent']);
        self::assertSame('Member', $adminExport['mentions']['sent'][0]['mentioned_user']['username']);
    }

    public function testMentionNotificationRendersTextAndDeepLinkForRoomAndDirectMessages(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');
        $roomMessage = (new MessageService($this->pdo))->send($admin, $room->id, 'Hello @Member');

        $notifications = new PrivacyNotificationService($this->pdo);
        $timeline = $notifications->timeline($member);
        self::assertCount(1, $timeline['notifications']);
        $roomNotification = $timeline['notifications'][0];
        self::assertSame('mentioned', $roomNotification['kind']);
        self::assertSame('You were mentioned', $roomNotification['title']);
        self::assertSame('Admin mentioned you in “General”.', $roomNotification['message']);
        self::assertSame(
            sprintf('/?room_id=%d&message_id=%d', $room->id, $roomMessage['id']),
            $roomNotification['link'],
        );

        $direct = new DirectMessageService($this->pdo);
        $directMessage = $direct->send($admin, $member->id, 'Hi @Member');
        $updatedTimeline = $notifications->timeline($member);
        $directNotification = $updatedTimeline['notifications'][0];
        self::assertSame('Admin mentioned you in a direct message.', $directNotification['message']);
        self::assertSame(
            sprintf('/messages.php?user_id=%d&peer_name=Admin&message_id=%d', $admin->id, $directMessage['id']),
            $directNotification['link'],
        );
    }
}
