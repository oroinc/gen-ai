<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\ValueType;

use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\ChoiceValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\EnumOptionValueType;
use Oro\Bundle\SmartBriefBundle\ValueType\FactValueTypeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Covers the enum_option, unit and choice value types: each resolves
 * a translation key in the user's language and keeps the raw string as its canonical form.
 */
final class TranslatedValueTypesTest extends TestCase
{
    /**
     * @dataProvider valueTypeDataProvider
     */
    public function testFormatTranslatesInTheUsersLanguage(
        string $class,
        string $name,
        string $key,
        string $value,
        string $expectedTranslationKey
    ): void {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with($expectedTranslationKey)
            ->willReturn('übersetzt');
        /** @var FactValueTypeInterface $valueType */
        $valueType = new $class($translator);

        self::assertSame($name, $valueType->getName());
        self::assertSame(
            'übersetzt',
            $valueType->format(new Fact($key, $name, $value), true)
        );
        self::assertSame($value, $valueType->canonicalize($value));
        self::assertSame($value, $valueType->restore($valueType->canonicalize($value)));
    }

    public static function valueTypeDataProvider(): array
    {
        return [
            'enum_option' => [
                EnumOptionValueType::class,
                FactValueType::ENUM_OPTION,
                'quote_for_this_rfq_status',
                'quote_internal_status.sent_to_customer',
                ExtendHelper::buildEnumOptionTranslationKey('quote_internal_status.sent_to_customer'),
            ],
            'choice' => [
                ChoiceValueType::class,
                FactValueType::CHOICE,
                'last_contact_channel',
                'email',
                'oro_smart_brief.fact.last_contact_channel.choice.email',
            ],
        ];
    }
}
