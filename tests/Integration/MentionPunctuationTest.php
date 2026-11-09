<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;

final class MentionPunctuationTest extends DatabaseTestCase
{
    public function testSentencePunctuationResolvesAndNotifiesInRoomsAndDirectMessages(): void
    {
        $auth = new AuthService($this->pdo,$this->config);
        $writer = $auth->register('Writer','a very secure password','127.0.0.1');
        $ada = $auth->register('Ada','another secure password','127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($writer,'mention-punctuation','Mentions','','private',0,0,'127.0.0.1');
        $rooms->invite($writer,$room->id,$ada->id,'127.0.0.1');
        $rooms->join($ada,$room->id,'127.0.0.2');
        $messages = new MessageService($this->pdo);
        $direct = new DirectMessageService($this->pdo);
        foreach (['@ada.', '@ada,', '@ada!', '@ada...', '(@ada)', '@ada.-'] as $body) {
            $sent = $messages->send($writer,$room->id,$body);
            self::assertSame([['user_id'=>$ada->id,'username'=>'Ada','broadcast'=>false]],$sent['mentions'],$body);
            self::assertSame($body,$sent['body']);
            $sent = $direct->send($writer,$ada->id,$body);
            self::assertSame([['user_id'=>$ada->id,'username'=>'Ada']],$sent['mentions'],$body);
            self::assertSame($body,$sent['body']);
        }
        self::assertSame(12,(int)$this->pdo->query("SELECT count(*) FROM account_notifications WHERE kind='mentioned'")->fetchColumn());
        foreach (['x@ada.de','name@ada.','@ab.'] as $body) {
            self::assertSame([],$messages->send($writer,$room->id,$body)['mentions'],$body);
            self::assertSame([],$direct->send($writer,$ada->id,$body)['mentions'],$body);
        }
        self::assertSame(12,(int)$this->pdo->query("SELECT count(*) FROM account_notifications WHERE kind='mentioned'")->fetchColumn());
    }

    public function testTheLongestMentionableNameWinsAndUnreadableUsersCannotShadowIt(): void
    {
        $auth = new AuthService($this->pdo,$this->config);
        $writer = $auth->register('Writer','a very secure password','127.0.0.1');
        $ada = $auth->register('Ada','another secure password','127.0.0.2');
        $longer = $auth->register('Ada.','a separate secure password','127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($writer,'mention-context','Mentions','','private',0,0,'127.0.0.1');
        $rooms->invite($writer,$room->id,$ada->id,'127.0.0.1');
        $rooms->join($ada,$room->id,'127.0.0.2');
        $messages = new MessageService($this->pdo);
        $sent = $messages->send($writer,$room->id,'@Ada @Ada.');
        self::assertSame([['user_id'=>$ada->id,'username'=>'Ada','broadcast'=>false]],$sent['mentions']);
        self::assertSame([['user_id'=>$ada->id,'username'=>'Ada']],
            (new DirectMessageService($this->pdo))->send($writer,$ada->id,'@Ada.')['mentions']);
        $rooms->invite($writer,$room->id,$longer->id,'127.0.0.1');
        $rooms->join($longer,$room->id,'127.0.0.3');
        $sent = $messages->send($writer,$room->id,'@Ada.- and @Ada');
        self::assertSame([$longer->id,$ada->id],array_column($sent['mentions'],'user_id'));
        self::assertSame([$longer->id,$ada->id],array_column($messages->storedMessage($sent['id'])['mentions'],'user_id'));
        self::assertSame([['user_id'=>$longer->id,'username'=>'Ada.']],
            (new DirectMessageService($this->pdo))->send($writer,$longer->id,'@Ada...')['mentions']);
    }

    public function testBroadcastKeywordsAcceptSentencePunctuationOnlyInRooms(): void
    {
        $auth = new AuthService($this->pdo,$this->config);
        $writer = $auth->register('Writer','a very secure password','127.0.0.1');
        $ada = $auth->register('Ada','another secure password','127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($writer,'broadcast-punctuation','Mentions','','public',0,0,'127.0.0.1');
        $rooms->join($ada,$room->id,'127.0.0.2');
        foreach (['@room.','@here...'] as $body) {
            $sent = (new MessageService($this->pdo))->send($writer,$room->id,$body);
            self::assertSame([['user_id'=>$ada->id,'username'=>'Ada','broadcast'=>true]],$sent['mentions']);
            self::assertSame([],(new DirectMessageService($this->pdo))->send($writer,$ada->id,$body)['mentions']);
        }
    }
}
