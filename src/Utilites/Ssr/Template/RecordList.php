<?php

declare(strict_types=1);

namespace Omikron\FactFinder\Shopware6\Utilites\Ssr\Template;

use Omikron\FactFinder\Shopware6\Utilites\Ssr\Exception\DetectRedirectCampaignException;
use Omikron\FactFinder\Shopware6\Utilites\Ssr\SearchAdapter;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinderInterface;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

class RecordList
{
    private const RECORD_PATTERN     = '#<ff-record[\s>].*?</ff-record>#s';
    private const SSR_RECORD_PATTERN = '#<ssr-record-template>.*?</ssr-record-template>#s';
    private const SSR_PAGING_PATTERN = '#<ssr-paging>.*?</ssr-paging>#s';

    private Request $request;
    private Environment $twig;
    private TemplateFinderInterface $templateFinder;
    private Engine $mustache;
    private SearchAdapter $searchAdapter;
    private string $salesChannelId;
    private string $content;
    private string $template;
    private string $pageUrlParam;

    public function __construct(
        Request $request,
        Environment $twig,
        TemplateFinderInterface $templateFinder,
        Engine $mustache,
        SearchAdapter $searchAdapter,
        string $salesChannelId,
        string $content,
        string $pageUrlParam = 'page'
    ) {
        $this->request        = $request;
        $this->twig           = $twig;
        $this->templateFinder = $templateFinder;
        $this->mustache       = $mustache;
        $this->searchAdapter  = $searchAdapter;
        $this->salesChannelId = $salesChannelId;
        $this->content        = $content;
        $this->pageUrlParam   = $pageUrlParam ?? 'page';
        $this->setTemplateString();
    }

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @throws DetectRedirectCampaignException
     */
    public function getContent(
        string $paramString,
        bool $isNavigationRequest = false
    ): string {
        $results = $this->searchResults($paramString, $isNavigationRequest);

        return $this->renderResults($results, $paramString);
    }

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @throws DetectRedirectCampaignException
     */
    public function searchResults(
        string $paramString,
        bool $isNavigationRequest = false
    ): array {
        if ($this->pageUrlParam !== 'page') {
            $paramString = strpos($paramString, $this->pageUrlParam . '=') === 0
                ? sprintf('page=%s', substr($paramString, strlen($this->pageUrlParam) + 1))
                : str_replace('&' . $this->pageUrlParam . '=', '&page=', $paramString);
        }


        $results = $this->searchAdapter->search($paramString, $isNavigationRequest, $this->salesChannelId);

        // Support redirect campaigns for SSR
        if ($this->getRedirectCampaign($results)) {
            throw new DetectRedirectCampaignException($this->getRedirectCampaign($results));
        }

        return $results;
    }

    public function renderResults(
        array $results,
        string $paramString
    ): string {
        $records        = $results['records'] ?? [];
        $recordsContent = array_reduce(
            $records,
            fn (string $carry, array $record) => sprintf(
                '%s%s',
                $carry,
                $this->mustache->render($this->template, $record)
            ),
            ''
        );

        $this->content = str_replace('{FF_SEARCH_RESULT}', json_encode($results) ?: '{}', $this->content);
        $this->setContentWithLinks($results);
        $this->setPaging($results);

        if ($records === []) {
            return preg_replace(self::SSR_RECORD_PATTERN, '', $this->content);
        }

        return preg_replace(self::SSR_RECORD_PATTERN, $recordsContent, $this->content);
    }

    private function setContentWithLinks(array $results): void
    {
        $nextPage     = (int) ($results['paging']['nextLink']['number'] ?? 0);
        $previousPage = (int) ($results['paging']['previousLink']['number'] ?? 0);
        $nextLink     = '';
        $previousLink = '';

        if ($previousPage !== 0) {
            $previousLink = sprintf('<link rel="prev" href="%s" />', $this->paginationUrl($previousPage));
        }

        if ($nextPage !== 0) {
            $nextLink = sprintf('<link rel="next" href="%s" />', $this->paginationUrl($nextPage));
        }

        $this->content = str_replace('</head>', sprintf('%s%s</head>', $previousLink, $nextLink), $this->content);
    }

    private function setPaging(array $results): void
    {
        $currentPage = (int) ($results['paging']['currentPage'] ?? 1);
        $pageCount   = (int) ($results['paging']['pageCount'] ?? 1);

        $twigVars = [
            'currentPage' => $currentPage,
            'pageCount'   => $pageCount,
        ];
        for ($page = 1; $page <= $pageCount; $page++) {
            $twigVars['pages'][$page] = $this->paginationUrl($page);
        }

        $template = $this->templateFinder->find('@Storefront/storefront/components/factfinder/ssr-paging.html.twig');
        $paging   = $this->twig->render($template, $twigVars);

        $this->content = preg_replace(self::SSR_PAGING_PATTERN, $paging, $this->content);
    }

    private function setTemplateString(): void
    {
        preg_match(self::RECORD_PATTERN, $this->content, $match);

        $this->template = $match[0] ?? '';
    }

    private function getRedirectCampaign(array $result): ?string
    {
        if (!empty($result['campaigns'])) {
            $campaign = array_search('REDIRECT', array_column($result['campaigns'], 'flavour'));

            return $result['campaigns'][$campaign]['target']['destination'] ?? null;
        }

        return null;
    }

    private function paginationUrl(int $page): string
    {
        $requestUrl = $this->request->attributes->has(RequestTransformer::ORIGINAL_REQUEST_URI)
            ? $this->request->getUriForPath($this->request->attributes->get(RequestTransformer::ORIGINAL_REQUEST_URI))
            : $this->request->getUri();
        $baseUrl = strtok($requestUrl, '?');

        $params = $this->request->query->all();
        unset($params[$this->pageUrlParam]);

        $queryString = http_build_query($page === 1 ? $params : array_merge($params, [$this->pageUrlParam => $page]));

        return $baseUrl . ($queryString === '' ? '' : '?' . $queryString);
    }
}
