<?php

declare(strict_types = 1);

namespace Firegento\CacheWarmup\Console\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Magento\Framework\UrlInterface;
use Magento\UrlRewrite\Model\ResourceModel\UrlRewriteCollection;
use Magento\UrlRewrite\Model\ResourceModel\UrlRewriteCollectionFactory;
use Magento\UrlRewrite\Model\UrlRewrite;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CacheWarmup extends Command
{
    protected $urls = [];

    protected $curlQueueCount = 0;

    protected $pageTypes = 'all';

    protected $possiblePageTypes = ['product', 'category', 'cms-page'];

    protected $maxConcurrentRequests = 10;

    protected $stopAfter = 0;

    protected $userAgent = 'Firegento/CacheWarmup/0.1.2';

    /**
     * @var UrlInterface
     */
    private $urlInterface;

    /**
     * @var UrlRewriteCollectionFactory
     */
    private $urlRewriteCollectionFactory;

    /**
     * CacheWarmup constructor.
     *
     * @param UrlInterface                $urlInterface
     * @param UrlRewriteCollectionFactory $urlRewriteCollectionFactory
     */
    public function __construct(
        UrlInterface $urlInterface,
        UrlRewriteCollectionFactory $urlRewriteCollectionFactory
    ) {
        parent::__construct();
        $this->urlInterface = $urlInterface;
        $this->urlRewriteCollectionFactory = $urlRewriteCollectionFactory;
    }

    /**
     * @param OutputInterface $output
     *
     * @return bool
     */
    public function isVerbose(OutputInterface $output): bool
    {
        return $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('cache:warmup')
            ->addOption(
                'pageTypes',
                'p',
                InputArgument::OPTIONAL,
                'Comma-separated PageTypes like cms-page,product,category or all -> Default: all'
            )
            ->addArgument(
                'concurrency',
                InputArgument::OPTIONAL,
                'Maximum number of concurrent curl requests -> Default: 10'
            )
            ->addOption(
                'stopAfter',
                's',
                InputArgument::OPTIONAL
            )
            ->addOption(
                'userAgent',
                'u',
                InputArgument::OPTIONAL,
                'Name of User-Agent will be send inside request headers'
            )
            ->setDescription('Fire curl requests to warm up varnish cache');

        parent::configure();
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \Exception
     *
     * @return int|void|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initParams($input);

        $urlCollection = $this->getRewriteCollection();

        if (!$urlCollection) {
            $up = new \Exception('No Rewrite Collection Loaded, may you misspelled the params?');

            throw $up; // haha
        }

        $client = $this->getClient();
        $requests = $this->getRequests($client, $urlCollection);
        $pool = $this->getPool($client, $requests, $output);
        $promise = $pool->promise();
        $promise->wait();
    }

    private function initParams(InputInterface $input)
    {
        $pageTypes = $input->getOption('pageTypes');

        if (mb_strlen($pageTypes) > 0) {
            $this->pageTypes = explode(',', $pageTypes);
        }
        $maxThreads = $input->getArgument('concurrency');

        if (intval($maxThreads) > 0) {
            $this->maxConcurrentRequests = $maxThreads;
        }
        $stopAfter = $input->getOption('stopAfter');

        if (intval($stopAfter) > 0) {
            $this->stopAfter = $stopAfter;
        }

        $userAgent = $input->getOption('userAgent') ?: '';
        if (strlen($userAgent) > 0) {
            $this->userAgent = $userAgent;
        } else {
            $this->userAgent = $this->userAgent . ' php/' . phpversion();
        }
    }

    /**
     * @param Client          $client
     * @param callable        $requests
     * @param OutputInterface $output
     *
     * @return Pool
     */
    private function getPool(Client $client, callable $requests, OutputInterface $output): Pool
    {
        return new Pool($client, $requests(), [
            'concurrency' => $this->maxConcurrentRequests,
            'fulfilled' => function (Response $response, $index) use ($client, $output) {
                $output->writeln('Successful: ' . $index);
            },
            'rejected' => function (RequestException $reason, $index) use ($client, $output) {
                $output->writeln('Rejected: ' . $reason->getRequest()->getUri());
            },
        ]);
    }

    /**
     * @param Client $client
     * @param $urlCollection
     *
     * @return \Closure
     */
    private function getRequests(Client $client, $urlCollection): \Closure
    {
        return function () use ($client, $urlCollection) {
            /** @var UrlRewrite $url */
            foreach ($urlCollection as $url) {
                yield function () use ($client, $url) {
                    return $client->getAsync($url->getRequestPath());
                };
            }
        };
    }

    /**
     * @return Client
     */
    private function getClient(): Client
    {
        $baseUrl = $this->urlInterface->getBaseUrl();

        return new Client([
            'headers' => [
                'User-Agent' => $this->userAgent
            ],
            RequestOptions::ALLOW_REDIRECTS => true,
            'base_uri' => $baseUrl,
        ]);
    }

    /**
     * @return UrlRewriteCollection|null
     */
    private function getRewriteCollection(): ?UrlRewriteCollection
    {
        $rewriteFilter = $this->getRewriteFilter();

        return $rewriteFilter
            ? $this->urlRewriteCollectionFactory->create()->addFieldToFilter('entity_type', $rewriteFilter)
            : null;
    }

    /**
     * @return array|null
     */
    private function getRewriteFilter(): ?array
    {
        if ('all' == $this->pageTypes) {
            return $this->possiblePageTypes;
        }

        if (!is_array($this->pageTypes)) {
            $this->pageTypes = [$this->pageTypes];
        }

        $filter = [];

        foreach ($this->pageTypes as $pageType) {
            if (in_array($pageType, $this->possiblePageTypes)) {
                $filter[] = $pageType;
            }
        }

        return !empty($filter) ? $filter : null;
    }
}
