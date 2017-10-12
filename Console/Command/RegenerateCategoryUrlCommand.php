<?php
namespace Iazel\RegenProductUrl\Console\Command;

use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Store\Model\Store;
use Magento\Framework\App\State;

class RegenerateCategoryUrlCommand extends Command
{
    /**
     * @var CategoryUrlRewriteGenerator
     */
    protected $categoryUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var Collection
     */
    protected $collection;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    public function __construct(
        State $state,
        StoreManagerInterface $storeManager,
        Collection $collection,
        CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        UrlPersistInterface $urlPersist
    ) {
        $this->state                       = $state;
        $this->storeManager                = $storeManager;
        $this->collection                  = $collection;
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->urlPersist                  = $urlPersist;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('iazel:regencategoryurl')
            ->setDescription('Regenerate url for given categories')
            ->addArgument(
                'cids',
                InputArgument::IS_ARRAY,
                'Categories to regenerate'
            )
            ->addOption(
                'store', 's',
                InputOption::VALUE_REQUIRED,
                'Use the specific Store View',
                Store::DEFAULT_STORE_ID
            )
        ;
        return parent::configure();
    }

    public function execute(InputInterface $inp, OutputInterface $out)
    {
        try {
            // this tosses an error if the areacode is not set.
            $this->state->getAreaCode();
        } catch (\Exception $e) {
            $this->state->setAreaCode('adminhtml');
        }

        $storeId = $inp->getOption('store');
        $this->collection->setStoreId($storeId);

        /** @var Store $currentStore */
        $currentStore   = $this->storeManager->getStore();
        $rootCategoryId = $currentStore->getRootCategoryId();

        $cids = $inp->getArgument('cids');
        if (!empty($cids)) {
            $this->collection->addIdFilter($cids);
        } else {
            $this->collection->addFilter('parent_id', $rootCategoryId);
        }

        $this->collection->addAttributeToSelect(['url_path', 'url_key']);

        foreach ($this->collection as $category) {
            if (!$category->getData('url_key') || !$category->getData('url_path')) {
                continue;
            }

            $category->setStoreId($storeId);

            $this->urlPersist->deleteByData([
                UrlRewrite::ENTITY_ID     => $category->getId(),
                UrlRewrite::ENTITY_TYPE   => CategoryUrlRewriteGenerator::ENTITY_TYPE,
                UrlRewrite::REDIRECT_TYPE => 0,
                UrlRewrite::STORE_ID      => $storeId
            ]);

            try {
                $this->urlPersist->replace($this->filterEmptyUrlKeys($category));
            } catch (\Exception $e) {
                $out->writeln('<error>Duplicated url for ' . $category->getId() . '</error>');
            }
        }
    }

    /**
     * @param $category
     * @return UrlRewrite[]
     */
    private function filterEmptyUrlKeys($category)
    {
        $urls = $this->categoryUrlRewriteGenerator->generate($category);

        foreach ($urls as $k => $url) {
            if (strpos($k, '//') !== false || strpos($k, '/_') !== false) {
                unset($urls[$k]);
            }
        }

        return $urls;
    }
}
