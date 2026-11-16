<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Client;

use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientInterface;
use Oro\Bundle\SmartBriefBundle\Client\SummaryRequest;
use Oro\Bundle\SmartBriefBundle\Client\SummaryResponse;
use Oro\Bundle\SmartBriefBundle\Factory\SummaryClientFactoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Pins the client contract and checks it stays provider- and feature-neutral.
 */
final class ContractNeutralityTest extends TestCase
{
    private const array CONTRACT_TYPES = [
        SummaryClientInterface::class,
        SummaryRequest::class,
        SummaryResponse::class,
        SummaryClientException::class,
        SummaryClientFactoryInterface::class,
    ];

    /** The only imports a contract type may have: the contract itself and the settings-bag type. */
    private const array ALLOWED_IMPORT_PATTERNS = [
        '/^Oro\\\\Bundle\\\\SmartBriefBundle\\\\Client\\\\Summary[A-Za-z]+$/',
        '/^Symfony\\\\Component\\\\HttpFoundation\\\\ParameterBag$/',
    ];

    /** Substrings no contract file may contain (subject entities, facts, AI Content Generation classes). */
    private const array FORBIDDEN_SOURCE_PATTERNS = [
        '/CustomerBundle|RFPBundle|SaleBundle/',
        '/Fact(Set|Provider|Formatter|Value|Registry)/',
        '/AiContentGenerationBundle\\\\(Client|Request|Task|Context|Form)\\\\/',
        '/AiContentGenerationBundle\\\\Factory\\\\ContentGeneration/',
    ];

    public function testClientInterfaceSignatures(): void
    {
        $reflection = new \ReflectionClass(SummaryClientInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame(
            ['generateSummary', 'getProviderType', 'checkConnection'],
            array_map(static fn (\ReflectionMethod $method) => $method->getName(), $reflection->getMethods())
        );

        $generate = $reflection->getMethod('generateSummary');
        self::assertSame(SummaryRequest::class, (string)$generate->getParameters()[0]->getType());
        self::assertSame(SummaryResponse::class, (string)$generate->getReturnType());
        self::assertSame('string', (string)$reflection->getMethod('getProviderType')->getReturnType());
        self::assertSame('void', (string)$reflection->getMethod('checkConnection')->getReturnType());
    }

    public function testFactoryInterfaceSignatures(): void
    {
        $reflection = new \ReflectionClass(SummaryClientFactoryInterface::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame(
            ['supports', 'build'],
            array_map(static fn (\ReflectionMethod $method) => $method->getName(), $reflection->getMethods())
        );

        $supports = $reflection->getMethod('supports');
        self::assertSame('string', (string)$supports->getParameters()[0]->getType());
        self::assertSame('bool', (string)$supports->getReturnType());

        $build = $reflection->getMethod('build');
        self::assertSame(ParameterBag::class, (string)$build->getParameters()[0]->getType());
        self::assertSame(SummaryClientInterface::class, (string)$build->getReturnType());
    }

    public function testExceptionConstructorSignature(): void
    {
        $constructor = (new \ReflectionClass(SummaryClientException::class))->getConstructor();

        self::assertSame(
            [
                ['message', 'string', false],
                ['reason', 'string', true],
                ['httpStatus', '?int', true],
                ['providerErrorCode', '?string', true],
                ['previous', '?Throwable', true],
            ],
            array_map(
                static fn (\ReflectionParameter $parameter) => [
                    $parameter->getName(),
                    (string)$parameter->getType(),
                    $parameter->isDefaultValueAvailable(),
                ],
                $constructor->getParameters()
            )
        );
        self::assertSame(
            SummaryClientException::REASON_CLIENT_ERROR,
            $constructor->getParameters()[1]->getDefaultValue()
        );
    }

    /**
     * @dataProvider contractTypesDataProvider
     */
    public function testContractTypeImportsOnlyContractAndSettingsBagTypes(string $class): void
    {
        foreach ($this->getImports($class) as $import) {
            $allowed = false;
            foreach (self::ALLOWED_IMPORT_PATTERNS as $pattern) {
                $allowed = $allowed || preg_match($pattern, $import) === 1;
            }

            self::assertTrue($allowed, sprintf('%s must not import %s.', $class, $import));
        }
    }

    /**
     * @dataProvider contractTypesDataProvider
     */
    public function testContractTypeReferencesNoSubjectFactOrForbiddenClass(string $class): void
    {
        $source = $this->getSource($class);

        foreach (self::FORBIDDEN_SOURCE_PATTERNS as $pattern) {
            self::assertDoesNotMatchRegularExpression(
                $pattern,
                $source,
                sprintf('%s must not reference a subject entity, fact type or forbidden class.', $class)
            );
        }
    }

    public static function contractTypesDataProvider(): array
    {
        return array_map(static fn (string $class) => [$class], self::CONTRACT_TYPES);
    }

    /**
     * @return string[]
     */
    private function getImports(string $class): array
    {
        preg_match_all('/^use\s+([^;\s]+)/m', $this->getSource($class), $matches);

        return $matches[1];
    }

    private function getSource(string $class): string
    {
        return file_get_contents((new \ReflectionClass($class))->getFileName());
    }
}
