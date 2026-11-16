<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Production code and configuration of the bundle reference only the allowed
 * AI Content Generation classes and services.
 */
final class ForbiddenReferencesTest extends TestCase
{
    private const array ALLOWED_CLASSES = [
        'Oro\Bundle\AiContentGenerationBundle\Integration\OpenAiChannel',
        'Oro\Bundle\AiContentGenerationBundle\Integration\VertexAiChannel',
        'Oro\Bundle\AiContentGenerationBundle\Integration\OpenAiTransport',
        'Oro\Bundle\AiContentGenerationBundle\Integration\VertexAiTransport',
        'Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings',
        'Oro\Bundle\AiContentGenerationBundle\Entity\VertexAiTransportSettings',
        'Oro\Bundle\AiContentGenerationBundle\Factory\OpenAiSdkClientFactory',
    ];

    private const string CONTENT_GENERATION_CLASS_PATTERN =
        '/Oro\\\\{1,2}Bundle\\\\{1,2}AiContentGenerationBundle(?:\\\\{1,2}\w+)+/';

    private const array ALLOWED_SERVICE_IDS = [
        'oro_ai_content_generation.factory.open_ai_sdk_factory',
    ];

    public function testProductionFilesReferenceOnlyAllowedContentGenerationClasses(): void
    {
        $checked = 0;
        foreach ($this->getProductionFiles() as $path => $source) {
            $checked++;
            preg_match_all(self::CONTENT_GENERATION_CLASS_PATTERN, $source, $matches);
            foreach ($matches[0] as $reference) {
                self::assertContains(
                    str_replace('\\\\', '\\', $reference),
                    self::ALLOWED_CLASSES,
                    sprintf('%s references %s, which is not allowed.', $path, $reference)
                );
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    public function testConfigurationReferencesOnlyAllowedContentGenerationServices(): void
    {
        foreach ($this->getProductionFiles() as $path => $source) {
            if (!str_ends_with($path, '.yml')) {
                continue;
            }
            preg_match_all('/oro_ai_content_generation\.[\w.]+/', $source, $matches);
            foreach ($matches[0] as $serviceId) {
                self::assertContains(
                    $serviceId,
                    self::ALLOWED_SERVICE_IDS,
                    sprintf('%s references service %s, which is not allowed.', $path, $serviceId)
                );
            }
        }
    }

    /**
     * @return iterable<string, string> relative path => source with PHP comments removed
     */
    private function getProductionFiles(): iterable
    {
        $finder = (new Finder())
            ->files()
            ->in(\dirname(__DIR__, 2))
            ->exclude('Tests')
            ->name(['*.php', '*.yml']);

        foreach ($finder as $file) {
            yield $file->getRelativePathname() => $this->stripComments($file->getContents(), $file->getExtension());
        }
    }

    /**
     * Comments may name the copied-from class; only code and configuration are checked.
     */
    private function stripComments(string $source, string $extension): string
    {
        if ('php' !== $extension) {
            return $source;
        }

        $code = '';
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                $code .= $token->text;
            }
        }

        return $code;
    }
}
