<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Provider\Customer;

use Oro\Bundle\LocaleBundle\Model\LocaleSettings;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadCustomerContactData;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * The last contact date is the calendar date in the user's
 * timezone, so an email at 22:30 UTC is the next day for a user in Europe/Berlin. The configuration change runs in
 * its own transaction.
 *
 * @dbIsolationPerTest
 */
final class LastContactTimezoneTest extends WebTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        if (!LoadCustomerContactData::isApplicable()) {
            self::markTestSkipped('The call, conversation and task packages are not installed.');
        }
        $this->initClient([], self::generateBasicAuthHeader());
        $this->loadFixtures([LoadCustomerContactData::class]);
        $this->updateUserSecurityToken(self::AUTH_USER);
    }

    public function testContactDateIsTheUsersCalendarDate(): void
    {
        $configManager = self::getContainer()->get('oro_config.global');
        $configManager->set('oro_locale.timezone', 'Europe/Berlin');
        $configManager->flush();
        // the locale settings keep the timezone they read first
        $localeSettings = self::getContainer()->get('oro_locale.settings');
        (new \ReflectionProperty(LocaleSettings::class, 'timeZone'))->setValue($localeSettings, null);

        $facts = self::getContainer()->get('oro_smart_brief.tests.fact_provider.customer.real')->getFacts(
            $this->getReference(
                LoadCustomerContactData::SCENARIO_PREFIX . LoadCustomerContactData::LATE_EVENING_SCENARIO
            )
        );
        $values = [];
        foreach ($facts as $fact) {
            $values[$fact->key] = $fact->value;
        }

        self::assertSame('2026-10-05', $values['last_contact_date'] ?? null);
        self::assertSame('email', $values['last_contact_channel'] ?? null);
    }
}
