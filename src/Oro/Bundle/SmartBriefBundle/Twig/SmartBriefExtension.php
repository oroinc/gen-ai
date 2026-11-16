<?php

namespace Oro\Bundle\SmartBriefBundle\Twig;

use Oro\Bundle\SmartBriefBundle\Model\SummaryView;
use Oro\Bundle\SmartBriefBundle\Provider\KeyFactsProvider;
use Oro\Bundle\SmartBriefBundle\Serializer\FactSetSerializer;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig functions of SmartBrief:
 *   - oro_smart_brief_facts — the rows of the "Key facts" list ({@see KeyFactsProvider::getRows()}): its lines of
 *     related facts and the facts on their own, formatted for display by the same formatter that builds the prompt;
 *   - oro_smart_brief_link — the link of a line ({@see KeyFactsProvider::getLink()}): the grid it filters to the
 *     records behind its lead fact, the section it scrolls to, or the record it opens;
 *   - oro_smart_brief_fingerprint — the hash of a summary view's facts, which changes when the facts change;
 *     the block keeps the user's expanded or collapsed choice for a record until it does;
 *   - oro_smart_brief_age — how long ago a summary was written ("Updated 5 min ago"), shown in the block header;
 *   - oro_smart_brief_text (filter) — the AI paragraph as HTML: escaped first, then the `**value**` bold
 *     the prompt asks for becomes `<strong>`; nothing else of the AI text becomes markup.
 *
 * @phpstan-import-type FactRow from KeyFactsProvider
 * @phpstan-import-type FactLink from KeyFactsProvider
 */
class SmartBriefExtension extends AbstractExtension implements ServiceSubscriberInterface
{
    private const string BOLD_PATTERN = '/\*\*(.+?)\*\*/su';
    private const int MINUTE = 60;
    private const int HOUR = 3600;
    private const int DAY = 86400;

    public function __construct(
        private readonly ContainerInterface $container
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('oro_smart_brief_facts', $this->getFacts(...)),
            new TwigFunction('oro_smart_brief_fingerprint', $this->getFingerprint(...)),
            new TwigFunction('oro_smart_brief_link', $this->getLink(...)),
            new TwigFunction('oro_smart_brief_age', $this->getAge(...)),
        ];
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('oro_smart_brief_text', $this->formatText(...), ['is_safe' => ['html']]),
        ];
    }

    public function formatText(string $text): string
    {
        $escaped = htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return (string)preg_replace(self::BOLD_PATTERN, '<strong>$1</strong>', $escaped);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return [
            'oro_smart_brief.provider.key_facts' => KeyFactsProvider::class,
            FactSetSerializer::class,
            TranslatorInterface::class,
        ];
    }

    public function getAge(\DateTimeInterface $generatedAt): string
    {
        $seconds = max(0, time() - $generatedAt->getTimestamp());
        $translator = $this->container->get(TranslatorInterface::class);

        return match (true) {
            $seconds < self::MINUTE => $translator->trans('oro_smart_brief.block.updated.just_now'),
            $seconds < self::HOUR => $translator->trans(
                'oro_smart_brief.block.updated.minutes_ago',
                ['%count%' => intdiv($seconds, self::MINUTE)]
            ),
            $seconds < self::DAY => $translator->trans(
                'oro_smart_brief.block.updated.hours_ago',
                ['%count%' => intdiv($seconds, self::HOUR)]
            ),
            default => $translator->trans(
                'oro_smart_brief.block.updated.days_ago',
                ['%count%' => intdiv($seconds, self::DAY)]
            ),
        };
    }

    /**
     * @return list<FactRow>
     */
    public function getFacts(SummaryView $view): array
    {
        return $this->container->get('oro_smart_brief.provider.key_facts')->getRows($view);
    }

    public function getFingerprint(SummaryView $view): string
    {
        return $this->container->get(FactSetSerializer::class)->hash($view->facts);
    }

    /**
     * @return FactLink|null
     */
    public function getLink(SummaryView $view, object $entity, string $lineName): ?array
    {
        return $this->container->get('oro_smart_brief.provider.key_facts')->getLink($view, $entity, $lineName);
    }
}
