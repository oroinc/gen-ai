<?php

namespace Oro\Bundle\SmartBriefBundle\Provider;

use Oro\Bundle\CustomerBundle\Entity\Customer;
use Oro\Bundle\EntityConfigBundle\Config\ConfigManager as EntityConfigManager;
use Oro\Bundle\FilterBundle\Form\Type\Filter\NumberFilterTypeInterface;
use Oro\Bundle\FilterBundle\Form\Type\Filter\TextFilterType;
use Oro\Bundle\RFPBundle\Entity\Request;
use Oro\Bundle\SmartBriefBundle\Formatter\FactFormatter;
use Oro\Bundle\SmartBriefBundle\Model\SummaryView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The rows of the "Key facts" list of the block and the links next to them.
 *
 * The list is made of lines ({@see getLines()}). A line is shown when its lead fact is there; its value starts with
 * the lead value and goes on with the values of its details, and it has its own icon and, optionally, a link. A fact
 * that no shown line takes is shown on its own line, under its own label, without a link.
 *
 * To change the list, extend this class, override {@see getLines()}, {@see getHiddenKeys()}, {@see getPageEntity()}
 * or {@see getPageRoute()}, and set the class on the `oro_smart_brief.provider.key_facts` service.
 *
 * @phpstan-import-type FormattedFact from FactFormatter
 *
 * A line: its `lead` fact (required), its `details` facts in display order, its `icon`, and its `link`
 * ({@see createGridLink()}, {@see createSectionLink()} or {@see createRecordLink()}).
 * @phpstan-type Line array{lead: string, details?: string[], icon?: string, link?: array}
 *
 * A row is a line as shown: its key (the line name, or the fact key of a fact on its own), label, value, icon and
 * parts: the values with the text around them, so a template can mark the values only and keep each value on one
 * line.
 * @phpstan-type FactRow array{
 *     key: string,
 *     label: string,
 *     value: string,
 *     icon: string,
 *     parts: list<array{before: string, value: string, after: string}>
 * }
 * @phpstan-type FactLink array{url: string, label: string, grid: ?string, filters: array, section: ?string}
 */
class KeyFactsProvider
{
    protected const string CUSTOMER_PAGE = 'customer';
    protected const string RFQ_PAGE = 'rfq';

    /** Stands for the value in a line text while it is split into the text before and after the value */
    private const string VALUE_MARK = "\u{FFFC}";

    public function __construct(
        private readonly FactFormatter $factFormatter,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly EntityConfigManager $entityConfigManager
    ) {
    }

    /**
     * The rows in the order the provider emitted the facts (a line where its lead fact is), without the hidden
     * facts.
     *
     * @return list<FactRow>
     */
    public function getRows(SummaryView $view): array
    {
        $hiddenKeys = $this->getHiddenKeys();
        $facts = array_column(array_filter(
            $this->factFormatter->format($view->facts, true),
            static fn (array $fact) => !\in_array($fact['key'], $hiddenKeys, true)
        ), null, 'key');

        // the shown lines, by lead fact; a line comes first when it is defined first, and takes its facts
        $linesByLead = [];
        $takenKeys = [];
        foreach ($this->getValidLines() as $name => $line) {
            if (isset($facts[$line['lead']]) && !isset($takenKeys[$line['lead']])) {
                $linesByLead[$line['lead']] = $name;
                foreach ([$line['lead'], ...$line['details'] ?? []] as $key) {
                    $takenKeys[$key] = true;
                }
            }
        }

        $rows = [];
        foreach ($facts as $key => $fact) {
            if (isset($linesByLead[$key])) {
                $rows[] = $this->createLineRow($linesByLead[$key], $facts);
            } elseif (!isset($takenKeys[$key])) {
                $rows[] = $fact + [
                    'icon' => $this->getDefaultIcon($key),
                    'parts' => [['before' => '', 'value' => $fact['value'], 'after' => '']],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param string $lineName the key of a row
     *
     * @return FactLink|null null when the line has no link, nothing behind it (a zero count), or a page the user
     *     cannot view
     */
    public function getLink(SummaryView $view, object $entity, string $lineName): ?array
    {
        $line = $this->getValidLines()[$lineName] ?? null;
        $link = $line['link'] ?? null;
        $value = null !== $line ? $view->facts->get($line['lead'])?->value : null;
        if (null === $link || null === $value || (\is_int($value) && $value <= 0)) {
            return null;
        }

        return empty($link['record'])
            ? $this->getPageLink($view, $entity, $lineName, $line['lead'], $link)
            : $this->getRecordLink($view, $lineName, $line['lead']);
    }

    /**
     * @return string[] the grids the links filter
     */
    public function getLinkedGrids(): array
    {
        $links = array_filter(array_column($this->getValidLines(), 'link'));

        return array_values(array_unique(array_filter(array_column($links, 'grid'))));
    }

    /**
     * The lines of the list, by name. A line's label is `oro_smart_brief.block.line.<line>.label`, the text of each
     * detail is `oro_smart_brief.block.line.<line>.<fact key>` around its value (`%value%`), and the label of its link
     * is `oro_smart_brief.block.link.<line>`. When two lines take the same fact, the line defined first is shown.
     *
     * @return array<string, Line>
     */
    protected function getLines(): array
    {
        return [
            'last_order' => [
                'lead' => 'last_order_date',
                'details' => ['last_order_total'],
                'icon' => 'fa-shopping-cart',
                'link' => $this->createGridLink(self::CUSTOMER_PAGE, 'customer-orders-grid', 'identifier'),
            ],
            'open_rfq' => [
                'lead' => 'open_rfq_count',
                'details' => ['oldest_open_rfq_date'],
                'icon' => 'fa-file-text-o',
                'link' => $this->createGridLink(
                    self::CUSTOMER_PAGE,
                    'customer-view-rfq-grid',
                    'id',
                    NumberFilterTypeInterface::TYPE_IN
                ),
            ],
            'awaiting_quote' => [
                'lead' => 'awaiting_quote_count',
                'details' => ['nearest_quote_expiry_date'],
                'icon' => 'fa-hourglass-half',
                'link' => $this->createGridLink(self::CUSTOMER_PAGE, 'customer-view-quote-grid', 'qid'),
            ],
            'last_contact' => [
                'lead' => 'last_contact_date',
                'details' => ['last_contact_channel'],
                'icon' => 'fa-comments-o',
                'link' => $this->createRecordLink(),
            ],
            'active_quotes' => [
                'lead' => 'active_quote_count',
                'icon' => 'fa-file-o',
                'link' => $this->createGridLink(self::CUSTOMER_PAGE, 'customer-view-quote-grid', 'qid'),
            ],
            'quote_for_this_rfq' => [
                'lead' => 'quote_for_this_rfq',
                'details' => ['quote_for_this_rfq_status'],
                'icon' => 'fa-link',
                'link' => $this->createGridLink(self::RFQ_PAGE, 'request-view-quotes-grid', 'qid'),
            ],
            'previously_purchased' => [
                'lead' => 'previously_purchased_product_count',
                'details' => ['requested_product_count', 'last_purchase_date'],
                'icon' => 'fa-history',
                'link' => $this->createGridLink(self::CUSTOMER_PAGE, 'customer-orders-grid', 'identifier'),
            ],
            // shown only when the line above is not, that is without the purchase facts
            'requested_products' => [
                'lead' => 'requested_product_count',
                'icon' => 'fa-cubes',
                'link' => $this->createSectionLink(self::RFQ_PAGE, '.rfp-line-items-info'),
            ],
        ];
    }

    /**
     * Facts never shown: they say what the record is rather than its state (the page title already shows them), or
     * only help the AI summarize the record (the RFQ price ranges).
     *
     * @return string[]
     */
    protected function getHiddenKeys(): array
    {
        return ['company_name', 'list_gap_percent_range', 'last_paid_gap_percent_range'];
    }

    /**
     * The record of a page: the summarized record itself, or the RFQ's customer for the customer page.
     */
    protected function getPageEntity(string $page, object $entity): ?object
    {
        return match ($page) {
            self::CUSTOMER_PAGE => match (true) {
                $entity instanceof Customer => $entity,
                $entity instanceof Request => $entity->getCustomer(),
                default => null,
            },
            self::RFQ_PAGE => $entity instanceof Request ? $entity : null,
            default => null,
        };
    }

    protected function getPageRoute(string $page): ?string
    {
        return match ($page) {
            self::CUSTOMER_PAGE => 'oro_customer_customer_view',
            self::RFQ_PAGE => 'oro_rfp_request_view',
            default => null,
        };
    }

    /**
     * A link to a grid of a page, filtered to the records of the line's lead fact
     * ({@see \Oro\Bundle\SmartBriefBundle\Model\FactSet::setRecords()}); without records it shows the whole grid.
     */
    protected function createGridLink(
        string $page,
        string $grid,
        string $filter,
        int $filterType = TextFilterType::TYPE_IN
    ): array {
        return ['page' => $page, 'grid' => $grid, 'filter' => $filter, 'filter_type' => $filterType];
    }

    /**
     * A link to a section of a page (CSS selector), which the page scrolls to.
     */
    protected function createSectionLink(string $page, string $section): array
    {
        return ['page' => $page, 'section' => $section];
    }

    /**
     * A link to the view page of the record whose class and id are the records of the line's lead fact.
     */
    protected function createRecordLink(): array
    {
        return ['record' => true];
    }

    /**
     * @return array<string, Line>
     *
     * @throws \LogicException when a line has no lead fact, or a page link no page
     */
    private function getValidLines(): array
    {
        $lines = $this->getLines();
        foreach ($lines as $name => $line) {
            if (empty($line['lead'])) {
                throw new \LogicException(sprintf('The key facts line "%s" has no lead fact.', $name));
            }
            if (isset($line['link']) && empty($line['link']['record']) && empty($line['link']['page'])) {
                throw new \LogicException(sprintf('The link of the key facts line "%s" has no page.', $name));
            }
        }

        return $lines;
    }

    private function getDefaultIcon(string $key): string
    {
        return match (true) {
            str_ends_with($key, '_date') => 'fa-calendar',
            str_ends_with($key, '_count') => 'fa-hashtag',
            str_ends_with($key, '_total') => 'fa-money',
            default => 'fa-info-circle',
        };
    }

    /**
     * A grid or a section of a page.
     *
     * @return FactLink|null
     */
    private function getPageLink(SummaryView $view, object $entity, string $lineName, string $lead, array $link): ?array
    {
        $pageEntity = $this->getPageEntity($link['page'], $entity);
        $route = $this->getPageRoute($link['page']);
        if (null === $pageEntity || null === $route || !$this->authorizationChecker->isGranted('VIEW', $pageEntity)) {
            return null;
        }

        $grid = $link['grid'] ?? null;
        $records = $view->facts->getRecords($lead);
        $filters = null !== $grid && $records
            ? [$link['filter'] => ['value' => implode(',', $records), 'type' => (string)$link['filter_type']]]
            : [];
        $parameters = ['id' => $pageEntity->getId()];
        if ($filters) {
            // the grid state the page reads from its URL
            $parameters['grid'] = [$grid => http_build_query(['f' => $filters])];
        }

        return $this->createLinkView($lineName, $route, $parameters, $grid, $filters, $link['section'] ?? null);
    }

    /**
     * The view page of the record whose class and id are the records of the lead fact.
     *
     * @return FactLink|null
     */
    private function getRecordLink(SummaryView $view, string $lineName, string $lead): ?array
    {
        $records = $view->facts->getRecords($lead) ?? [];
        if (2 !== \count($records)) {
            return null;
        }
        [$class, $id] = $records;
        try {
            $route = $this->entityConfigManager->getEntityMetadata($class)?->getRoute('view', true);
        } catch (\LogicException) {
            // the class has no view page
            return null;
        }

        return null === $route ? null : $this->createLinkView($lineName, $route, ['id' => $id], null, [], null);
    }

    /**
     * @return FactLink
     */
    private function createLinkView(
        string $lineName,
        string $route,
        array $parameters,
        ?string $grid,
        array $filters,
        ?string $section
    ): array {
        return [
            'url' => $this->urlGenerator->generate($route, $parameters),
            'label' => $this->translator->trans(sprintf('oro_smart_brief.block.link.%s', $lineName)),
            'grid' => $grid,
            'filters' => $filters,
            'section' => null === $grid ? $section : null,
        ];
    }

    /**
     * @param array<string, FormattedFact> $facts
     *
     * @return FactRow
     */
    private function createLineRow(string $name, array $facts): array
    {
        $line = $this->getLines()[$name];
        $parts = [['before' => '', 'value' => $facts[$line['lead']]['value'], 'after' => '']];
        foreach ($line['details'] ?? [] as $key) {
            if (isset($facts[$key])) {
                // the text of the detail around its value, such as ", oldest from %value%"
                [$before, $after] = explode(self::VALUE_MARK, $this->translator->trans(
                    sprintf('oro_smart_brief.block.line.%s.%s', $name, $key),
                    ['%value%' => self::VALUE_MARK]
                ), 2) + [1 => ''];
                $parts[] = ['before' => $before, 'value' => $facts[$key]['value'], 'after' => $after];
            }
        }

        return [
            'key' => $name,
            'label' => $this->translator->trans(sprintf('oro_smart_brief.block.line.%s.label', $name)),
            'value' => implode('', array_map(static fn (array $part) => implode('', $part), $parts)),
            'icon' => $line['icon'] ?? $this->getDefaultIcon($line['lead']),
            'parts' => $parts,
        ];
    }
}
