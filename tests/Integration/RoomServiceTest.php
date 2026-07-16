<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use DateTimeImmutable;

final class RoomServiceTest extends DatabaseTestCase
{
    public function testOnlyPrivilegedUsersCanCreateRooms(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $normal = $auth->register('Normal', 'another secure password', '127.0.0.2');

        try {
            (new RoomService($this->pdo))->create(
                $normal,
                'general',
                'General',
                '',
                'public',
                0,
                0,
                '127.0.0.2',
            );
            self::fail('Expected room creation to be forbidden.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }
    }

    public function testPrivateRoomRequiresInvitationAndMembershipForHistory(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $guest = $auth->register('Guest', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'private-room', 'Private', '', 'private', 0, 0, '127.0.0.1');

        try {
            $rooms->get($guest, $room->id);
            self::fail('Expected private room metadata to be hidden.');
        } catch (ApiException $exception) {
            self::assertSame('room_forbidden', $exception->errorCode);
        }

        $rooms->invite($admin, $room->id, $guest->id, '127.0.0.1');
        self::assertTrue($rooms->get($guest, $room->id)->invited);

        try {
            (new MessageService($this->pdo))->history($guest, $room->id);
            self::fail('Expected private history to require membership.');
        } catch (ApiException $exception) {
            self::assertSame('room_forbidden', $exception->errorCode);
        }

        $joined = $rooms->join($guest, $room->id, '127.0.0.2');
        self::assertSame('member', $joined->memberRole);
        self::assertFalse($joined->invited);
    }

    public function testMinimumAgeRequiresAnEligibleBirthDate(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $adultDate = (new DateTimeImmutable('today'))->modify('-25 years')->format('Y-m-d');
        $minorDate = (new DateTimeImmutable('today'))->modify('-15 years')->format('Y-m-d');
        $adult = $auth->register('Adult', 'another secure password', '127.0.0.2', $adultDate);
        $minor = $auth->register('Minor', 'different secure password', '127.0.0.3', $minorDate);
        $unknown = $auth->register('Unknown', 'further secure password', '127.0.0.4');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'adults', 'Adults', '', 'public', 18, 0, '127.0.0.1');

        self::assertSame('member', $rooms->join($adult, $room->id, '127.0.0.2')->memberRole);

        foreach ([[$minor, 'minimum_age_not_met'], [$unknown, 'birth_date_required']] as [$user, $errorCode]) {
            try {
                $rooms->join($user, $room->id, '127.0.0.9');
                self::fail('Expected minimum-age rejection.');
            } catch (ApiException $exception) {
                self::assertSame($errorCode, $exception->errorCode);
            }
        }
    }

    public function testUnlistedAndPrivateRoomsAreNotListedToOutsiders(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $guest = $auth->register('Guest', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $public = $rooms->create($admin, 'public-room', 'Public', '', 'public', 0, 0, '127.0.0.1');
        $rooms->create($admin, 'unlisted-room', 'Unlisted', '', 'unlisted', 0, 0, '127.0.0.1');
        $private = $rooms->create($admin, 'private-room', 'Private', '', 'private', 0, 0, '127.0.0.1');

        self::assertSame([$public->id], array_column($rooms->list($guest), 'id'));

        $rooms->invite($admin, $private->id, $guest->id, '127.0.0.1');
        self::assertSame(
            [$private->id, $public->id],
            array_column($rooms->list($guest), 'id'),
        );
    }

    public function testRoomInactivityTimeoutIsValidatedAndReturned(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'timed', 'Timed', '', 'public', 0, 600, '127.0.0.1');

        self::assertSame(600, $room->inactivityTimeoutSeconds);
        self::assertSame(600, $room->toArray()['inactivity_timeout_seconds']);

        try {
            $rooms->create($admin, 'too-short', 'Too short', '', 'public', 0, 60, '127.0.0.1');
            self::fail('Expected inactivity timeout validation.');
        } catch (ApiException $exception) {
            self::assertSame('invalid_inactivity_timeout', $exception->errorCode);
        }
    }
}
