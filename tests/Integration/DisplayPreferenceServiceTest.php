<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\DisplayPreferenceService;
use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;

final class DisplayPreferenceServiceTest extends DatabaseTestCase
{
    public function testDateAndTimePreferencesDefaultToAutomaticAndAcceptOnlyOfferedValues(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $member = $auth->register('Member', 'a very secure password', '127.0.0.1');
        $preferences = new DisplayPreferenceService($this->pdo);

        self::assertSame(['date_locale' => null, 'hour_cycle' => null], $preferences->get($member->id));

        // A browser set to US English can still show German dates on a 24-hour clock.
        self::assertSame(
            ['date_locale' => 'de-DE', 'hour_cycle' => 'h23'],
            $preferences->update($member->id, 'de-DE', 'h23'),
        );
        self::assertSame(
            ['date_locale' => null, 'hour_cycle' => 'h12'],
            $preferences->update($member->id, null, 'h12'),
        );

        foreach ([['xx-YY', null], ['de-DE', 'h24']] as [$locale, $cycle]) {
            try {
                $preferences->update($member->id, $locale, $cycle);
                self::fail('Expected an unoffered value to be rejected.');
            } catch (ApiException $exception) {
                self::assertSame('validation_error', $exception->errorCode);
            }
        }
        self::assertSame(['date_locale' => null, 'hour_cycle' => 'h12'], $preferences->get($member->id));
    }
}
